<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\RecipientStatus;
use App\Http\Requests\AcknowledgeRecipientRequest;
use App\Http\Requests\CompleteRecipientRequest;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentRoutingRecipientController extends Controller
{
    public function acknowledge(AcknowledgeRecipientRequest $request, DocumentRoutingRecipient $recipient): RedirectResponse
    {
        $recipient->load('routing.document');
        $document = $recipient->routing->document;
        $user = $request->user();
        $now = now();

        // A division chief (or a unit head whose division is flagged
        // allow_sds_direct_routing — e.g. ICTU/LU/PAU, who the SDS routes to
        // directly the same way she routes to chiefs) who acknowledges an
        // incoming document may still need to route it onward to their own
        // staff — auto-completing here would flip the whole document to
        // Completed (once no pending recipients remain) and hide the "Route
        // Document" option before they ever get to use it. Everyone else has
        // no such follow-up decision to make, so for them acknowledging and
        // completing happen together in this one step.
        if (($user->hasRole('division_chief') || $this->isDirectRoutableUnitHead($user)) && $user->division_id !== null) {
            $recipient->update([
                'status' => RecipientStatus::Acknowledged,
                'acknowledged_at' => $now,
                'acknowledged_by' => $user->id,
                'response_remarks' => $request->response_remarks,
            ]);

            AuditLogger::log($user, $document, 'recipient.acknowledged', $request);

            return redirect()->back()->with('success', 'Document acknowledged.');
        }

        DB::transaction(function () use ($request, $recipient, $document, $now, $user) {
            $recipient->update([
                'status' => RecipientStatus::Completed,
                'acknowledged_at' => $now,
                'acknowledged_by' => $user->id,
                'completed_at' => $now,
                'completed_by' => $user->id,
                'response_remarks' => $request->response_remarks,
            ]);

            AuditLogger::log($user, $document, 'recipient.acknowledged', $request);
            AuditLogger::log($user, $document, 'recipient.completed', $request);

            self::promoteDocumentIfFullyCompleted($document, $user, $request);
        });

        return redirect()->back()->with('success', 'Document acknowledged and marked complete.');
    }

    /**
     * A unit head whose division has been admin-flagged
     * allow_sds_direct_routing (e.g. ICTU/LU/PAU, or any other unit opted in
     * via Office Management) — same identification as
     * DocumentPolicy::isDirectRoutableUnitHead() (kept as a separate private
     * copy here rather than shared, matching how isOsdsSecretary() is
     * duplicated per-class elsewhere in this codebase rather than shared).
     */
    private function isDirectRoutableUnitHead(User $user): bool
    {
        if (! $user->hasRole('unit_head') || $user->division_id === null) {
            return false;
        }

        return (bool) $user->division?->allow_sds_direct_routing;
    }

    /**
     * The deferred path for a division chief (or a directly-routable unit
     * head) who acknowledged but decided to handle the assignment
     * themselves rather than route it to staff.
     */
    public function complete(CompleteRecipientRequest $request, DocumentRoutingRecipient $recipient): RedirectResponse
    {
        $recipient->load('routing.document');
        $document = $recipient->routing->document;
        $user = $request->user();

        DB::transaction(function () use ($request, $recipient, $document, $user) {
            $recipient->update([
                'status' => RecipientStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $user->id,
                'response_remarks' => $request->response_remarks ?? $recipient->response_remarks,
            ]);

            AuditLogger::log($user, $document, 'recipient.completed', $request);

            self::promoteDocumentIfFullyCompleted($document, $user, $request);
        });

        return redirect()->back()->with('success', 'Action marked as complete.');
    }

    /**
     * Undo one recipient's assignment within a routing round without
     * touching its siblings — e.g. a routing meant for one chief was
     * mistakenly sent to two, and only the wrong one should be withdrawn.
     * See DocumentRoutingRecipientPolicy::cancel() for the permission
     * rules; contrast with DocumentRoutingController::cancel(), which
     * withdraws every recipient under a routing round at once.
     */
    public function cancel(Request $request, DocumentRoutingRecipient $recipient): RedirectResponse
    {
        $this->authorize('cancel', $recipient);

        $recipient->load('routing.document');
        $routing = $recipient->routing;
        $document = $routing->document;
        $user = $request->user();

        DB::transaction(function () use ($request, $recipient, $routing, $document, $user) {
            $recipient->update(['status' => RecipientStatus::Cancelled]);

            // If every recipient under this routing round is now resolved,
            // the round itself is effectively withdrawn too — mirroring
            // DocumentRoutingController::cancel() so the router regains
            // their once-each routing turn (DocumentPolicy::route() keys
            // off DocumentRouting::cancelled_at) instead of staying
            // permanently blocked from routing this document again.
            $anyStillActive = $routing->recipients()
                ->where('status', '!=', RecipientStatus::Cancelled)
                ->exists();

            if (! $anyStillActive) {
                $routing->update([
                    'cancelled_at' => now(),
                    'cancelled_by' => $user->id,
                ]);

                // Undo store()'s side effect: this routing auto-completed
                // the router's own prior assignment when it was issued —
                // revert it since the round it came from is now fully
                // withdrawn. See DocumentRoutingController::cancel() for
                // the identical whole-routing-round version of this.
                DocumentRoutingRecipient::where('auto_completed_by_routing_id', $routing->id)
                    ->update([
                        'status' => RecipientStatus::Acknowledged,
                        'completed_at' => null,
                        'completed_by' => null,
                        'auto_completed_by_routing_id' => null,
                    ]);
            }

            // The SDS/secretary's unconditional cancel means this recipient
            // may have already been Completed — which, if it was the
            // document's last outstanding recipient, would have already
            // promoted the whole document to Completed. Cancelling implies
            // that isn't actually final, so reopen it — otherwise
            // DocumentPolicy::route()'s status guard would still hide
            // "Route Document" even after a successful cancel.
            if ($document->status === DocumentStatus::Completed) {
                $document->update(['status' => DocumentStatus::Routed]);
            }

            AuditLogger::log($user, $document, 'recipient.cancelled', $request, [
                'recipient_id' => $recipient->id,
                'routing_id' => $routing->id,
            ]);
        });

        return redirect()->back()->with('success', 'That recipient\'s routing was cancelled.');
    }

    private static function promoteDocumentIfFullyCompleted($document, $user, $request): void
    {
        // The Records Section auto-forward (see IncomingAutoForwarder) is a
        // courtesy copy — Records Section acknowledging "we've seen it" is
        // not the same as the document's actual dispatch-and-action workflow
        // being done. Promoting to Completed based on that alone would flip
        // the document's status before the SDS/her secretary ever routes it
        // to a division chief, and DocumentPolicy::route()'s status guard
        // would then hide the "Route Document" option entirely — nobody
        // could route it to chiefs anymore. So don't even consider promoting
        // until at least one real (non-auto-forward) routing exists.
        $hasRealRouting = DocumentRoutingRecipient::whereHas('routing', fn ($q) => $q->where('document_id', $document->id)
            ->where(fn ($q2) => $q2->whereNull('remarks')
                ->orWhere('remarks', '!=', IncomingDocumentController::AUTO_FORWARD_REMARKS)))
            ->exists();

        if (! $hasRealRouting) {
            return;
        }

        // If every recipient across all routings for this document is now completed,
        // promote the document to completed status.
        // Cancelled counts as resolved too — a routing that was withdrawn (see
        // DocumentRoutingPolicy::cancel()) should never permanently block a
        // document from reaching Completed.
        $pendingExists = DocumentRoutingRecipient::whereHas(
            'routing',
            fn ($q) => $q->where('document_id', $document->id),
        )->whereNotIn('status', [RecipientStatus::Completed->value, RecipientStatus::Cancelled->value])
            ->exists();

        if (! $pendingExists) {
            $document->update(['status' => DocumentStatus::Completed]);
            AuditLogger::log($user, $document, 'document.completed', $request);
            NotificationDispatcher::documentCompleted($document);
        }
    }
}
