<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\OriginatorUploadSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DocumentPolicy::createOutgoing() has always allowed originator/unit_head/
 * division_chief, but the "+ New Outgoing" button on Documents/Index.vue was
 * gated by a hardcoded client-side hasRole('originator', 'division_chief')
 * check that never included unit_head — so OSDS sub-unit heads (ICTU, LU,
 * PAU) never saw the button even though they were always allowed to use it.
 * The button is now driven by a server-computed canCreateOutgoing prop
 * instead, so it can't drift from the policy again.
 */
class CreateOutgoingPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $pau;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'sds', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->pau = Division::create(['name' => 'Public Affairs Unit', 'code' => 'PAU', 'parent_id' => $this->osds->id]);
    }

    public function test_pau_unit_head_can_create_outgoing_document(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->pau->id]);
        $unitHead->assignRole('unit_head');

        $this->assertTrue($unitHead->can('createOutgoing', Document::class));

        $this->actingAs($unitHead)
            ->get(route('documents.outgoing.create'))
            ->assertOk();
    }

    public function test_documents_index_shows_new_outgoing_button_for_pau_unit_head(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->pau->id]);
        $unitHead->assignRole('unit_head');

        $this->actingAs($unitHead)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', true));
    }

    public function test_user_without_a_division_cannot_create_outgoing(): void
    {
        $noDivision = User::factory()->create(['division_id' => null]);
        $noDivision->assignRole('originator');

        $this->assertFalse($noDivision->can('createOutgoing', Document::class));

        $this->actingAs($noDivision)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', false));
    }

    public function test_records_section_originator_can_now_view_my_signature_nav(): void
    {
        $records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->osds->id]);
        $clerk = User::factory()->create(['division_id' => $records->id]);
        $clerk->assignRole('originator');

        $this->actingAs($clerk)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('auth.canUploadSignature', true));
    }

    /**
     * A plain 'originator' (Staff) with a division can no longer create
     * outgoing documents by default — they need the additional 'kmt_focal'
     * role. Covers both the policy directly and the two server-side
     * enforcement points (the create() page load and the "New Outgoing"
     * button visibility).
     */
    public function test_plain_originator_cannot_create_outgoing_without_kmt_focal(): void
    {
        $originator = User::factory()->create(['division_id' => $this->pau->id]);
        $originator->assignRole('originator');

        $this->assertFalse($originator->can('createOutgoing', Document::class));

        $this->actingAs($originator)
            ->get(route('documents.outgoing.create'))
            ->assertForbidden();

        $this->actingAs($originator)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', false));
    }

    /**
     * An originator additionally tagged 'kmt_focal' regains the ability.
     */
    public function test_originator_with_kmt_focal_can_create_outgoing(): void
    {
        $originator = User::factory()->create(['division_id' => $this->pau->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->assertTrue($originator->can('createOutgoing', Document::class));

        $this->actingAs($originator)
            ->get(route('documents.outgoing.create'))
            ->assertOk();

        $this->actingAs($originator)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', true));
    }

    /**
     * 'kmt_focal' alone, without the 'originator' role, grants nothing — it
     * only has an effect layered on top of 'originator'.
     */
    public function test_kmt_focal_alone_without_originator_role_grants_nothing(): void
    {
        $user = User::factory()->create(['division_id' => $this->pau->id]);
        $user->assignRole('kmt_focal');

        $this->assertFalse($user->can('createOutgoing', Document::class));
    }

    public function test_the_all_originators_setting_is_off_by_default(): void
    {
        $this->assertFalse(OriginatorUploadSetting::allowsAllOriginators());
    }

    /**
     * With the admin's "all originators" switch on, a plain originator (no
     * kmt_focal) can create outgoing documents; switching it back off
     * restores the KMT-only rule without touching anyone's roles.
     */
    public function test_admin_toggle_lets_every_originator_create_outgoing_and_back(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $originator = User::factory()->create(['division_id' => $this->pau->id]);
        $originator->assignRole('originator');

        $this->actingAs($admin)
            ->post(route('originator-upload-setting.toggle'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(OriginatorUploadSetting::allowsAllOriginators());
        $this->assertTrue($originator->fresh()->can('createOutgoing', Document::class));
        $this->actingAs($originator)->get(route('documents.outgoing.create'))->assertOk();
        $this->assertDatabaseHas('signature_audit_logs', ['action' => 'auth.originator_upload_toggled']);

        $this->actingAs($admin)->post(route('originator-upload-setting.toggle'));

        $this->assertFalse(OriginatorUploadSetting::allowsAllOriginators());
        $this->assertFalse($originator->fresh()->can('createOutgoing', Document::class));
        $this->assertFalse($originator->fresh()->hasRole('kmt_focal'));
    }

    public function test_all_originators_setting_still_requires_a_division(): void
    {
        OriginatorUploadSetting::toggle();

        $noDivision = User::factory()->create(['division_id' => null]);
        $noDivision->assignRole('originator');

        $this->assertFalse($noDivision->can('createOutgoing', Document::class));
    }

    public function test_non_admin_cannot_toggle_the_all_originators_setting(): void
    {
        $chief = User::factory()->create(['division_id' => $this->osds->id]);
        $chief->assignRole('division_chief');

        $this->actingAs($chief)
            ->post(route('originator-upload-setting.toggle'))
            ->assertForbidden();

        $this->assertFalse(OriginatorUploadSetting::allowsAllOriginators());
    }

    public function test_users_page_reports_the_all_originators_setting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        OriginatorUploadSetting::toggle();

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertInertia(fn ($page) => $page->where('allOriginatorsCanUpload', true));
    }
}
