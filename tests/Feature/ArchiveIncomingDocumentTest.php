<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
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
 * Records Section can upload incoming documents directly, and unlike
 * OSDS-Proper uploads these are never auto-forwarded — they sit at Received
 * until someone routes them to a division chief. Not every one of these
 * needs to actually reach a chief, so the SDS's OSDS secretary can file one
 * directly (DocumentStatus::Archived — previously a fully vestigial enum
 * case) instead of routing it. See DocumentPolicy::archive()'s first case.
 *
 * Its second case covers the opposite direction: an OSDS-Proper upload that
 * auto-forwards to Records Section on intake (IncomingAutoForwarder) stays
 * at Routed forever once Records Section files their courtesy copy, since
 * that alone never promotes the document (no *real* routing exists) — the
 * secretary needs the same ability to file the document itself so it stops
 * sitting in her For Action queue with nothing left to do about it.
 */
class ArchiveIncomingDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Division $osdsp;
    private Division $records;
    private Division $ad;
    private User $secretary;
    private User $sds;
    private User $recordsClerk;
    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->osdsp = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $osds->id]);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);

        $this->secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $this->secretary->assignRole('secretary');

        $this->sds = User::factory()->create(['division_id' => $osds->id]);
        $this->sds->assignRole('sds');

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');

        $this->chief = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chief->assignRole('division_chief');
    }

    private function uploadAsRecordsClerk(string $title): Document
    {
        $this->actingAs($this->recordsClerk)->post('/documents/incoming', [
            'title'         => $title,
            'source_origin' => 'Walk-in',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        return Document::firstWhere('title', $title);
    }

    /**
     * Triggers IncomingAutoForwarder — the secretary sits in OSDS Proper
     * (division code 'OSDSP'), the exact condition IncomingDocumentController::
     * store() checks to auto-forward on intake.
     */
    private function uploadAsSecretary(string $title): Document
    {
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => $title,
            'source_origin' => 'Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        return Document::firstWhere('title', $title);
    }

    public function test_osds_secretary_can_archive_a_records_section_upload(): void
    {
        $document = $this->uploadAsRecordsClerk('FYI circular');

        $this->assertTrue($this->secretary->can('archive', $document));

        $this->actingAs($this->secretary)
            ->post(route('documents.archive', $document), ['remarks' => 'Informational only.'])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Archived, $document->status);
        $this->assertDatabaseHas('signature_audit_logs', [
            'document_id' => $document->id,
            'action' => 'document.archived',
        ]);
    }

    public function test_incoming_upload_stamps_division_id(): void
    {
        $document = $this->uploadAsRecordsClerk('Stamped division memo');

        $this->assertSame($this->records->id, $document->division_id);
    }

    public function test_sds_cannot_archive_directly(): void
    {
        $document = $this->uploadAsRecordsClerk('SDS cannot file this');

        $this->assertFalse($this->sds->can('archive', $document));

        $this->actingAs($this->sds)
            ->post(route('documents.archive', $document), [])
            ->assertForbidden();
    }

    public function test_secretary_outside_osds_cannot_archive(): void
    {
        $outsideSecretary = User::factory()->create(['division_id' => $this->ad->id]);
        $outsideSecretary->assignRole('secretary');

        $document = $this->uploadAsRecordsClerk('Outside secretary cannot file this');

        $this->assertFalse($outsideSecretary->can('archive', $document));
    }

    public function test_cannot_archive_a_document_not_uploaded_by_records_section(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'SDS-uploaded memo',
            'uploaded_by' => $this->sds->id, 'division_id' => $this->sds->division_id,
            'file_path' => 'incoming/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Received,
        ]);

        $this->assertFalse($this->secretary->can('archive', $document));
    }

    public function test_cannot_archive_once_already_routed(): void
    {
        $document = $this->uploadAsRecordsClerk('Already routed memo');

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $this->assertSame(DocumentStatus::Routed, $document->fresh()->status);
        $this->assertFalse($this->secretary->can('archive', $document->fresh()));

        $this->actingAs($this->secretary)
            ->post(route('documents.archive', $document), [])
            ->assertForbidden();
    }

    public function test_archived_document_cannot_be_archived_again_or_routed(): void
    {
        $document = $this->uploadAsRecordsClerk('Already filed memo');

        $this->actingAs($this->secretary)->post(route('documents.archive', $document), []);
        $document->refresh();

        $this->assertFalse($this->secretary->can('archive', $document));
        $this->assertFalse($this->secretary->can('route', $document));

        $this->actingAs($this->secretary)
            ->post(route('documents.archive', $document), [])
            ->assertForbidden();
    }

    public function test_archived_document_disappears_from_for_routing_tab(): void
    {
        $document = $this->uploadAsRecordsClerk('Vanishes from queue memo');

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));

        $this->actingAs($this->secretary)->post(route('documents.archive', $document), []);

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_legacy_document_with_null_division_id_is_still_archivable(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Legacy pre-stamp memo',
            'uploaded_by' => $this->recordsClerk->id, 'division_id' => null,
            'file_path' => 'incoming/legacy.pdf', 'file_hash' => hash('sha256', 'legacy'),
            'status' => DocumentStatus::Received,
        ]);

        $this->assertTrue($this->secretary->can('archive', $document));
    }

    public function test_uploader_cannot_delete_document_once_archived(): void
    {
        $document = $this->uploadAsRecordsClerk('No longer deletable memo');

        $this->assertTrue($this->recordsClerk->can('delete', $document));

        $this->actingAs($this->secretary)->post(route('documents.archive', $document), []);
        $document->refresh();

        $this->assertFalse($this->recordsClerk->can('delete', $document));
    }

    // ── Second case: auto-forwarded (OSDS-Proper upload), Routed, no real routing ──

    public function test_osds_secretary_can_archive_an_auto_forwarded_document_with_no_real_routing(): void
    {
        $document = $this->uploadAsSecretary('Auto-forwarded circular');

        $this->assertSame(DocumentStatus::Routed, $document->status);
        $this->assertTrue($this->secretary->can('archive', $document));

        $this->actingAs($this->secretary)
            ->post(route('documents.archive', $document), [])
            ->assertRedirect(route('documents.show', $document));

        $this->assertSame(DocumentStatus::Archived, $document->fresh()->status);
    }

    /**
     * The exact reported scenario: Records Section files their auto-forward
     * copy (the recipient's action is literally RoutingAction::File) first —
     * that alone doesn't promote the document, so the secretary must still
     * be able to file the document itself afterward.
     */
    public function test_osds_secretary_can_still_archive_after_records_section_files_their_own_copy(): void
    {
        $document = $this->uploadAsSecretary('Filed by records section first');
        $document->load('routings.recipients');
        $recipient = $document->routings->first()->recipients->first();

        $this->actingAs($this->recordsClerk)
            ->patch(route('routing-recipients.acknowledge', $recipient), []);

        $this->assertSame('completed', $recipient->fresh()->status->value);
        $this->assertSame(DocumentStatus::Routed, $document->fresh()->status);

        $this->assertTrue($this->secretary->can('archive', $document->fresh()));

        $this->actingAs($this->secretary)
            ->post(route('documents.archive', $document), [])
            ->assertRedirect(route('documents.show', $document));

        $this->assertSame(DocumentStatus::Archived, $document->fresh()->status);
    }

    public function test_cannot_archive_an_auto_forwarded_document_once_a_real_routing_is_added(): void
    {
        $document = $this->uploadAsSecretary('Also really routed');

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $this->assertFalse($this->secretary->can('archive', $document->fresh()));
    }

    /**
     * A real routing that was itself cancelled doesn't count — same
     * "cancelled means it never really happened" treatment used everywhere
     * else a routing's existence is checked.
     */
    public function test_can_archive_an_auto_forwarded_document_once_its_extra_real_routing_is_cancelled(): void
    {
        $document = $this->uploadAsSecretary('Really routed then cancelled');

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings');
        // Not firstWhere('routed_by', ...) — the auto-forward routing is
        // ALSO routed_by the secretary (she's the uploader), so that alone
        // doesn't distinguish it from the real one she just issued.
        $realRouting = $document->routings->first(
            fn ($r) => $r->remarks !== \App\Http\Controllers\IncomingDocumentController::AUTO_FORWARD_REMARKS,
        );
        $this->actingAs($this->secretary)
            ->delete(route('documents.routings.cancel', [$document, $realRouting]))
            ->assertRedirect();

        $this->assertTrue($this->secretary->can('archive', $document->fresh()));
    }

    public function test_auto_forwarded_document_disappears_from_secretarys_for_action_tab_once_archived(): void
    {
        $document = $this->uploadAsSecretary('Auto-forwarded, now filed');

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));

        $this->actingAs($this->secretary)->post(route('documents.archive', $document), []);

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }
}
