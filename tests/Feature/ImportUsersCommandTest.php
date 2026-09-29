<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/import_users_fixture_'.uniqid().'.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    private function writeFixture(array $rows): void
    {
        file_put_contents($this->fixturePath, '<?php return '.var_export($rows, true).';');
    }

    public function test_dry_run_previews_without_writing_anything(): void
    {
        Division::create(['name' => 'Finance Division', 'code' => 'FD']);

        $this->writeFixture([
            ['name' => 'New Person', 'email' => 'new.person@example.com', 'division' => 'Finance Division'],
        ]);

        $this->artisan('users:import', ['--file' => $this->fixturePath, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    }

    public function test_import_creates_inactive_originator_accounts_with_matched_division(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'originator', 'guard_name' => 'web']);
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);

        $this->writeFixture([
            ['name' => 'New Person', 'email' => 'New.Person@Example.com', 'division' => 'Finance Division'],
        ]);

        $this->artisan('users:import', ['--file' => $this->fixturePath])
            ->expectsConfirmation('Create 1 user(s) now?', 'yes')
            ->assertExitCode(0);

        $user = User::where('email', 'new.person@example.com')->first();
        $this->assertNotNull($user, 'Email should be normalized to lowercase.');
        $this->assertFalse($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertSame($division->id, $user->division_id);
        $this->assertTrue($user->hasRole('originator'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Pass6789!0', $user->password));
    }

    public function test_import_skips_an_email_that_already_exists_and_leaves_it_untouched(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'originator', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);

        $existing = User::factory()->create(['email' => 'already.here@example.com', 'is_active' => true]);
        $existing->assignRole('admin');

        $this->writeFixture([
            ['name' => 'Already Here', 'email' => 'already.here@example.com', 'division' => 'Finance Division'],
        ]);

        $this->artisan('users:import', ['--file' => $this->fixturePath, '--dry-run' => true])
            ->assertExitCode(0);

        $existing->refresh();
        $this->assertTrue($existing->is_active);
        $this->assertTrue($existing->hasRole('admin'));
        $this->assertSame(1, User::where('email', 'already.here@example.com')->count());
    }

    public function test_unmatched_division_name_leaves_division_id_null(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'originator', 'guard_name' => 'web']);

        $this->writeFixture([
            ['name' => 'No Division Person', 'email' => 'no.division@example.com', 'division' => 'Nonexistent Division'],
        ]);

        $this->artisan('users:import', ['--file' => $this->fixturePath])
            ->expectsConfirmation('Create 1 user(s) now?', 'yes')
            ->assertExitCode(0);

        $user = User::where('email', 'no.division@example.com')->firstOrFail();
        $this->assertNull($user->division_id);
    }
}
