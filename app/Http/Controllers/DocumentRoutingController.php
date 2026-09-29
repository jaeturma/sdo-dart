<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\RecipientStatus;
use App\Enums\RoutingAction;
use App\Http\Requests\RouteDocumentRequest;
use App\Models\Document;
use App\Models\DocumentRouting;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use App\Services\RoutingCandidateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DocumentRoutingController extends Controller
{
    private const ROLE_LABELS = [
        'ard' => 'Assistant Regional Director',
        'division_chief' => 'Division Chief',
        'unit_head' => 'Unit / Section Head',
        'originator' => 'Staff',
        'detailed_staff' => 'Detailed Official',
    ];

    public function create(Request $request, Document $document): Response
    {
        // DocumentPolicy::route() reads $document->routings/->recipients as
        // already-loaded relations (not fresh queries) — load them here so
        // this single-document authorize check still resolves in a bounded
        // number of queries instead of relying on N+1-prone lazy loading.
        $document->loadMissing('routings.recipients');
        $this->authorize('route', $document);

        $users = $this->candidateUsers($request->user());

        return Inertia::render('Documents/Route', compact('document', 'users'));
    }

    /**
     * Same candidate list as create(), as JSON — used to populate the routing
     * form when it's opened as a modal on top of the document preview instead
     * of navigating to the dedicated /route page.
     */
    public function candidates(Request $request, Document $document): JsonResponse
    {
        $document->loadMissing('routings.recipients');
        $this->authorize('route', $document);

        return response()->json(['users' => $this->candidateUsers($request->user())]);
    }

    /**
     * The eligibility logic itself (who's a valid candidate) lives in
     * RoutingCandidateResolver, shared with RouteDocumentRequest's
     * server-side validation so the two can never drift apart — this method
     * only adds the display-shape mapping for the Vue frontend.
     */
    private function candidateUsers(User $user): Collection
    {
        return RoutingCandidateResolver::forUser($user)->map(function ($u) {
            // Exclude 'kmt_focal' — it's an additional role layered on top of
            // a primary one (see DocumentPolicy::createOutgoing()), never the
            // role that should label a candidate here.
            $primaryRole = $u->getRoleNames()->reject(fn ($r) => $r === 'kmt_focal')->first();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $primaryRole,
                'subtitle' => self::ROLE_LABELS[$primaryRole] ?? '',
                'division' => $u->division?->name,
            ];
        })->values();
    }

    /**
     * The RD's own secretary — same identification as
     * DocumentPolicy::isOrdSecretary() (kept as a separate private copy here
     * rather than shared, matching how isDirectRoutableUnitHead() is
     * duplicated per-class elsewhere in this codebase rather than shared).
     */
    private function isOrdSecretary(User $user): bool
    {
        if (! $user->hasRole('secretary') || $user->division_id === null) {
            return false;
        }

        return $user->division?->code === 'ORD' || $user->division?->parent?->code === 'ORD';
    }

    public function store(RouteDocumentRequest $request, Document $document): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $document, $user) {
            $action = RoutingAction::from($request->action);

            $routing = DocumentRouting::create([
                'document_id' => $document->id,
                'routed_by' => $user->id,
                'is_rush' => $request->boolean('is_rush'),
                'remarks' => $request->remarks,
                'routed_at' => now(),
                // Stamped now, at creation time, rather than re-derived later
                // from $user's role/division — those can change (e.g. a
                // secretary later transferred to a different division), and
                // this fact must not change retroactively with them. See
                // DocumentPolicy::route()'s matching use of this column.
                'is_rd_routing' => $user->hasRole('rd') || $this->isOrdSecretary($user),
            ]);

            foreach ($request->user_ids as $userId) {
                DocumentRoutingRecipient::create([
                    'routing_id' => $routing->id,
                    'user_id' => $userId,
                    'action' => $action,
                    'action_other' => $request->action_other ?? null,
                    'status' => RecipientStatus::Pending,
                ]);
            }

            // If the person routing this onward was themselves a recipient
            // (e.g. a division chief who acknowledged, then delegated to
            // their own staff instead of completing it personally), their
            // own part is done — close out their row too. Otherwise it would
            // sit at Acknowledged forever and the document could never reach
            // Completed once every delegated recipient finishes their part.
            DocumentRoutingRecipient::whereHas('routing', fn ($q) => $q->where('document_id', $document->id))
                ->where('status', RecipientStatus::Acknowledged)
                ->where(fn ($q) => $q->where('user_id', $user->id)
                    ->when($user->division_id, fn ($q2) => $q2->orWhere('division_id', $user->division_id)))
                ->update([
                    'status' => RecipientStatus::Completed,
                    'completed_at' => now(),
                    'completed_by' => $user->id,
                    'auto_completed_by_routing_id' => $routing->id,
                ]);

            $document->update(['status' => DocumentStatus::Routed]);

            AuditLogger::log($user, $document, 'document.routed', $request, [
                'recipient_count' => count($request->user_ids),
                'is_rush' => $request->boolean('is_rush'),
            ]);

            NotificationDispatcher::documentRouted($routing);
        });

        // Back to a list (not the document itself) so the RD/ARD can keep
        // working through their remaining pending documents. The RD gets her
        // own "For Routing" tab (incoming only) since she has a separate
        // "For Approval" tab for outgoing — everyone else shares the combined
        // "For Action" tab.
        $tab = $user->hasRole('rd') ? 'for_routing' : 'for_action';

        return redirect()->route('documents.index', ['tab' => $tab])
            ->with('success', 'Document routed successfully.');
    }

    /**
     * Undo a routing round issued by mistake (e.g. wrong staff selected).
     * A division chief may only do this while every one of its recipients
     * is still Pending; the RD/her secretary may do this anytime, even after
     * the recipient has acknowledged or fully completed it (see
     * DocumentRoutingPolicy::cancel()).
     */
    public function cancel(Request $request, Document $document, DocumentRouting $routing): RedirectResponse
    {
        $this->authorize('cancel', $routing);

        $user = $request->user();

        DB::transaction(function () use ($request, $document, $routing, $user) {
            $routing->recipients()->update(['status' => RecipientStatus::Cancelled]);

            $routing->update([
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ]);

            // Undo store()'s side effect: this routing auto-completed the
            // chief's own assignment when it was (mistakenly) issued — revert
            // it to Acknowledged so the document re-enters their action queue
            // and they can route it again correctly.
            DocumentRoutingRecipient::where('auto_completed_by_routing_id', $routing->id)
                ->update([
                    'status' => RecipientStatus::Acknowledged,
                    'completed_at' => null,
                    'completed_by' => null,
                    'auto_completed_by_routing_id' => null,
                ]);

            // The RD/secretary's unconditional cancel means this routing's
            // recipient may have already been Completed — which, if it was
            // the document's last outstanding recipient, would have already
            // promoted the whole document to Completed (see
            // DocumentRoutingRecipientController::promoteDocumentIfFullyCompleted()).
            // Cancelling implies the document isn't actually done and needs
            // routing again, so reopen it — otherwise DocumentPolicy::route()'s
            // status guard (Received/PendingRouting/Routed only) would still
            // hide "Route Document" even after a successful cancel. A no-op
            // for the division-chief case, which can never reach Completed
            // status while still cancellable.
            if ($document->status === DocumentStatus::Completed) {
                $document->update(['status' => DocumentStatus::Routed]);
            }

            AuditLogger::log($user, $document, 'document.routing_cancelled', $request, [
                'routing_id' => $routing->id,
            ]);
        });

        return redirect()->route('documents.show', $document)
            ->with('success', 'Routing cancelled — you can route the document again.');
    }

    /**
     * The RD's ORD secretary files a Records-Section-uploaded incoming
     * document directly, skipping routing entirely — for documents that
     * don't need to reach any division chief. See DocumentPolicy::archive()
     * for exactly which documents qualify. Deliberately creates no
     * DocumentRouting/DocumentRoutingRecipient rows — that's the whole
     * point of this action, as opposed to store() above.
     */
    public function archive(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('archive', $document);

        $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        $user = $request->user();

        $document->update(['status' => DocumentStatus::Archived]);

        AuditLogger::log($user, $document, 'document.archived', $request, [
            'remarks' => $request->remarks,
        ]);

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document filed — no routing needed.');
    }
}
