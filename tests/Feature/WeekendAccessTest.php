<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureEmailVerifiedIfEnforced;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RequirePasswordChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RestrictWeekendAccess blocks everyone except SDS/admin on Saturday/Sunday —
 * can_login_weekends is a per-user, admin-controlled override (disabled by
 * default) that lets specific staff through anyway.
 */
class WeekendAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deliberately keep RestrictWeekendAccess active — only silence the
        // other gates in that route group so a plain factory user can reach
        // /dashboard without unrelated 2FA/verification/password noise.
        $this->withoutMiddleware([
            EnsureTwoFactorEnabled::class,
            RequirePasswordChange::class,
            EnsureEmailVerifiedIfEnforced::class,
        ]);

        foreach (['admin', 'sds', 'originator'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Carbon::setTestNow(Carbon::parse('next Saturday')->setTime(10, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_users_default_to_no_weekend_access(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->fresh()->can_login_weekends);
    }

    public function test_regular_user_is_blocked_on_a_weekend_by_default(): void
    {
        $user = User::factory()->create();
        $user->assignRole('originator');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_regular_user_with_weekend_access_enabled_can_log_in(): void
    {
        $user = User::factory()->create(['can_login_weekends' => true]);
        $user->assignRole('originator');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_sds_can_always_access_regardless_of_the_flag(): void
    {
        $sds = User::factory()->create(['can_login_weekends' => false]);
        $sds->assignRole('sds');

        $this->actingAs($sds)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_admin_can_toggle_a_users_weekend_access(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $user = User::factory()->create();
        $user->assignRole('originator');

        $this->actingAs($admin)
            ->post(route('users.toggle-weekend-access', $user))
            ->assertRedirect(route('users.index'));

        $this->assertTrue($user->fresh()->can_login_weekends);

        // Toggling again revokes it.
        $this->actingAs($admin)
            ->post(route('users.toggle-weekend-access', $user))
            ->assertRedirect(route('users.index'));

        $this->assertFalse($user->fresh()->can_login_weekends);
    }

    public function test_non_admin_cannot_toggle_weekend_access(): void
    {
        $originator = User::factory()->create();
        $originator->assignRole('originator');

        $target = User::factory()->create();
        $target->assignRole('originator');

        $this->actingAs($originator)
            ->post(route('users.toggle-weekend-access', $target))
            ->assertForbidden();

        $this->assertFalse($target->fresh()->can_login_weekends);
    }

    public function test_weekend_restriction_does_not_apply_on_a_weekday(): void
    {
        Carbon::setTestNow(Carbon::parse('next Monday')->setTime(10, 0));

        $user = User::factory()->create();
        $user->assignRole('originator');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }
}
