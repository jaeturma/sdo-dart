<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\EmailVerificationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailVerificationSettingTest extends TestCase
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
            'two_factor_secret'       => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->admin->assignRole('admin');
    }

    private function unverifiedUser(): User
    {
        $user = User::factory()->unverified()->create([
            'two_factor_secret'       => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('originator');

        return $user;
    }

    public function test_email_verification_is_enforced_by_default(): void
    {
        $this->assertTrue(EmailVerificationSetting::isEnforced());

        $this->actingAs($this->unverifiedUser())
            ->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_admin_can_make_email_verification_optional(): void
    {
        $this->actingAs($this->admin)
            ->post(route('email-verification-setting.toggle'))
            ->assertRedirect();

        $this->assertFalse(EmailVerificationSetting::isEnforced());

        $this->actingAs($this->unverifiedUser())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_toggling_twice_restores_enforcement(): void
    {
        $this->actingAs($this->admin)->post(route('email-verification-setting.toggle'));
        $this->actingAs($this->admin)->post(route('email-verification-setting.toggle'));

        $this->assertTrue(EmailVerificationSetting::isEnforced());
    }

    public function test_non_admin_cannot_toggle_the_setting(): void
    {
        $user = User::factory()->create([
            'two_factor_secret'       => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('originator');

        $this->actingAs($user)
            ->post(route('email-verification-setting.toggle'))
            ->assertForbidden();

        $this->assertTrue(EmailVerificationSetting::isEnforced());
    }

    public function test_verified_users_are_unaffected_regardless_of_enforcement(): void
    {
        $user = User::factory()->create([
            'two_factor_secret'       => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('originator');

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
