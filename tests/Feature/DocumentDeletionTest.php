<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ReviewAction;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentReview;
use App\Models\DocumentRouting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An outgoing document's own uploader may cancel/delete it themselves any
 * time before the RD approves it — even after a unit head/division
 * chief/ARD has already reviewed and forwarded it onward, since none of
 * those are the final word. Once Approved (or beyond), only the RD can
 * remove it.
 *
 * An incoming document's own uploader may delete it themselves any time
 * before it's been routed (manually or auto-forwarded). Once routed, only
 * the RD can remove it.
 */
class DocumentDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;
    private User $rd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');
    }

    private function makeOutgoingDocument(User $uploader, DocumentStatus $status): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Test memo',
            'uploaded_by' => $uploader->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/2026/07/test.pdf', 'file_hash' => hash('sha256', 'test'),
            'status' => $status,
        ]);
    }

    private function makeIncomingDocument(User $uploader, DocumentStatus $status): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Incoming memo',
            'uploaded_by' => $uploader->id, 'file_path' => 'incoming/2026/07/test.pdf',
            'file_hash' => hash('sha256', 'test'), 'status' => $status,
        ]);
    }

    public function test_rd_can_always_delete_an_outgoing_document(): void
    {
        $originator = User::factory()->create(['division_id' => $this->division->id]);
        $originator->assignRole('originator');
        $document = $this->makeOutgoingDocument($originator, DocumentStatus::Signed);

        $this->assertTrue($this->rd->can('delete', $document));

        $this->actingAs($this->rd)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_originator_can_delete_their_own_upload_before_anyone_reviews_it(): void
    {
        $originator = User::factory()->create(['division_id' => $this->division->id]);
        $originator->assignRole('originator');
        $document = $this->makeOutgoingDocument($originator, DocumentStatus::PendingUnitHeadReview);

        $this->assertTrue($originator->can('delete', $document));

        $this->actingAs($originator)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_originator_can_still_delete_after_unit_head_and_division_chief_have_reviewed(): void
    {
        $originator = User::factory()->create(['division_id' => $this->division->id]);
        $originator->assignRole('originator');
        $unitHead = User::factory()->create(['division_id' => $this->division->id]);
        $unitHead->assignRole('unit_head');

        // Sitting with the ARD now — unit head and division chief already
        // acted, but the RD (the only one who's final) hasn't approved yet.
        $document = $this->makeOutgoingDocument($originator, DocumentStatus::PendingArdReview);
        DocumentReview::create([
            'document_id' => $document->id, 'reviewer_id' => $unitHead->id,
            'role_at_review' => 'unit_head', 'action' => ReviewAction::ApprovedForwarded,
        ]);

        $this->assertTrue($originator->can('delete', $document));

        $this->actingAs($originator)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_originator_cannot_delete_once_approved(): void
    {
        $originator = User::factory()->create(['division_id' => $this->division->id]);
        $originator->assignRole('originator');
        $document = $this->makeOutgoingDocument($originator, DocumentStatus::Approved);

        $this->assertFalse($originator->can('delete', $document));

        $this->actingAs($originator)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_originator_cannot_delete_once_signed_or_released(): void
    {
        foreach ([DocumentStatus::PendingSignature, DocumentStatus::Signed, DocumentStatus::Released] as $status) {
            $originator = User::factory()->create(['division_id' => $this->division->id]);
            $originator->assignRole('originator');
            $document = $this->makeOutgoingDocument($originator, $status);

            $this->assertFalse($originator->can('delete', $document), "should not be deletable at status {$status->value}");
        }
    }

    public function test_division_chief_can_delete_their_own_upload_before_ard_acts(): void
    {
        $chief = User::factory()->create(['division_id' => $this->division->id]);
        $chief->assignRole('division_chief');

        // A chief's own upload skips straight to ARD review.
        $document = $this->makeOutgoingDocument($chief, DocumentStatus::PendingArdReview);

        $this->assertTrue($chief->can('delete', $document));

        $this->actingAs($chief)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_division_chief_can_still_delete_after_ard_has_reviewed_their_own_upload(): void
    {
        $chief = User::factory()->create(['division_id' => $this->division->id]);
        $chief->assignRole('division_chief');
        $ard = User::factory()->create();
        $ard->assignRole('ard');

        $document = $this->makeOutgoingDocument($chief, DocumentStatus::PendingRdReview);
        DocumentReview::create([
            'document_id' => $document->id, 'reviewer_id' => $ard->id,
            'role_at_review' => 'ard', 'action' => ReviewAction::ApprovedForwarded,
        ]);

        $this->assertTrue($chief->can('delete', $document));
    }

    public function test_a_different_user_cannot_delete_someone_elses_outgoing_upload(): void
    {
        $originator = User::factory()->create(['division_id' => $this->division->id]);
        $originator->assignRole('originator');
        $document = $this->makeOutgoingDocument($originator, DocumentStatus::PendingUnitHeadReview);

        $outsider = User::factory()->create(['division_id' => $this->division->id]);
        $outsider->assignRole('originator');

        $this->assertFalse($outsider->can('delete', $document));
    }

    public function test_uploader_can_delete_their_own_incoming_document_before_it_is_routed(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->division->id]);
        $secretary->assignRole('originator');
        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Received);

        $this->assertTrue($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_uploader_cannot_delete_their_incoming_document_once_routed(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->division->id]);
        $secretary->assignRole('originator');
        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $this->assertFalse($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    /**
     * A cancelled routing (see DocumentRoutingPolicy::cancel()) doesn't
     * count as "already routed" anywhere else in this codebase (route(),
     * forActionScope()) — delete() gets the same treatment: if the only
     * real routing(s) on the document have all been cancelled, the
     * uploader can still delete it, same as if it had never been routed.
     */
    public function test_uploader_can_delete_their_incoming_document_once_its_only_routing_is_cancelled(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->division->id]);
        $secretary->assignRole('originator');
        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
            'cancelled_at' => now(), 'cancelled_by' => $this->rd->id,
        ]);

        $this->assertTrue($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    /**
     * The cancelled-routing exclusion only frees up deletion once every
     * real routing is resolved — an active routing alongside a cancelled
     * one still blocks it, since the document is genuinely routed right now.
     */
    public function test_uploader_still_cannot_delete_if_an_active_routing_remains_alongside_a_cancelled_one(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->division->id]);
        $secretary->assignRole('originator');
        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
            'cancelled_at' => now(), 'cancelled_by' => $this->rd->id,
        ]);
        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $this->assertFalse($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_rd_can_always_delete_an_incoming_document(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->division->id]);
        $secretary->assignRole('originator');
        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $this->assertTrue($this->rd->can('delete', $document));

        $this->actingAs($this->rd)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_a_different_user_cannot_delete_someone_elses_incoming_upload(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->division->id]);
        $secretary->assignRole('originator');
        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Received);

        $outsider = User::factory()->create(['division_id' => $this->division->id]);
        $outsider->assignRole('originator');

        $this->assertFalse($outsider->can('delete', $document));
    }

    /**
     * The RD's own (ORD Proper) secretary — every incoming document she
     * uploads is auto-forwarded to Records Section the instant it's created
     * (IncomingAutoForwarder, triggered by IncomingDocumentController::store()
     * for the 'ORDP' division code). That auto-forward routing must not count
     * as "already routed" for delete() purposes — same exclusion already
     * applied in DocumentPolicy::route()/DocumentScopesTrait::forActionScope() —
     * or she could never delete a mistaken upload at all.
     */
    public function test_ord_secretary_can_delete_her_own_incoming_upload_despite_the_auto_forward_routing(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordProper = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);

        $secretary = User::factory()->create(['division_id' => $ordProper->id]);
        $secretary->assignRole('secretary');

        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $secretary->id,
            'is_rush' => false, 'routed_at' => now(),
            'remarks' => \App\Http\Controllers\IncomingDocumentController::AUTO_FORWARD_REMARKS,
        ]);

        $this->assertTrue($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    /**
     * Unlike any other uploader, the RD's own secretary may delete an
     * incoming document she uploaded even after a *real* routing exists on
     * it — including one she issued herself (e.g. she routed it to the wrong
     * division/person and wants to undo the whole thing rather than just
     * cancel-and-reroute) — because she acts as the RD's delegate on her own
     * uploads, mirroring the RD's own unconditional delete power.
     */
    public function test_ord_secretary_can_delete_her_incoming_upload_even_after_she_routed_it_herself(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordProper = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);

        $secretary = User::factory()->create(['division_id' => $ordProper->id]);
        $secretary->assignRole('secretary');

        $document = $this->makeIncomingDocument($secretary, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $secretary->id,
            'is_rush' => false, 'routed_at' => now(),
            'remarks' => \App\Http\Controllers\IncomingDocumentController::AUTO_FORWARD_REMARKS,
        ]);
        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $secretary->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $this->assertTrue($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    /**
     * Real reported case: Records Section uploads an incoming document, the
     * RD's secretary routes it and then cancels that routing (e.g. wrong
     * recipient), and needs to delete the document itself — but she isn't
     * the uploader, so the "own upload" exception above doesn't apply. She
     * still gets delete rights here because she's the one driving the
     * routing/cancel lifecycle for incoming mail on the RD's behalf, and
     * once the only routing is cancelled there's nothing left blocking it.
     */
    public function test_ord_secretary_can_delete_someone_elses_incoming_upload_once_its_only_routing_is_cancelled(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordProper = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);

        $secretary = User::factory()->create(['division_id' => $ordProper->id]);
        $secretary->assignRole('secretary');

        $recordsClerk = User::factory()->create(['division_id' => $this->division->id]);
        $recordsClerk->assignRole('originator');

        $document = $this->makeIncomingDocument($recordsClerk, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $secretary->id,
            'is_rush' => false, 'routed_at' => now(),
            'cancelled_at' => now(), 'cancelled_by' => $secretary->id,
        ]);

        $this->assertTrue($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    /**
     * The secretary's routing-lifecycle-based delete right on someone
     * else's upload is NOT the RD's fully unconditional power — an active
     * (non-cancelled) real routing still blocks her, same as it would the
     * actual uploader.
     */
    public function test_ord_secretary_cannot_delete_someone_elses_incoming_upload_while_a_routing_is_still_active(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordProper = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);

        $secretary = User::factory()->create(['division_id' => $ordProper->id]);
        $secretary->assignRole('secretary');

        $recordsClerk = User::factory()->create(['division_id' => $this->division->id]);
        $recordsClerk->assignRole('originator');

        $document = $this->makeIncomingDocument($recordsClerk, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $secretary->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $this->assertFalse($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    /**
     * The unconditional exception is specific to the RD's own secretary —
     * an ordinary uploader (e.g. Records Section, who can also upload
     * incoming documents) is still blocked once a real routing exists,
     * matching test_uploader_cannot_delete_their_incoming_document_once_routed
     * above.
     */
    public function test_non_secretary_uploader_still_cannot_delete_once_a_real_routing_exists(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordProper = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);

        $uploader = User::factory()->create(['division_id' => $ordProper->id]);
        $uploader->assignRole('originator');

        $document = $this->makeIncomingDocument($uploader, DocumentStatus::Routed);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $this->assertFalse($uploader->can('delete', $document));

        $this->actingAs($uploader)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    /**
     * The RD's secretary's own outgoing uploads already skip straight to
     * PendingArdReview/PendingRdReview (never Approved) per
     * OutgoingDocumentController::initialStatus()'s ORD-secretary branch, so
     * delete() already permits this without any change — this test just
     * documents/locks in that existing behavior alongside the incoming fix
     * above, since the request that prompted this fix asked for both.
     */
    public function test_ord_secretary_can_delete_her_own_outgoing_upload_before_approval(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordProper = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);

        $secretary = User::factory()->create(['division_id' => $ordProper->id]);
        $secretary->assignRole('secretary');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo from the secretary',
            'uploaded_by' => $secretary->id, 'division_id' => $ordProper->id,
            'file_path' => 'outgoing/2026/07/test.pdf', 'file_hash' => hash('sha256', 'test'),
            'status' => DocumentStatus::PendingRdReview,
        ]);

        $this->assertTrue($secretary->can('delete', $document));

        $this->actingAs($secretary)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }
}
