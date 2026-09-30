<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleAndDivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * users:create-admin is the only way to get a first login on a production
 * install — see App\Console\Commands\CreateAdminUser.
 */
class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    public function test_creates_a_verified_admin(): void
    {
        $this->seed(RoleAndDivisionSeeder::class);

        $this->artisan('users:create-admin', ['email' => 'ict@sdo.deped.gov.ph', '--name' => 'SDO ICT'])
            ->expectsQuestion('Password (min. 10 characters, mixed case, a number and a symbol)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertExitCode(0);

        $admin = User::firstWhere('email', 'ict@sdo.deped.gov.ph');
        $this->assertNotNull($admin);
        $this->assertSame('SDO ICT', $admin->name);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertDatabaseHas('signature_audit_logs', ['action' => 'user.admin_created_via_cli']);
    }

    public function test_rejects_a_weak_password(): void
    {
        $this->seed(RoleAndDivisionSeeder::class);

        $this->artisan('users:create-admin', ['email' => 'ict@sdo.deped.gov.ph'])
            ->expectsQuestion('Password (min. 10 characters, mixed case, a number and a symbol)', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'ict@sdo.deped.gov.ph']);
    }

    public function test_rejects_an_email_that_is_already_taken(): void
    {
        $this->seed(RoleAndDivisionSeeder::class);
        User::factory()->create(['email' => 'taken@sdo.deped.gov.ph']);

        $this->artisan('users:create-admin', ['email' => 'taken@sdo.deped.gov.ph'])
            ->assertExitCode(1);
    }

    public function test_refuses_to_run_before_roles_are_seeded(): void
    {
        $this->artisan('users:create-admin', ['email' => 'ict@sdo.deped.gov.ph'])
            ->expectsOutputToContain('db:seed')
            ->assertExitCode(1);
    }
}
