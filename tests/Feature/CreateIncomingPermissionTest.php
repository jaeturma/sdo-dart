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
 * Only the RD's secretary (ORD Proper), Records Section staff — plus
 * admin/rd — may upload incoming documents. Other ORD sub-unit staff (ICTU,
 * LU, PAU) sit within the ORD division tree but don't receive incoming mail
 * themselves, so originator/unit_head roles there must not see or be able to
 * use "+ Upload Incoming".
 */
class CreateIncomingPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;
    private Division $ordp;
    private Division $pau;
    private Division $records;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord     = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->ordp    = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $this->ord->id]);
        $this->pau     = Division::create(['name' => 'Public Affairs Unit', 'code' => 'PAU', 'parent_id' => $this->ord->id]);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS']);
    }

    public function test_secretary_in_ordp_can_upload_incoming(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->ordp->id]);
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

    public function test_rd_and_admin_can_still_upload_incoming(): void
    {
        $rd = User::factory()->create();
        $rd->assignRole('rd');
        $this->assertTrue($rd->can('createIncoming', Document::class));

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

    public function test_records_section_upload_lands_at_received_and_is_visible_to_the_rd(): void
    {
        $staff = User::factory()->create(['division_id' => $this->records->id]);
        $staff->assignRole('originator');

        $rd = User::factory()->create();
        $rd->assignRole('rd');

        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');

        $response = $this->actingAs($staff)->post('/documents/incoming', [
            'title'         => 'Directly received memo',
            'source_origin' => 'Walk-in courier',
            'file'          => $file,
        ]);

        $document = Document::firstWhere('title', 'Directly received memo');

        $response->assertRedirect(route('documents.show', $document));

        // No auto-forward for Records Section uploads (that only fires for
        // ORD Proper's division code) — it just sits at Received, awaiting
        // the RD to route it to a chief.
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertCount(0, $document->routings);

        $this->assertTrue($rd->can('view', $document));
        $this->assertContains($document->id, $this->forActionScopeIds($rd));
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
