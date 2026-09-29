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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The RD's own remarks always win over an "Approved" selection at her final
 * approval stage (PendingRdReview) — if she's typed anything into remarks,
 * the document is returned for revision instead, even though she picked
 * Approve. Only genuinely empty remarks lets an Approve selection actually
 * approve. Scoped strictly to her own stage and only when Approve was
 * picked — 'for_comment'/'rejected' are already deliberate alternate
 * outcomes and are untouched, and other review stages (unit_head/
 * division_chief/ard) are unaffected.
 */
class RdReviewRemarksOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $rd;

    private User $originator;

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

        $this->originator = User::factory()->create(['division_id' => $this->division->id]);
        $this->originator->assignRole('originator');
    }

    private function pendingRdReviewDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Ready for RD',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::PendingRdReview,
        ]);
    }

    public function test_approve_with_remarks_is_forced_to_returned_for_revision(): void
    {
        $document = $this->pendingRdReviewDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'approved_forwarded',
                'remarks' => 'Please fix section 2 before this can be approved.',
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_approval']));

        $document->refresh();
        $this->assertSame(DocumentStatus::ReturnedForRevision, $document->status);

        $review = DocumentReview::where('document_id', $document->id)->latest('id')->first();
        $this->assertSame(ReviewAction::ReturnedForRevision, $review->action);
        $this->assertSame('Please fix section 2 before this can be approved.', $review->remarks);
    }

    public function test_approve_with_empty_remarks_approves_normally(): void
    {
        $document = $this->pendingRdReviewDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'approved_forwarded',
                'remarks' => '',
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_approval']));

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_approve_with_whitespace_only_remarks_approves_normally(): void
    {
        $document = $this->pendingRdReviewDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'approved_forwarded',
                'remarks' => "   \n  ",
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_approval']));

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_for_comment_with_remarks_is_not_coerced_to_returned_for_revision(): void
    {
        $document = $this->pendingRdReviewDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'for_comment',
                'remarks' => 'Just a comment, not a return.',
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_approval']));

        $this->assertSame(DocumentStatus::ForComment, $document->fresh()->status);
    }

    public function test_rejected_with_remarks_is_not_coerced_to_returned_for_revision(): void
    {
        $document = $this->pendingRdReviewDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'rejected',
                'remarks' => 'This cannot proceed at all.',
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_approval']));

        $this->assertSame(DocumentStatus::Rejected, $document->fresh()->status);
    }

    public function test_rd_can_submit_remarks_with_no_action_selected(): void
    {
        $document = $this->pendingRdReviewDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), [
                'remarks' => 'Send this back, please revise the budget section.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('documents.index', ['tab' => 'for_approval']));

        $document->refresh();
        $this->assertSame(DocumentStatus::ReturnedForRevision, $document->status);

        $review = DocumentReview::where('document_id', $document->id)->latest('id')->first();
        $this->assertSame(ReviewAction::ReturnedForRevision, $review->action);
        $this->assertSame('Send this back, please revise the budget section.', $review->remarks);
    }

    public function test_rd_submitting_with_no_action_and_no_remarks_still_fails_validation(): void
    {
        $document = $this->pendingRdReviewDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), [
                'remarks' => '',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(DocumentStatus::PendingRdReview, $document->fresh()->status);
    }

    public function test_division_chief_without_an_action_still_requires_one(): void
    {
        $chief = User::factory()->create(['division_id' => $this->division->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Ready for chief, no action',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/c.pdf', 'file_hash' => hash('sha256', 'c'),
            'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->actingAs($chief)
            ->post(route('documents.reviews.store', $document), [
                'remarks' => 'Some remarks but no action picked.',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(DocumentStatus::PendingDivisionChiefReview, $document->fresh()->status);
    }

    public function test_division_chief_approving_with_remarks_is_unaffected(): void
    {
        $chief = User::factory()->create(['division_id' => $this->division->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Ready for chief',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/b.pdf', 'file_hash' => hash('sha256', 'b'),
            'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->actingAs($chief)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'approved_forwarded',
                'remarks' => 'Looks good, forwarding.',
            ])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertNotSame(DocumentStatus::ReturnedForRevision, $document->status);
    }
}
