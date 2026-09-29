<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_new_users_are_active_by_default(): void
    {
        // is_active isn't fillable/set by the factory, so the in-memory
        // model never loads it after create() — refresh() to see the actual
        // DB-applied column default.
        $user = User::factory()->create()->refresh();

        $this->assertTrue($user->is_active);
    }

    public function test_active_user_can_log_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'deactivated',
            collect(session('errors')->get('email'))->implode(' '),
        );
    }

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('users.toggle-active', $user))
            ->assertRedirect(route('users.index'));

        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($this->admin)
            ->post(route('users.toggle-active', $user))
            ->assertRedirect(route('users.index'));

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_non_admin_cannot_toggle_active_status(): void
    {
        $originator = User::factory()->create();
        $originator->assignRole('originator');

        $target = User::factory()->create(['is_active' => true]);

        $this->actingAs($originator)
            ->post(route('users.toggle-active', $target))
            ->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.toggle-active', $this->admin))
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->is_active);
    }
}
