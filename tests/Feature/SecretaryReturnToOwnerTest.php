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
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DocumentReviewController::store()'s "RD's remarks always win" rule (see
 * RdReviewRemarksOverrideTest) only guards *new* reviews going forward.
 * Documents approved before that rule existed can still be sitting in the
 * secretary's signing queue (Approved status) with an RD comment that was
 * effectively ignored. This lets the secretary — the next person to
 * actually look at the document — catch that and send it back to the
 * owner herself instead of silently signing over it.
 */
class SecretaryReturnToOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;
    private User $rd;
    private User $secretary;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->secretary = User::factory()->create();
        $this->secretary->assignRole('secretary');

        $this->originator = User::factory()->create(['division_id' => $this->division->id]);
        $this->originator->assignRole('originator');
    }

    private function approvedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Legacy approved memo',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Approved,
        ]);
    }

    /**
     * Simulates data from before the "remarks always win" rule existed: the
     * RD approved despite leaving remarks, which the old code let through.
     */
    private function addRdReviewWithRemarks(Document $document, string $remarks): void
    {
        DocumentReview::create([
            'document_id' => $document->id,
            'reviewer_id' => $this->rd->id,
            'role_at_review' => 'rd',
            'action' => ReviewAction::ApprovedForwarded,
            'remarks' => $remarks,
        ]);
    }

    public function test_secretary_can_return_to_owner_when_rd_left_an_unactioned_comment(): void
    {
        Notification::fake();

        $document = $this->approvedDocument();
        $this->addRdReviewWithRemarks($document, 'Please double-check the budget figures.');

        $this->assertTrue($this->secretary->can('returnToOwner', $document->fresh()->load('reviews')));

        $this->actingAs($this->secretary)
            ->post(route('documents.return-to-owner', $document), ['remarks' => 'Returning per RD\'s note above.'])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::ReturnedForRevision, $document->status);

        $latestReview = DocumentReview::where('document_id', $document->id)->latest('id')->first();
        $this->assertSame('secretary', $latestReview->role_at_review);
        $this->assertSame(ReviewAction::ReturnedForRevision, $latestReview->action);
        $this->assertSame('Returning per RD\'s note above.', $latestReview->remarks);
        $this->assertSame($this->secretary->id, $latestReview->reviewer_id);

        Notification::assertSentTo($this->originator, \App\Notifications\DocumentEvent::class);
    }

    public function test_secretary_cannot_return_to_owner_when_rd_left_no_remarks(): void
    {
        $document = $this->approvedDocument();
        $this->addRdReviewWithRemarks($document, '');

        $this->assertFalse($this->secretary->can('returnToOwner', $document->fresh()->load('reviews')));

        $this->actingAs($this->secretary)
            ->post(route('documents.return-to-owner', $document), [])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_secretary_cannot_return_to_owner_once_signed(): void
    {
        $document = $this->approvedDocument();
        $this->addRdReviewWithRemarks($document, 'Fix the letterhead.');
        $document->update(['status' => DocumentStatus::Signed]);

        $this->assertFalse($this->secretary->can('returnToOwner', $document->fresh()->load('reviews')));

        $this->actingAs($this->secretary)
            ->post(route('documents.return-to-owner', $document), [])
            ->assertForbidden();
    }

    public function test_only_secretary_role_can_return_to_owner(): void
    {
        $document = $this->approvedDocument();
        $this->addRdReviewWithRemarks($document, 'Fix the letterhead.');

        foreach (['rd', 'ard'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertFalse($user->can('returnToOwner', $document->fresh()->load('reviews')));

            $this->actingAs($user)
                ->post(route('documents.return-to-owner', $document), [])
                ->assertForbidden();
        }
    }

    /**
     * Regression guard for the policy's "latest RD review only" scoping: an
     * earlier returned-for-revision review (with remarks) from before a
     * resubmission restarted the whole chain must not be mistaken for the
     * review that produced the document's *current* Approved status. Only
     * the RD review that actually led to today's Approved status counts.
     */
    public function test_an_earlier_review_cycles_remarks_do_not_leak_into_the_current_one(): void
    {
        $document = $this->approvedDocument();

        // First cycle: RD returned it for revision with remarks.
        DocumentReview::create([
            'document_id' => $document->id,
            'reviewer_id' => $this->rd->id,
            'role_at_review' => 'rd',
            'action' => ReviewAction::ReturnedForRevision,
            'remarks' => 'Please fix section 2.',
        ]);

        // Second cycle, after resubmission: RD approved cleanly, no remarks.
        DocumentReview::create([
            'document_id' => $document->id,
            'reviewer_id' => $this->rd->id,
            'role_at_review' => 'rd',
            'action' => ReviewAction::ApprovedForwarded,
            'remarks' => null,
        ]);

        $this->assertFalse($this->secretary->can('returnToOwner', $document->fresh()->load('reviews')));
    }
}
