<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\RecipientStatus;
use App\Models\Division;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Traits\DocumentScopesTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    use DocumentScopesTrait;

    private const TABS = [
        'outgoing', 'incoming', 'for_action', 'for_approval', 'for_routing',
        'records_incoming', 'records_handled', 'ready_to_release', 'released',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true)
            ? $request->query('tab')
            : 'outgoing';
        $search = trim((string) $request->query('search', ''));

        // Built once and cloned below for every tab/count that needs it —
        // forActionScope() re-runs Division::selfAndChildrenIds() sub-queries
        // internally, so calling it repeatedly (once per tab, once per count)
        // re-runs those sub-queries for no reason. forApprovalScope()/
        // forRoutingScope() are just forActionScope() narrowed by type (see
        // DocumentScopesTrait), so a clone + where('type', ...) is identical.
        $forActionBase = $this->forActionScope(Document::query(), $user);

        $query = match ($tab) {
            'incoming' => $this->incomingScope(Document::query(), $user),
            'for_action' => clone $forActionBase,
            'for_approval' => (clone $forActionBase)->where('type', DocumentType::Outgoing),
            'for_routing' => (clone $forActionBase)->where('type', DocumentType::Incoming),
            'records_incoming' => $this->recordsSectionIncomingScope(Document::query(), $user),
            'records_handled' => $this->recordsSectionHandledScope(Document::query(), $user),
            'ready_to_release' => $this->readyToReleaseScope(Document::query(), $user),
            'released' => $this->releasedScope(Document::query(), $user),
            default => $this->outgoingScope(Document::query(), $user),
        };

        // Outgoing now shows every document from the user's own division
        // (see outgoingScope()), not just their own uploads — this lets them
        // narrow back down to just what they personally submitted. Simply
        // ANDs an extra `uploaded_by` constraint on top of whatever
        // outgoingScope() already allowed, rather than replacing the scope,
        // so it can never accidentally reveal a document the user otherwise
        // couldn't see.
        $mine = $tab === 'outgoing' && $request->boolean('mine');

        if ($mine) {
            $query->where('uploaded_by', $user->id);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('control_number', 'like', "%{$search}%")
                    ->orWhere('source_origin', 'like', "%{$search}%")
                    ->orWhereHas('uploader', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('division', fn ($d) => $d->where('name', 'like', "%{$search}%"));
            });
        }

        $documents = $query
            ->with([
                'uploader:id,name',
                'division:id,name,parent_id',
                // Eager-loaded so the can_review/can_route checks below (via
                // DocumentPolicy::review()/route()) read from an already-fetched
                // relation instead of firing 1-2 fresh queries per row.
                'routings:id,document_id,routed_by,is_rd_routing,cancelled_at,remarks',
                'routings.recipients:id,routing_id,user_id,division_id,status',
            ])
            ->withExists(['routings as has_rush_routing' => fn ($q) => $q->where('is_rush', true)])
            ->latest()
            ->paginate(20)
            ->withQueryString()
            // Lets the mobile quick-preview modal offer the same Route/Review
            // actions as the full document page, without a second round trip.
            ->through(function (Document $document) use ($user) {
                $document->setAttribute('can_review', $user->can('review', $document));
                $document->setAttribute('can_route', $user->can('route', $document));

                return $document;
            });

        $forActionCount = (clone $forActionBase)->count();

        $isRd = $user->hasRole('rd');
        // RD's "For Action" is split into two focused lists (For Approval /
        // For Routing) rather than one mixed queue — only computed for RD to
        // avoid two extra counting queries for every other role.
        $forApprovalCount = $isRd ? (clone $forActionBase)->where('type', DocumentType::Outgoing)->count() : null;
        $forRoutingCount = $isRd ? (clone $forActionBase)->where('type', DocumentType::Incoming)->count() : null;

        $canCreateOutgoing = $user->can('createOutgoing', Document::class);
        $canCreateIncoming = $user->can('createIncoming', Document::class);

        $isRecordsSection = $user->hasRole('admin')
            || ($user->division_id !== null && $user->division_id === Division::recordsSectionId());

        return Inertia::render('Documents/Index', compact(
            'documents', 'tab', 'search', 'mine', 'forActionCount', 'canCreateOutgoing', 'canCreateIncoming', 'isRecordsSection',
            'isRd', 'forApprovalCount', 'forRoutingCount',
        ));
    }

    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $document->load([
            // division_id also selected here — DocumentPolicy::archive()'s
            // legacy-document fallback reads $document->uploader->division_id.
            'uploader:id,name,division_id',
            'division:id,name,parent_id',
            'reviews.reviewer:id,name',
            'routings.routedBy:id,name',
            'routings.recipients.user:id,name',
            'routings.recipients.division:id,name',
            'routings.recipients.acknowledgedBy:id,name',
            'routings.recipients.completedBy:id,name',
            'releasedBy:id,name',
            'emailedBy:id,name',
            'auditLogs' => fn ($q) => $q->with('user:id,name')->latest('id')->limit(50),
        ]);

        $user = $request->user();

        $canReview = $user->can('review', $document);
        $canRoute = $user->can('route', $document);
        $canSign = $user->can('sign', $document);
        $canUndoSignature = $user->can('undoSignature', $document);
        $canDelete = $user->can('delete', $document);
        $canResubmit = $user->can('resubmit', $document);
        $canRelease = $user->can('release', $document);
        $canMarkEmailed = $user->can('markEmailed', $document);
        $canReturnToOwner = $user->can('returnToOwner', $document);
        $canReturnForCorrection = $user->can('returnForCorrection', $document);
        $canArchive = $user->can('archive', $document);

        $document->routings->each(function ($routing) use ($user) {
            $routing->setAttribute('can_cancel', $user->can('cancel', $routing));
            $routing->recipients->each(fn ($r) => $r->setAttribute('can_cancel', $user->can('cancel', $r)));
        });

        // Excludes cancelled routings and prefers the newest routing first —
        // otherwise, after a cancel-and-reroute, a recipient row from the
        // now-defunct CANCELLED routing (e.g. one this user had already
        // fully completed before it got cancelled) could be matched ahead of
        // their real, active assignment on the new routing round, since
        // $document->routings has no guaranteed order and this used a plain
        // ->first() across every routing ever issued. That stale match then
        // made Show.vue render "✓ Completed" for an assignment that no
        // longer exists, hiding the Acknowledge/Complete/Route UI for the
        // user's actual pending assignment entirely (a real reported bug —
        // the recipient of a rerouted document couldn't route it to their
        // own staff at all).
        //
        // Also excludes individually-cancelled recipient rows — since
        // DocumentRoutingRecipientController::cancel() can withdraw a single
        // mistakenly-added recipient without cancelling the whole routing
        // (e.g. two chiefs were routed to by mistake and only one is undone),
        // a routing can stay active (cancelled_at === null) while one of its
        // recipient rows is individually Cancelled. Without this, the exact
        // same class of stale-match bug above would resurface: this user's
        // own now-withdrawn row could still be picked as "their assignment,"
        // either falsely rendering it as done or shadowing a real active
        // assignment they hold on an older routing.
        $recipientAssignment = $document->routings
            ->reject(fn ($routing) => $routing->cancelled_at !== null)
            ->sortByDesc('id')
            ->flatMap(fn ($routing) => $routing->recipients)
            ->reject(fn ($r) => $r->status === RecipientStatus::Cancelled)
            ->first(fn ($r) => $r->user_id === $user->id
                || ($r->division_id !== null && $r->division_id === $user->division_id));

        // A division chief's (or a unit head's, if their division is
        // flagged allow_rd_direct_routing — e.g. ICTU/LU/PAU) acknowledgement
        // doesn't auto-complete their assignment — they may still route it
        // to their own staff instead — so the frontend needs to know to show
        // that deferred state rather than treating acknowledge as final. See
        // DocumentRoutingRecipientController::acknowledge()/isDirectRoutableUnitHead().
        $isDirectRoutableUnitHead = $user->hasRole('unit_head')
            && $user->division_id !== null
            && (bool) $user->division?->allow_rd_direct_routing;
        $recipientMayDeferCompletion = ($user->hasRole('division_chief') || $isDirectRoutableUnitHead) && $user->division_id !== null;

        if (in_array($document->status->value, ['signed', 'released'], true)) {
            $document->load('signature.signer:id,name');
        }

        if ($document->status->value === 'released') {
            $document->load('releaseStamp.stampedBy:id,name');
        }

        return Inertia::render('Documents/Show', compact(
            'document', 'canReview', 'canRoute', 'canSign', 'canUndoSignature', 'canDelete', 'canResubmit', 'canRelease', 'canMarkEmailed',
            'canReturnToOwner', 'canReturnForCorrection', 'canArchive', 'recipientAssignment', 'recipientMayDeferCompletion',
        ));
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        AuditLogger::log($request->user(), $document, 'document.deleted', $request);

        $document->delete();

        return redirect()->route('documents.index')->with('success', 'Document deleted.');
    }

    public function file(Request $request, Document $document): BinaryFileResponse
    {
        $this->authorize('view', $document);

        $disk = Storage::disk('documents');

        abort_if(! $disk->exists($document->file_path), 404);

        $ext = strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        // response()->file() (BinaryFileResponse) rather than the disk's
        // response()/StreamedResponse — it natively supports HTTP Range
        // requests for local files, which pdf.js relies on to progressively
        // fetch pages instead of downloading the whole file before it can
        // render anything.
        return response()->file($disk->path($document->file_path), [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.basename($document->file_path).'"',
        ]);
    }
}
