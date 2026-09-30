<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecipientStatus;
use App\Enums\RoutingAction;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the admin "office management" screen (DivisionController) — CRUD
 * over the Division model, admin-only, plus the deletion guard: every
 * foreign key into divisions.id (users, documents,
 * document_routing_recipients, and divisions.parent_id itself) uses
 * nullOnDelete(), so without an app-level check, deleting a division with
 * real data would silently detach everything from it rather than failing
 * loudly — see DivisionController::deletionBlockers().
 */
class DivisionManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->originator = User::factory()->create();
        $this->originator->assignRole('originator');
    }

    public function test_non_admin_cannot_access_office_management(): void
    {
        $division = Division::create(['name' => 'Test Division', 'code' => 'TD']);

        $this->actingAs($this->originator)->get(route('divisions.index'))->assertForbidden();
        $this->actingAs($this->originator)->get(route('divisions.create'))->assertForbidden();
        $this->actingAs($this->originator)->get(route('divisions.edit', $division))->assertForbidden();
        $this->actingAs($this->originator)->post(route('divisions.store'), ['name' => 'New Office'])->assertForbidden();
        $this->actingAs($this->originator)->put(route('divisions.update', $division), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($this->originator)->delete(route('divisions.destroy', $division))->assertForbidden();
    }

    public function test_admin_can_create_a_top_level_office(): void
    {
        $this->actingAs($this->admin)->post(route('divisions.store'), [
            'name' => 'Legal Unit',
            'code' => 'lu',
        ])->assertRedirect(route('divisions.index'));

        $division = Division::where('name', 'Legal Unit')->firstOrFail();
        $this->assertNull($division->parent_id);
        // Codes are normalized to uppercase to match this app's OSDS/RS/etc. convention.
        $this->assertSame('LU', $division->code);
    }

    public function test_admin_can_create_a_sub_office_under_a_parent(): void
    {
        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);

        $this->actingAs($this->admin)->post(route('divisions.store'), [
            'name' => 'Public Affairs Unit',
            'parent_id' => $osds->id,
        ])->assertRedirect(route('divisions.index'));

        $division = Division::where('name', 'Public Affairs Unit')->firstOrFail();
        $this->assertSame($osds->id, $division->parent_id);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true]);

        $this->actingAs($this->admin)->post(route('divisions.store'), [
            'name' => 'Another Office',
            'code' => 'RS',
        ])->assertSessionHasErrors('code');
    }

    public function test_admin_can_update_an_office(): void
    {
        $division = Division::create(['name' => 'Old Name', 'code' => 'ON']);

        $this->actingAs($this->admin)->put(route('divisions.update', $division), [
            'name' => 'New Name',
            'code' => 'ON',
        ])->assertRedirect(route('divisions.index'));

        $this->assertSame('New Name', $division->fresh()->name);
    }

    public function test_admin_can_enable_direct_sds_routing_on_creation(): void
    {
        $hrdd = Division::create(['name' => 'Human Resource Development Division', 'code' => 'HRDD']);

        $this->actingAs($this->admin)->post(route('divisions.store'), [
            'name' => 'NEAP',
            'parent_id' => $hrdd->id,
            'allow_sds_direct_routing' => true,
        ])->assertRedirect(route('divisions.index'));

        $division = Division::where('name', 'NEAP')->firstOrFail();
        $this->assertTrue($division->allow_sds_direct_routing);
    }

    public function test_admin_can_toggle_direct_sds_routing_via_update(): void
    {
        $division = Division::create(['name' => 'NEAP', 'allow_sds_direct_routing' => false]);

        $this->actingAs($this->admin)->put(route('divisions.update', $division), [
            'name' => $division->name,
            'allow_sds_direct_routing' => true,
        ])->assertRedirect(route('divisions.index'));

        $this->assertTrue($division->fresh()->allow_sds_direct_routing);

        $this->actingAs($this->admin)->put(route('divisions.update', $division), [
            'name' => $division->name,
            'allow_sds_direct_routing' => false,
        ])->assertRedirect(route('divisions.index'));

        $this->assertFalse($division->fresh()->allow_sds_direct_routing);
    }

    public function test_index_reports_allow_sds_direct_routing_flag(): void
    {
        $flagged = Division::create(['name' => 'ICTU', 'allow_sds_direct_routing' => true]);
        $plain = Division::create(['name' => 'Procurement Unit']);

        $this->actingAs($this->admin)
            ->get(route('divisions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('divisions', function ($rows) use ($flagged, $plain) {
                    $rows = collect($rows);

                    return $rows->firstWhere('id', $flagged->id)['allowSdsDirectRouting'] === true
                        && $rows->firstWhere('id', $plain->id)['allowSdsDirectRouting'] === false;
                }));
    }

    public function test_an_office_cannot_be_made_its_own_parent(): void
    {
        $division = Division::create(['name' => 'Self Parent Test']);

        $this->actingAs($this->admin)->put(route('divisions.update', $division), [
            'name' => $division->name,
            'parent_id' => $division->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_an_office_cannot_be_made_a_sub_office_of_its_own_child(): void
    {
        $parent = Division::create(['name' => 'Parent Office']);
        $child = Division::create(['name' => 'Child Office', 'parent_id' => $parent->id]);

        $this->actingAs($this->admin)->put(route('divisions.update', $parent), [
            'name' => $parent->name,
            'parent_id' => $child->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_deleting_an_office_with_no_references_succeeds(): void
    {
        $division = Division::create(['name' => 'Empty Office']);

        $this->actingAs($this->admin)->delete(route('divisions.destroy', $division))
            ->assertRedirect(route('divisions.index'));

        $this->assertNull(Division::find($division->id));
    }

    public function test_deleting_an_office_with_a_sub_office_is_blocked(): void
    {
        $parent = Division::create(['name' => 'Parent Office']);
        Division::create(['name' => 'Child Office', 'parent_id' => $parent->id]);

        $this->actingAs($this->admin)->delete(route('divisions.destroy', $parent))
            ->assertRedirect(route('divisions.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Division::find($parent->id));
    }

    public function test_deleting_an_office_with_assigned_users_is_blocked(): void
    {
        $division = Division::create(['name' => 'Staffed Office']);
        User::factory()->create(['division_id' => $division->id]);

        $this->actingAs($this->admin)->delete(route('divisions.destroy', $division))
            ->assertRedirect(route('divisions.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Division::find($division->id));
    }

    public function test_deleting_an_office_with_documents_is_blocked(): void
    {
        $division = Division::create(['name' => 'Office With Documents']);

        Document::create([
            'type' => DocumentType::Outgoing,
            'title' => 'Some memo',
            'uploaded_by' => $this->originator->id,
            'division_id' => $division->id,
            'file_path' => 'outgoing/2026/07/memo.pdf',
            'file_hash' => hash('sha256', 'memo'),
            'status' => DocumentStatus::Draft,
        ]);

        $this->actingAs($this->admin)->delete(route('divisions.destroy', $division))
            ->assertRedirect(route('divisions.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Division::find($division->id));
    }

    public function test_deleting_an_office_with_routing_history_is_blocked(): void
    {
        $division = Division::create(['name' => 'Office With Routing History']);

        $document = Document::create([
            'type' => DocumentType::Incoming,
            'title' => 'Some incoming doc',
            'uploaded_by' => $this->originator->id,
            'file_path' => 'incoming/2026/07/doc.pdf',
            'file_hash' => hash('sha256', 'doc'),
            'status' => DocumentStatus::Routed,
        ]);

        $routing = $document->routings()->create([
            'routed_by' => $this->admin->id,
            'remarks' => 'For action',
        ]);

        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id,
            'division_id' => $division->id,
            'action' => RoutingAction::AppropriateAction,
            'status' => RecipientStatus::Pending,
        ]);

        $this->actingAs($this->admin)->delete(route('divisions.destroy', $division))
            ->assertRedirect(route('divisions.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Division::find($division->id));
    }

    public function test_index_reports_can_delete_flag_correctly(): void
    {
        $empty = Division::create(['name' => 'Empty Office']);
        $withChild = Division::create(['name' => 'Parent Office']);
        Division::create(['name' => 'Child Office', 'parent_id' => $withChild->id]);

        $this->actingAs($this->admin)
            ->get(route('divisions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('divisions', function ($rows) use ($empty, $withChild) {
                    $rows = collect($rows);

                    return $rows->firstWhere('id', $empty->id)['canDelete'] === true
                        && $rows->firstWhere('id', $withChild->id)['canDelete'] === false;
                }));
    }

    public function test_admin_can_designate_the_records_section_on_creation(): void
    {
        $this->actingAs($this->admin)->post(route('divisions.store'), [
            'name' => 'Records Section',
            'is_records_section' => true,
        ])->assertRedirect(route('divisions.index'));

        $records = Division::where('name', 'Records Section')->firstOrFail();
        $this->assertTrue($records->is_records_section);
        $this->assertSame($records->id, Division::recordsSectionId());
        $this->assertDatabaseHas('signature_audit_logs', ['action' => 'division.records_section_designated']);
    }

    /**
     * Only one office can be the Records Section — designating another one
     * moves the designation rather than creating a second.
     */
    public function test_designating_another_office_moves_the_records_section(): void
    {
        $old = Division::create(['name' => 'Records Unit', 'is_records_section' => true]);
        $new = Division::create(['name' => 'Records Section']);

        $this->actingAs($this->admin)->put(route('divisions.update', $new), [
            'name' => $new->name,
            'is_records_section' => true,
        ])->assertRedirect(route('divisions.index'));

        $this->assertTrue($new->fresh()->is_records_section);
        $this->assertFalse($old->fresh()->is_records_section);
        $this->assertSame($new->id, Division::recordsSectionId());
    }

    public function test_clearing_the_designation_leaves_no_records_section(): void
    {
        $records = Division::create(['name' => 'Records Section', 'is_records_section' => true]);

        $this->actingAs($this->admin)->put(route('divisions.update', $records), [
            'name' => $records->name,
            'is_records_section' => false,
        ])->assertRedirect(route('divisions.index'));

        $this->assertNull(Division::recordsSectionId());
        $this->assertDatabaseHas('signature_audit_logs', ['action' => 'division.records_section_cleared']);
    }

    /**
     * The "RS" code no longer means anything on its own — only the explicit
     * designation does.
     */
    public function test_a_division_coded_rs_is_not_the_records_section_unless_designated(): void
    {
        Division::create(['name' => 'Records Section', 'code' => 'RS']);

        $this->assertNull(Division::recordsSectionId());
    }

    public function test_index_reports_the_records_section_and_whether_one_is_configured(): void
    {
        $plain = Division::create(['name' => 'Legal Unit']);

        $this->actingAs($this->admin)
            ->get(route('divisions.index'))
            ->assertInertia(fn ($page) => $page->where('recordsSectionConfigured', false));

        $records = Division::create(['name' => 'Records Section', 'is_records_section' => true]);

        $this->actingAs($this->admin)
            ->get(route('divisions.index', ['search' => 'Legal']))
            // A search that hides the Records Section row must not hide the fact that one exists.
            ->assertInertia(fn ($page) => $page->where('recordsSectionConfigured', true));

        $this->actingAs($this->admin)
            ->get(route('divisions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('divisions', function ($rows) use ($records, $plain) {
                    $rows = collect($rows);

                    return $rows->firstWhere('id', $records->id)['isRecordsSection'] === true
                        && $rows->firstWhere('id', $plain->id)['isRecordsSection'] === false;
                }));
    }

    public function test_the_osds_code_cannot_be_changed(): void
    {
        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);

        $this->actingAs($this->admin)->put(route('divisions.update', $osds), [
            'name' => 'Office of the SDS',
            'code' => 'SDO',
        ])->assertSessionHasErrors('code');

        $this->assertSame('OSDS', $osds->fresh()->code);

        // Renaming it while keeping the code is fine.
        $this->actingAs($this->admin)->put(route('divisions.update', $osds), [
            'name' => 'Office of the SDS',
            'code' => 'OSDS',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Office of the SDS', $osds->fresh()->name);
    }

    public function test_the_osds_office_cannot_be_deleted_even_when_empty(): void
    {
        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);

        $this->actingAs($this->admin)->delete(route('divisions.destroy', $osds))
            ->assertRedirect(route('divisions.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Division::find($osds->id));

        $this->actingAs($this->admin)
            ->get(route('divisions.index'))
            ->assertInertia(fn ($page) => $page
                ->where('divisions', fn ($rows) => collect($rows)->firstWhere('id', $osds->id)['canDelete'] === false));
    }
}
