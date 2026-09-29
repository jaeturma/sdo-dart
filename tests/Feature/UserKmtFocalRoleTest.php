<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 'kmt_focal' is the first genuinely multi-role case this app supports — a
 * secondary role layered on top of a user's one primary role (originator/
 * unit_head/division_chief/etc.), toggled via an independent checkbox in the
 * Users admin form rather than the single-select "Role" dropdown. These
 * tests cover UserController::store()/update()'s role-syncing (via
 * rolesToSync()) and the primary-role projections in index()/edit(), which
 * must exclude 'kmt_focal' so it's never mistaken for the primary role.
 */
class UserKmtFocalRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'originator', 'unit_head', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create([
            'two_factor_secret'       => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->admin->assignRole('admin');

        $this->division = Division::create(['name' => 'Test Division', 'code' => 'TD']);
    }

    public function test_creating_a_user_with_kmt_focal_checked_assigns_both_roles(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name'                  => 'New Staffer',
            'email'                 => 'staffer@example.com',
            'password'              => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
            'role'                  => 'originator',
            'kmt_focal'             => true,
            'division_id'           => $this->division->id,
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'staffer@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('originator'));
        $this->assertTrue($user->hasRole('kmt_focal'));
    }

    public function test_creating_a_user_without_kmt_focal_checked_only_assigns_the_primary_role(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name'                  => 'Plain Staffer',
            'email'                 => 'plainstaffer@example.com',
            'password'              => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
            'role'                  => 'originator',
            'division_id'           => $this->division->id,
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'plainstaffer@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('originator'));
        $this->assertFalse($user->hasRole('kmt_focal'));
    }

    public function test_toggling_kmt_focal_on_via_update_preserves_the_primary_role(): void
    {
        $user = User::factory()->create(['division_id' => $this->division->id]);
        $user->assignRole('originator');

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => 'originator',
            'kmt_focal'   => true,
            'division_id' => $this->division->id,
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertTrue($user->hasRole('originator'));
        $this->assertTrue($user->hasRole('kmt_focal'));
    }

    public function test_toggling_kmt_focal_off_via_update_removes_only_that_role(): void
    {
        $user = User::factory()->create(['division_id' => $this->division->id]);
        $user->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => 'originator',
            'division_id' => $this->division->id,
            // 'kmt_focal' omitted entirely — unchecked checkboxes don't submit
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertTrue($user->hasRole('originator'));
        $this->assertFalse($user->hasRole('kmt_focal'));
    }

    public function test_changing_primary_role_via_update_preserves_kmt_focal(): void
    {
        $user = User::factory()->create(['division_id' => $this->division->id]);
        $user->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => 'unit_head',
            'kmt_focal'   => true,
            'division_id' => $this->division->id,
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertTrue($user->hasRole('unit_head'));
        $this->assertFalse($user->hasRole('originator'));
        $this->assertTrue($user->hasRole('kmt_focal'));
    }

    public function test_index_and_edit_report_the_primary_role_excluding_kmt_focal(): void
    {
        $user = User::factory()->create(['division_id' => $this->division->id]);
        $user->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertInertia(fn ($page) => $page
                ->where('users.data', function ($rows) use ($user) {
                    $row = collect($rows)->firstWhere('id', $user->id);

                    return $row['role'] === 'originator' && $row['kmtFocal'] === true;
                }));

        $this->actingAs($this->admin)
            ->get(route('users.edit', $user))
            ->assertInertia(fn ($page) => $page
                ->where('user.role', 'originator')
                ->where('user.kmt_focal', true));
    }
}
