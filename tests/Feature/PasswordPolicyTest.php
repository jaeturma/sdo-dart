<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\PasswordPolicySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([RestrictWeekendAccess::class]);

        foreach (['admin', 'originator'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
        $this->admin->assignRole('admin');
    }

    private function confirmedUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ], $attributes));
        $user->assignRole('originator');

        return $user;
    }

    public function test_a_user_flagged_to_change_password_is_redirected_from_dashboard(): void
    {
        $user = $this->confirmedUser(['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.change'));
    }

    public function test_user_can_complete_the_forced_password_change(): void
    {
        $user = $this->confirmedUser(['must_change_password' => true, 'password' => 'OldPassw0rd!']);

        $response = $this->actingAs($user)->put(route('password.change.update'), [
            'current_password' => 'OldPassw0rd!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_weak_new_password_is_rejected(): void
    {
        $user = $this->confirmedUser(['must_change_password' => true, 'password' => 'OldPassw0rd!']);

        $response = $this->actingAs($user)->put(route('password.change.update'), [
            'current_password' => 'OldPassw0rd!',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertTrue($user->must_change_password);
    }

    public function test_admin_can_force_password_reset_for_all_users(): void
    {
        $user = $this->confirmedUser(['must_change_password' => false, 'password_changed_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->post(route('password-policy.force-reset-all'))
            ->assertRedirect();

        $this->assertNotNull(PasswordPolicySetting::forceResetAt());

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.change'));
    }

    public function test_changing_password_after_a_forced_reset_clears_the_requirement(): void
    {
        $user = $this->confirmedUser(['password' => 'OldPassw0rd!', 'password_changed_at' => now()->subDay()]);

        PasswordPolicySetting::forceResetAll();

        $this->actingAs($user)->put(route('password.change.update'), [
            'current_password' => 'OldPassw0rd!',
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ])->assertRedirect(route('dashboard'));

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_non_admin_cannot_force_password_reset(): void
    {
        $user = $this->confirmedUser();

        $this->actingAs($user)
            ->post(route('password-policy.force-reset-all'))
            ->assertForbidden();

        $this->assertNull(PasswordPolicySetting::forceResetAt());
    }

    public function test_admin_creating_a_user_with_a_weak_password_is_rejected(): void
    {
        $division = Division::create(['name' => 'Test Division', 'code' => 'TD']);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'New Guy',
            'email' => 'newguy@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
            'role' => 'originator',
            'division_id' => $division->id,
        ])->assertSessionHasErrors('password');
    }

    public function test_admin_created_user_must_change_password_on_first_login(): void
    {
        $division = Division::create(['name' => 'Test Division', 'code' => 'TD']);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'New Guy',
            'email' => 'newguy@example.com',
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
            'role' => 'originator',
            'division_id' => $division->id,
        ])->assertRedirect(route('users.index'));

        $newUser = User::where('email', 'newguy@example.com')->firstOrFail();
        $this->assertTrue($newUser->must_change_password);
    }

    public function test_admin_can_reset_a_users_password_to_a_random_temporary_password(): void
    {
        $user = $this->confirmedUser(['must_change_password' => false, 'password' => 'SomeOldPass1!']);

        $this->actingAs($this->admin)
            ->post(route('users.reset-password', $user))
            ->assertRedirect(route('users.index'));

        $temporaryPassword = session('temporary_password')['password'] ?? null;
        $this->assertNotNull($temporaryPassword);

        $user->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertTrue($user->must_change_password);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.change'));
    }

    public function test_reset_password_generates_a_different_password_each_time(): void
    {
        $user = $this->confirmedUser(['must_change_password' => false, 'password' => 'SomeOldPass1!']);

        $this->actingAs($this->admin)->post(route('users.reset-password', $user));
        $first = session('temporary_password')['password'];

        $this->actingAs($this->admin)->post(route('users.reset-password', $user));
        $second = session('temporary_password')['password'];

        $this->assertNotSame($first, $second);
    }

    public function test_non_admin_cannot_reset_another_users_password(): void
    {
        $user = $this->confirmedUser();
        $other = $this->confirmedUser(['password' => 'SomeOldPass1!']);

        $this->actingAs($user)
            ->post(route('users.reset-password', $other))
            ->assertForbidden();

        $other->refresh();
        $this->assertTrue(Hash::check('SomeOldPass1!', $other->password));
    }
}
