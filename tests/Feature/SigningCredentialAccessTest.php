<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\User;
use App\Models\UserSigningCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Records Section staff now need their own PNPKI signing credential to
 * attach a release stamp — this widens SigningCredentialPolicy::upload()
 * (previously rd/ard/division_chief only) to also include Records Section
 * division membership, without opening it up to everyone.
 */
class SigningCredentialAccessTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private Division $records;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'rd'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'parent_id' => $this->ad->id]);
    }

    public function test_records_section_user_can_view_and_upload_their_own_credential(): void
    {
        $clerk = User::factory()->create(['division_id' => $this->records->id]);
        $clerk->assignRole('originator');

        $this->assertTrue($clerk->can('upload', UserSigningCredential::class));

        $this->actingAs($clerk)
            ->get(route('my-signature.show'))
            ->assertOk();
    }

    public function test_non_records_section_originator_cannot_view_or_upload(): void
    {
        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->assertFalse($staff->can('upload', UserSigningCredential::class));

        $this->actingAs($staff)
            ->get(route('my-signature.show'))
            ->assertForbidden();
    }

    public function test_rd_can_still_view_and_upload(): void
    {
        $rd = User::factory()->create();
        $rd->assignRole('rd');

        $this->assertTrue($rd->can('upload', UserSigningCredential::class));

        $this->actingAs($rd)
            ->get(route('my-signature.show'))
            ->assertOk();
    }

    public function test_admin_overview_includes_records_section_staff(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $clerk = User::factory()->create(['division_id' => $this->records->id, 'name' => 'Records Clerk']);
        $clerk->assignRole('originator');

        $this->actingAs($admin)
            ->get(route('signing-credentials.index'))
            ->assertInertia(fn ($page) => $page->where(
                'users',
                fn ($users) => collect($users)->pluck('name')->contains('Records Clerk'),
            ));
    }
}
