<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\TwoFactorSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoFactorSettingTest extends TestCase
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

    public function test_two_factor_is_enforced_by_default(): void
    {
        $this->assertTrue(TwoFactorSetting::isEnforced());

        $user = User::factory()->create();
        $user->assignRole('originator');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_admin_can_make_two_factor_optional(): void
    {
        $this->actingAs($this->admin)
            ->post(route('two-factor-setting.toggle'))
            ->assertRedirect();

        $this->assertFalse(TwoFactorSetting::isEnforced());

        $user = User::factory()->create();
        $user->assignRole('originator');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_toggling_twice_restores_enforcement(): void
    {
        $this->actingAs($this->admin)->post(route('two-factor-setting.toggle'));
        $this->actingAs($this->admin)->post(route('two-factor-setting.toggle'));

        $this->assertTrue(TwoFactorSetting::isEnforced());
    }

    public function test_non_admin_cannot_toggle_the_setting(): void
    {
        $user = User::factory()->create([
            'two_factor_secret'       => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('originator');

        $this->actingAs($user)
            ->post(route('two-factor-setting.toggle'))
            ->assertForbidden();

        $this->assertTrue(TwoFactorSetting::isEnforced());
    }
}
