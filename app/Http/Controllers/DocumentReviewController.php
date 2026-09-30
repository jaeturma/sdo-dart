<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Http\Requests\ReviewDocumentRequest;
use App\Models\AsdsAvailabilitySetting;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentReview;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentReviewController extends Controller
{
    public function store(ReviewDocumentRequest $request, Document $document): RedirectResponse
    {
        $user = $request->user();

        // Derive the reviewer role for the role_at_review snapshot.
        // Authorization is already enforced by ReviewDocumentRequest via the review policy.
        $requiredRole = match ($document->status) {
            DocumentStatus::PendingUnitHeadReview => 'unit_head',
            DocumentStatus::Submitted,
            DocumentStatus::PendingDivisionChiefReview => 'division_chief',
            DocumentStatus::PendingAsdsReview => 'asds',
            DocumentStatus::PendingSdsReview => 'sds',
            default => abort(403),
        };

        // The SDS may submit remarks with no action selected at all (see
        // ReviewDocumentRequest::rules(), which only relaxes the
        // action-required rule for this exact case) — her remarks alone
        // already imply "send it back", same as the Approve-plus-remarks
        // override just below, so there's nothing left to pick.
        $action = $requiredRole === 'sds' && ! $request->filled('action')
            ? ReviewAction::ReturnedForRevision
            : ReviewAction::from($request->action);

        // The SDS's own remarks always win over an Approve selection at her
        // stage — if she's typed anything, she means to send it back, not
        // approve it, even if Approve is what's selected. Scoped strictly to
        // her own PendingSdsReview stage and only when Approve was picked —
        // 'for_comment'/'rejected' are already deliberate alternate outcomes
        // and are left alone; other review stages (unit_head/division_chief/
        // asds) are unaffected.
        if ($requiredRole === 'sds'
            && $action === ReviewAction::ApprovedForwarded
            && trim((string) $request->remarks) !== '') {
            $action = ReviewAction::ReturnedForRevision;
        }

        $nextStatus = match ($action) {
            ReviewAction::ApprovedForwarded => match ($requiredRole) {
                'unit_head' => $this->nextStatusAfterUnitHead($document),
                'division_chief' => AsdsAvailabilitySetting::nextAfterDivisionChief(),
                'asds' => DocumentStatus::PendingSdsReview,
                'sds' => DocumentStatus::Approved,
            },
            ReviewAction::ForComment => DocumentStatus::ForComment,
            ReviewAction::ReturnedForRevision => DocumentStatus::ReturnedForRevision,
            ReviewAction::Rejected => DocumentStatus::Rejected,
        };

        DB::transaction(function () use ($user, $document, $action, $requiredRole, $nextStatus, $request) {
            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => $requiredRole,
                'action' => $action,
                'remarks' => $request->remarks,
            ]);

            $document->update(['status' => $nextStatus]);

            AuditLogger::log($user, $document, 'review.'.$action->value, $request, [
                'role_at_review' => $requiredRole,
                'next_status' => $nextStatus->value,
            ]);

            NotificationDispatcher::reviewDecision($document, $action);
        });

        // The SDS goes back to her "For Approval" queue rather than the
        // document itself, so she can keep working through the rest of it —
        // other reviewers still land on the document (e.g. to see it move to
        // the next stage).
        if ($user->hasRole('sds')) {
            return redirect()->route('documents.index', ['tab' => 'for_approval'])
                ->with('success', 'Review submitted.');
        }

        return redirect()->route('documents.show', $document)
            ->with('success', 'Review submitted.');
    }

    /**
     * The secretary's manual override for an Approved document whose SDS
     * review left remarks that were never acted on (see
     * DocumentPolicy::returnToOwner() for exactly which documents qualify
     * and why this exists). Recorded as its own append-only review row —
     * role_at_review 'secretary' matches the existing precedent for
     * secretary-initiated status changes (see DocumentSignatureController::
     * destroy()'s SignatureReverted) — rather than editing/backdating the
     * SDS's own original review row.
     */
    public function returnToOwner(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('returnToOwner', $document);

        $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        $user = $request->user();

        DB::transaction(function () use ($user, $document, $request) {
            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => 'secretary',
                'action' => ReviewAction::ReturnedForRevision,
                'remarks' => $request->remarks,
            ]);

            $document->update(['status' => DocumentStatus::ReturnedForRevision]);

            AuditLogger::log($user, $document, 'review.returned_for_revision', $request, [
                'role_at_review' => 'secretary',
            ]);

            NotificationDispatcher::reviewDecision($document, ReviewAction::ReturnedForRevision);
        });

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document returned to the owner for revision.');
    }

    /**
     * The secretary's general QC gate on an Approved document — missing
     * pages, wrong format, etc. — found while preparing to sign it. See
     * DocumentPolicy::returnForCorrection() for how this differs from
     * returnToOwner() above. Remarks are required here (unlike
     * returnToOwner()'s optional note) since, without an SDS comment already
     * on record to point to, the originator needs an explicit explanation
     * of what to fix.
     */
    public function returnForCorrection(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('returnForCorrection', $document);

        $request->validate(['remarks' => ['required', 'string', 'max:2000']]);

        $user = $request->user();

        DB::transaction(function () use ($user, $document, $request) {
            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => 'secretary',
                'action' => ReviewAction::ReturnedForRevision,
                'remarks' => $request->remarks,
            ]);

            $document->update(['status' => DocumentStatus::ReturnedForRevision]);

            AuditLogger::log($user, $document, 'review.returned_for_revision', $request, [
                'role_at_review' => 'secretary',
                'reason' => 'correction',
            ]);

            NotificationDispatcher::reviewDecision($document, ReviewAction::ReturnedForRevision);
        });

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document returned to the owner for correction.');
    }

    /**
     * The chief above a unit head sits in the document's own division or its
     * parent — the same dual check OutgoingDocumentController::initialStatus()
     * and DocumentPolicy::review() use. Checking only the parent would skip a
     * chief who shares a top-level division (e.g. CID) with the unit head.
     */
    private function nextStatusAfterUnitHead(Document $document): DocumentStatus
    {
        $parentId = Division::where('id', $document->division_id)->value('parent_id');
        $divisionIds = array_filter([$document->division_id, $parentId]);

        $hasChief = ! empty($divisionIds) && User::whereHas('roles', fn ($q) => $q->where('name', 'division_chief'))
            ->whereIn('division_id', $divisionIds)
            ->exists();

        return $hasChief
            ? DocumentStatus::PendingDivisionChiefReview
            : AsdsAvailabilitySetting::nextAfterDivisionChief();
    }
}
