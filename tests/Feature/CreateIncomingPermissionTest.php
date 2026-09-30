<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Only the SDS's secretary (OSDS Proper), Records Section staff — plus
 * admin/sds — may upload incoming documents. Other OSDS sub-unit staff (ICTU,
 * LU, PAU) sit within the OSDS division tree but don't receive incoming mail
 * themselves, so originator/unit_head roles there must not see or be able to
 * use "+ Upload Incoming".
 */
class CreateIncomingPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $osdsp;
    private Division $pau;
    private Division $records;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds     = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->osdsp    = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $this->osds->id]);
        $this->pau     = Division::create(['name' => 'Public Affairs Unit', 'code' => 'PAU', 'parent_id' => $this->osds->id]);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true]);
    }

    public function test_secretary_in_osdsp_can_upload_incoming(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $secretary->assignRole('secretary');

        $this->assertTrue($secretary->can('createIncoming', Document::class));
    }

    public function test_pau_originator_cannot_upload_incoming(): void
    {
        $staff = User::factory()->create(['division_id' => $this->pau->id]);
        $staff->assignRole('originator');

        $this->assertFalse($staff->can('createIncoming', Document::class));

        $this->actingAs($staff)
            ->get(route('documents.incoming.create'))
            ->assertForbidden();
    }

    public function test_pau_unit_head_cannot_upload_incoming(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->pau->id]);
        $unitHead->assignRole('unit_head');

        $this->assertFalse($unitHead->can('createIncoming', Document::class));
    }

    public function test_sds_and_admin_can_still_upload_incoming(): void
    {
        $sds = User::factory()->create();
        $sds->assignRole('sds');
        $this->assertTrue($sds->can('createIncoming', Document::class));

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->assertTrue($admin->can('createIncoming', Document::class));
    }

    public function test_documents_index_hides_upload_incoming_button_for_pau_staff(): void
    {
        $staff = User::factory()->create(['division_id' => $this->pau->id]);
        $staff->assignRole('originator');

        $this->actingAs($staff)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateIncoming', false));
    }

    public function test_records_section_staff_can_upload_incoming(): void
    {
        $staff = User::factory()->create(['division_id' => $this->records->id]);
        $staff->assignRole('originator');

        $this->assertTrue($staff->can('createIncoming', Document::class));
    }

    public function test_records_section_upload_lands_at_received_and_is_visible_to_the_sds(): void
    {
        $staff = User::factory()->create(['division_id' => $this->records->id]);
        $staff->assignRole('originator');

        $sds = User::factory()->create();
        $sds->assignRole('sds');

        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');

        $response = $this->actingAs($staff)->post('/documents/incoming', [
            'title'         => 'Directly received memo',
            'source_origin' => 'Walk-in courier',
            'file'          => $file,
        ]);

        $document = Document::firstWhere('title', 'Directly received memo');

        $response->assertRedirect(route('documents.show', $document));

        // No auto-forward for Records Section uploads (that only fires for
        // OSDS Proper's division code) — it just sits at Received, awaiting
        // the SDS to route it to a chief.
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertCount(0, $document->routings);

        $this->assertTrue($sds->can('view', $document));
        $this->assertContains($document->id, $this->forActionScopeIds($sds));
    }

    /** @return array<int> */
    private function forActionScopeIds(User $user): array
    {
        return (new class {
            use \App\Traits\DocumentScopesTrait {
                forActionScope as public;
            }
        })->forActionScope(Document::query(), $user)->pluck('id')->all();
    }
}
