<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecipientStatus;
use App\Enums\ReviewAction;
use App\Http\Controllers\IncomingDocumentController;
use App\Models\Division;
use App\Models\Document;
use App\Models\OriginatorUploadSetting;
use App\Models\User;
use App\Models\UserSigningCredential;

class DocumentPolicy
{
    /**
     * Admins bypass every policy check.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    /**
     * Can the user see this document's detail page?
     */
    public function view(User $user, Document $document): bool
    {
        if ($user->hasAnyRole(['sds', 'asds'])) {
            return true;
        }

        // The SDS's secretary can also route incoming documents in lieu of
        // the SDS (see route()), so she needs the same blanket incoming
        // visibility to find and open anything she might need to route.
        if ($document->type === DocumentType::Incoming && $this->isOsdsSecretary($user)) {
            return true;
        }

        if ($document->uploaded_by === $user->id) {
            return true;
        }

        if ($user->hasAnyRole(['division_chief', 'unit_head'])
            && $user->division_id !== null
            && $document->type === DocumentType::Outgoing
            && (
                $document->division_id === $user->division_id
                || Division::where('id', $document->division_id)->value('parent_id') === $user->division_id
            )) {
            return true;
        }

        // Anyone in the uploader's division (or its direct sub-units) can see
        // an outgoing document, regardless of who on that team uploaded it or
        // its current status — mirrors DocumentScopesTrait::outgoingScope()'s
        // equivalent branch, and the incoming analogue further below.
        if ($document->type === DocumentType::Outgoing
            && $user->division_id !== null
            && $document->division_id !== null
            && in_array($document->division_id, Division::selfAndChildrenIds($user->division_id), true)) {
            return true;
        }

        if ($document->reviews()->where('reviewer_id', $user->id)->exists()) {
            return true;
        }

        if ($user->hasRole('secretary')
            && $document->type === DocumentType::Outgoing
            && in_array($document->status, [DocumentStatus::Approved, DocumentStatus::Signed], true)) {
            return true;
        }

        // Records Section handles releasing signed outgoing documents
        // regardless of which division originated them.
        if ($user->division_id !== null
            && $user->division_id === Division::recordsSectionId()
            && $document->type === DocumentType::Outgoing
            && in_array($document->status, [DocumentStatus::Signed, DocumentStatus::Released], true)) {
            return true;
        }

        if ($document->routings()
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)
                ->when($user->division_id, fn ($q2) => $q2->orWhere('division_id', $user->division_id)))
            ->exists()) {
            return true;
        }

        // Anyone in the uploader's division (or its direct sub-units) can see
        // an incoming document, regardless of who on that team uploaded it —
        // must mirror DocumentScopesTrait::incomingScope()'s equivalent branch.
        if ($document->type === DocumentType::Incoming
            && $user->division_id !== null
            && $document->uploader
            && in_array($document->uploader->division_id, Division::selfAndChildrenIds($user->division_id), true)) {
            return true;
        }

        return false;
    }

    /**
     * Can the user submit a new outgoing document? The SDS's own (OSDS)
     * secretary also gets this — she drafts memoranda on the SDS's behalf
     * from time to time, not just handling incoming mail/signing. A
     * secretary outside the OSDS tree doesn't get this ability.
     *
     * A plain 'originator' (Staff) no longer gets this by default — they
     * additionally need the 'kmt_focal' role, a secondary role layered on
     * top of their primary one (see UserController::rolesToSync()), not a
     * selectable primary role of its own. A user with only 'kmt_focal' and
     * no 'originator' role gets nothing from this branch — it only has an
     * effect paired with 'originator'. unit_head/division_chief keep their
     * existing unrestricted ability, unaffected by this. Review chain is
     * unchanged either way — OutgoingDocumentController::initialStatus()
     * has no role-specific branching for originator/kmt_focal, so a
     * KMT-Focal upload goes through the exact same unit-head-then-chief
     * chain any originator's upload always has.
     *
     * An admin can lift the KMT requirement app-wide from the Users page
     * (OriginatorUploadSetting) — while it's on, every originator with a
     * division may create outgoing documents, KMT Focal or not.
     */
    public function createOutgoing(User $user): bool
    {
        if ($user->hasRole('originator') && $user->division_id !== null) {
            return $user->hasRole('kmt_focal') || OriginatorUploadSetting::allowsAllOriginators();
        }

        if ($user->hasAnyRole(['unit_head', 'division_chief']) && $user->division_id !== null) {
            return true;
        }

        // Staff detailed to this Schools Division Office from elsewhere (e.g. a
        // school or another division office) — deliberately no division_id check, since
        // they're never assigned one (see RoleAndDivisionSeeder).
        if ($user->hasRole('detailed_staff')) {
            return true;
        }

        return $this->isOsdsSecretary($user);
    }

    /**
     * Can the user upload a new incoming document?
     * Restricted to the SDS's secretary (OSDS Proper) — she's the one who
     * physically receives incoming mail/documents on the SDS's behalf.
     * Other OSDS sub-unit staff (e.g. ICTU, LU, PAU) don't receive incoming
     * documents themselves, so they don't get this ability even though
     * they're technically within the OSDS division tree.
     *
     * Records Section can also upload — they sometimes intake documents
     * directly. These uploads aren't auto-forwarded (that only happens for
     * OSDS Proper's code), so they just land at Received and the SDS (who
     * already sees every incoming document unconditionally) routes them on
     * to division chiefs herself.
     */
    public function createIncoming(User $user): bool
    {
        if ($user->hasAnyRole(['admin', 'sds'])) {
            return true;
        }

        if ($user->division_id !== null && $user->division_id === Division::recordsSectionId()) {
            return true;
        }

        if ($this->isOsdsSecretary($user)) {
            return true;
        }

        return false;
    }

    /**
     * Can the user view/print the daily Incoming Documents Log — every
     * incoming document uploaded on a given date, regardless of who
     * uploaded it or whether it's been routed to them? Deliberately a
     * broader grant than incomingScope()'s normal per-user visibility
     * (Records Section otherwise only sees documents they uploaded
     * themselves, that were routed to them, or that came from their own
     * division) — this is a read-only reporting view, not a change to what
     * they can act on, so it doesn't touch incomingScope() itself.
     */
    public function viewIncomingLog(User $user): bool
    {
        return $user->hasRole('admin')
            || ($user->division_id !== null && $user->division_id === Division::recordsSectionId());
    }

    /**
     * The SDS's own secretary — identified by the `secretary` role plus
     * sitting in the OSDS division tree (OSDS itself, or a direct sub-unit
     * like OSDS Proper). She's treated as acting in lieu of the SDS for
     * certain abilities (uploading incoming mail, routing incoming
     * documents) that other OSDS sub-unit staff (ICTU, LU, PAU) don't get.
     */
    private function isOsdsSecretary(User $user): bool
    {
        if (! $user->hasRole('secretary') || $user->division_id === null) {
            return false;
        }

        $division = $user->division;

        return $division?->code === 'OSDS' || $division?->parent?->code === 'OSDS';
    }

    /**
     * A unit head whose division has been admin-flagged
     * allow_sds_direct_routing (e.g. ICTU/LU/PAU, or any other unit opted in
     * via Office Management — see DivisionController) — the SDS routes
     * incoming documents to these unit heads exactly like she routes to
     * division chiefs (see DocumentRoutingController::candidateUsers()), so
     * they need the same "may forward this to my own staff" ability a
     * division chief gets once they've acknowledged it (see route() below).
     * A unit head whose division isn't flagged (e.g. Procurement Unit under
     * Administrative Division) is never offered as an SDS routing target in
     * the first place — for them a routing round always treats them as
     * staff, a terminal recipient, not someone who forwards further.
     */
    private function isDirectRoutableUnitHead(User $user): bool
    {
        if (! $user->hasRole('unit_head') || $user->division_id === null) {
            return false;
        }

        return (bool) $user->division?->allow_sds_direct_routing;
    }

    /**
     * Is this user the designated reviewer for the document's current stage?
     * Division chiefs are scoped to their own division. The uploader can
     * never review/approve their own document at any stage — normally
     * initialStatus() already routes a unit head's/division chief's own
     * upload past their own review level, but this guards against the
     * document getting stuck there anyway (e.g. the uploader's role changed
     * after they submitted it).
     */
    public function review(User $user, Document $document): bool
    {
        if ($document->uploaded_by === $user->id) {
            return false;
        }

        return match ($document->status) {
            DocumentStatus::PendingUnitHeadReview => $user->hasRole('unit_head')
                && $user->division_id !== null
                && $user->division_id === $document->division_id,
            DocumentStatus::Submitted,
            DocumentStatus::PendingDivisionChiefReview => $user->hasRole('division_chief')
                && $user->division_id !== null
                && (
                    $user->division_id === $document->division_id
                    // Property access (not Division::where(...)->value(...))
                    // so this reads from an already eager-loaded `division`
                    // relation when one is available (e.g. DocumentController::
                    // index()'s bulk listing) instead of firing a fresh query
                    // per document.
                    || $document->division?->parent_id === $user->division_id
                ),
            DocumentStatus::PendingAsdsReview => $user->hasRole('asds'),
            DocumentStatus::PendingSdsReview => $user->hasRole('sds'),
            default => false,
        };
    }

    /**
     * Can the secretary sign this document?
     * Requires: secretary role, outgoing PDF at Approved status, and the SDS
     * having an uploaded signing credential (the secretary attaches the SDS's
     * signature, not one of her own).
     */
    public function sign(User $user, Document $document): bool
    {
        if ($document->type !== DocumentType::Outgoing) {
            return false;
        }

        if ($document->status !== DocumentStatus::Approved) {
            return false;
        }

        if (! str_ends_with(strtolower($document->file_path ?? ''), '.pdf')) {
            return false;
        }

        return $user->hasRole('secretary') && UserSigningCredential::forSds() !== null;
    }

    /**
     * Can the secretary undo a signature to correct a mistake (e.g. the
     * e-signature was placed in the wrong spot) and sign again? Only while
     * still Signed — once Records Section has released it, the document is
     * final and undoing the signature no longer makes sense. Previously this
     * required an admin to manually revert the status in the database.
     */
    public function undoSignature(User $user, Document $document): bool
    {
        return $document->type === DocumentType::Outgoing
            && $document->status === DocumentStatus::Signed
            && $user->hasRole('secretary');
    }

    /**
     * Lets the secretary manually send an Approved document (sitting in her
     * signing queue) back to its originator when the SDS's own most recent
     * review left remarks but the document was approved anyway rather than
     * returned. DocumentReviewController::store()'s "remarks always win"
     * rule (added after this feature's predecessor shipped) now prevents
     * this from happening for any *new* review, but documents reviewed
     * before that rule existed can still be sitting here with an SDS comment
     * nobody acted on — this gives the secretary, who's the next person to
     * actually look at the document, a way to catch that and correct course
     * instead of silently signing over an overlooked concern.
     *
     * Scoped to the SDS's own *latest* review specifically (not "any SDS
     * review ever, on any submission cycle") — an earlier
     * returned-for-revision review from before a resubmission restarted the
     * whole chain doesn't count; only the review that actually produced the
     * document's current Approved status matters here.
     */
    public function returnToOwner(User $user, Document $document): bool
    {
        if ($document->type !== DocumentType::Outgoing || $document->status !== DocumentStatus::Approved) {
            return false;
        }

        if (! $user->hasRole('secretary')) {
            return false;
        }

        $latestSdsReview = $document->reviews
            ->filter(fn ($r) => $r->role_at_review === 'sds')
            ->sortByDesc('id')
            ->first();

        return $latestSdsReview !== null
            && $latestSdsReview->action === ReviewAction::ApprovedForwarded
            && trim((string) $latestSdsReview->remarks) !== '';
    }

    /**
     * Lets the secretary send an Approved document back to its originator
     * for a physical/formatting problem she finds while preparing to sign
     * it — missing pages, wrong letterhead/format, etc. Deliberately
     * separate from returnToOwner() above (which is narrowly scoped to one
     * specific legacy scenario: an SDS comment that was overlooked) — this
     * one is a general QC gate available on any Approved document, any
     * secretary (matching sign()'s scope, not narrowed to the SDS's own OSDS
     * secretary), with no dependency on the review history at all. The two
     * can both be offered on the same document at once; each addresses a
     * different kind of problem.
     */
    public function returnForCorrection(User $user, Document $document): bool
    {
        return $document->type === DocumentType::Outgoing
            && $document->status === DocumentStatus::Approved
            && $user->hasRole('secretary');
    }

    /**
     * The SDS can delete any document, of either type (always soft-deleted).
     *
     * An outgoing document's own uploader may cancel/delete it themselves at
     * any point before the SDS approves it — including after a unit head/
     * division chief/ASDS has already reviewed and forwarded it onward, since
     * none of those are the final word. Once `Approved` (or anything past
     * it — signature, release), only the SDS can remove it.
     *
     * An incoming document's own uploader may delete it themselves any time
     * before it's been *deliberately* routed — whether routed manually by the
     * SDS/ASDS/a division chief. The automatic OSDS-Proper-upload auto-forward
     * (IncomingAutoForwarder) doesn't count — it's a system action taken on
     * the uploader's behalf, not a human routing decision, exactly like it
     * doesn't count as "already routed" in route()/forActionScope() — so an
     * OSDS secretary must still be able to delete her own mistaken upload even
     * though it was auto-forwarded to Records Section the instant she
     * created it. Once any *real, still-active* routing exists, only the SDS
     * can remove it — except for the SDS's own secretary (see below), who
     * gets an unrestricted exception on her own uploads. A *cancelled* real
     * routing (see DocumentRoutingPolicy::cancel()) doesn't count either —
     * same "cancelled means it never really happened" treatment already
     * given everywhere else a routing's existence is checked (route(),
     * forActionScope()) — so if the only real routing(s) on the document
     * were all cancelled, the uploader can still delete it.
     *
     * The SDS's own secretary may delete an incoming document she uploaded
     * herself regardless of routing status — even after she's personally
     * routed it onward (e.g. picked the wrong recipient/division). She acts
     * as the SDS's delegate for incoming mail (uploading it, and often routing
     * it too — see isOsdsSecretary()'s shared "already routed" pool with the
     * SDS elsewhere in this class), so a routing decision she made herself on
     * her own upload isn't someone else's authoritative decision the way a
     * chief's or the SDS's own routing would be — this mirrors the SDS's own
     * unconditional delete power above, just scoped to documents she
     * personally uploaded.
     *
     * On top of that, she may also delete an incoming document she did NOT
     * upload — e.g. one Records Section uploaded — once there's no active
     * real routing left on it (same rule an ordinary uploader gets on their
     * own document, just not restricted to "own upload" for her). She's the
     * one actually driving the routing/cancel lifecycle for incoming mail on
     * the SDS's behalf across the board, not only documents she personally
     * uploaded, so once she's cancelled the only routing on someone else's
     * upload she needs to be able to remove it too — a real reported gap:
     * she could cancel a routing she'd issued by mistake but still couldn't
     * delete the underlying document, since the prior version of this method
     * required being the uploader before even checking routing status at
     * all. An active (non-cancelled) real routing still blocks her here,
     * same as it would for the actual uploader — this isn't the SDS's fully
     * unconditional power, just her routing-lifecycle authority extended to
     * deletion. Soft-deleted, so nothing is actually destroyed.
     */
    public function delete(User $user, Document $document): bool
    {
        if ($user->hasRole('sds')) {
            return true;
        }

        if ($document->type === DocumentType::Incoming && $this->isOsdsSecretary($user)) {
            if ($document->uploaded_by === $user->id) {
                return true;
            }

            return $this->incomingHasNoActiveRealRouting($document);
        }

        if ($document->uploaded_by !== $user->id) {
            return false;
        }

        if ($document->type === DocumentType::Outgoing) {
            return ! in_array($document->status, [
                DocumentStatus::Approved,
                DocumentStatus::PendingSignature,
                DocumentStatus::Signed,
                DocumentStatus::Released,
            ], true);
        }

        return $this->incomingHasNoActiveRealRouting($document);
    }

    /**
     * Shared by delete()'s two incoming-document paths (the ordinary
     * uploader, and the SDS's secretary acting on someone else's upload). A
     * filed/archived document (see archive() above) is a deliberate closure
     * decision, same as a real routing — locked from deletion the same way.
     * A *cancelled* real routing (see DocumentRoutingPolicy::cancel())
     * doesn't count as "still routed" — same "cancelled means it never
     * really happened" treatment already given everywhere else a routing's
     * existence is checked (route(), forActionScope()). The auto-forward
     * routing (IncomingAutoForwarder) never counts as a real routing either,
     * same exclusion as those same call sites.
     */
    private function incomingHasNoActiveRealRouting(Document $document): bool
    {
        if ($document->status === DocumentStatus::Archived) {
            return false;
        }

        return $document->routings()
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q->whereNull('remarks')
                ->orWhere('remarks', '!=', IncomingDocumentController::AUTO_FORWARD_REMARKS))
            ->doesntExist();
    }

    /**
     * Can the user issue an action slip (route) for this document?
     * Includes `Routed` so the SDS/ASDS can still route it themselves after
     * someone else already routed it — but only once each: after SDS/ASDS has
     * personally issued their own routing round for it, the option disappears
     * for them too (matches the same rule the forActionScope() "For Routing"
     * queue already applies), mirroring the division chief case below. The
     * SDS's own secretary gets this exact same ability, acting in lieu of the
     * SDS — she is not restricted to documents she personally uploaded.
     *
     * For the SDS/her secretary specifically, "already routed" is shared
     * between the two of them via the `is_sds_routing` flag on
     * `document_routings` (stamped at creation time — see
     * DocumentRoutingController::store()) rather than tracked by *current*
     * user identity: the secretary routes *as* the SDS, not as a separate
     * independent channel, so if either of them has already issued a real
     * routing round, the option disappears for both — otherwise the SDS would
     * keep seeing "Route Document" (and the document would keep reappearing
     * in her For Action/For Routing queue) for a document her own secretary
     * already routed on her behalf, and vice versa. Deliberately NOT derived
     * from whoever currently holds the `sds`/`secretary` role+division at
     * query time — staff get reassigned (a secretary transferred to a
     * different division later), and a routing she issued while she WAS the
     * SDS's secretary must keep counting as such forever after, regardless of
     * where she works now. ASDS is excluded from this sharing — she's a
     * genuinely independent reviewer, not a delegate, so only her own
     * routing decision (tracked by her own stable `routed_by` id) counts.
     *
     * `is_sds_routing` is only ever true for a real, deliberate routing — the
     * automatic OSDS-Proper-upload auto-forward (IncomingAutoForwarder) never
     * sets it, even though it's recorded with `routed_by` set to the
     * uploading secretary (for the audit trail — see who triggered it) — it's
     * a system action, not a deliberate routing decision, and must not
     * consume that same secretary's one manual routing turn on her own upload.
     *
     * A division chief is different: they may only forward a document that
     * was routed to them (by user or by their whole division) AND that they've
     * already acknowledged (proves they've actually looked at it before
     * delegating it onward), and only once — after they've issued their own
     * routing round for it, forwarding is done and the option disappears.
     * A unit head within the OSDS division tree (ICTU/LU/PAU) gets this exact
     * same ability for the same reason — the SDS routes directly to them too,
     * and they need to be able to forward it on to their own staff.
     */
    public function route(User $user, Document $document): bool
    {
        if ($document->type !== DocumentType::Incoming) {
            return false;
        }

        if (! in_array($document->status, [
            DocumentStatus::Received,
            DocumentStatus::PendingRouting,
            DocumentStatus::Routed,
        ], true)) {
            return false;
        }

        // Below: property access on `routings`/`recipients` (Collection
        // methods) rather than `$document->routings()`/`whereHas(...)` (fresh
        // query-builder calls) — reads from an already eager-loaded relation
        // when one is available (e.g. DocumentController::index()'s bulk
        // listing) instead of firing 1-2 fresh queries per document. Same
        // boolean conditions as before, just evaluated in memory.
        if ($user->hasRole('sds') || $this->isOsdsSecretary($user)) {
            // A cancelled SDS/secretary routing (see DocumentRoutingPolicy::cancel())
            // doesn't count as "already routed" — cancelling it is exactly
            // what's meant to let the SDS/secretary route it again after a
            // mistake, mirroring the division-chief branch below. ASDS has no
            // cancel pathway and isn't part of this shared pool, so her own
            // branch below intentionally has no equivalent exclusion.
            return ! $document->routings->contains(fn ($r) => $r->is_sds_routing && $r->cancelled_at === null);
        }

        if ($user->hasRole('asds')) {
            return ! $document->routings->contains(fn ($r) => $r->routed_by === $user->id
                && ($r->remarks === null || $r->remarks !== IncomingDocumentController::AUTO_FORWARD_REMARKS));
        }

        if (($user->hasRole('division_chief') || $this->isDirectRoutableUnitHead($user)) && $user->division_id !== null) {
            // A cancelled routing (see DocumentRoutingPolicy::cancel()) doesn't
            // count as "already routed" — cancelling it is exactly what frees
            // the chief to route again after picking the wrong staff.
            if ($document->routings->contains(fn ($r) => $r->routed_by === $user->id && $r->cancelled_at === null)) {
                return false;
            }

            return $document->routings->contains(fn ($r) => $r->recipients->contains(
                fn ($rec) => in_array($rec->status, [RecipientStatus::Acknowledged, RecipientStatus::Completed], true)
                    && ($rec->user_id === $user->id || $rec->division_id === $user->division_id)
            ));
        }

        return false;
    }

    /**
     * Can the SDS's own OSDS secretary file this incoming document directly,
     * skipping routing entirely? Secretary-only, deliberately NOT shared
     * with the SDS the way route()/cancel() are (mirrors sign(), which is
     * likewise exclusively the secretary's job in this app). Two distinct
     * cases:
     *
     * 1. A Records-Section upload that hasn't been routed at all yet
     *    (Received/PendingRouting) and doesn't need to reach a division
     *    chief.
     *
     * 2. An OSDS-Proper upload that was auto-forwarded to Records Section on
     *    intake (IncomingAutoForwarder — the recipient's action is literally
     *    RoutingAction::File) and never got a REAL, deliberate routing on
     *    top of that. Records Section completing their courtesy copy
     *    doesn't promote the document to Completed — see
     *    DocumentRoutingRecipientController::promoteDocumentIfFullyCompleted()'s
     *    "has a real routing" gate — so without this, it would sit in the
     *    secretary's For Action/For Routing queue forever with no way to
     *    close it out, even after Records Section has already filed their
     *    copy. A real reported gap: Records Section files their auto-forward
     *    copy, but the document itself never leaves the secretary's queue.
     */
    public function archive(User $user, Document $document): bool
    {
        if ($document->type !== DocumentType::Incoming) {
            return false;
        }

        if (! $this->isOsdsSecretary($user)) {
            return false;
        }

        if (in_array($document->status, [DocumentStatus::Received, DocumentStatus::PendingRouting], true)) {
            $recordsSectionId = Division::recordsSectionId();

            // Prefer the division stamped on the document at upload time
            // (see IncomingDocumentController::store()) over the uploader's
            // CURRENT division, which could have changed since — falls back
            // to the uploader's current division only for documents
            // uploaded before this column started being populated (the
            // existing backlog this feature exists to clear).
            if ($document->division_id !== null) {
                return $document->division_id === $recordsSectionId;
            }

            return $document->uploader?->division_id === $recordsSectionId;
        }

        if ($document->status === DocumentStatus::Routed) {
            // "No real routing" — auto-forward routings, and any real
            // routing that was itself cancelled, don't count (same
            // "cancelled means it never really happened" treatment used
            // everywhere else a routing's existence is checked — route(),
            // forActionScope(), delete()).
            return ! $document->routings()
                ->whereNull('cancelled_at')
                ->where(fn ($q) => $q->whereNull('remarks')
                    ->orWhere('remarks', '!=', IncomingDocumentController::AUTO_FORWARD_REMARKS))
                ->exists();
        }

        return false;
    }

    /**
     * Can the uploader correct and resubmit this document? Only while it's
     * for_comment/returned_for_revision — rejected is a terminal dead end.
     */
    public function resubmit(User $user, Document $document): bool
    {
        return $document->uploaded_by === $user->id
            && in_array($document->status, [
                DocumentStatus::ForComment,
                DocumentStatus::ReturnedForRevision,
            ], true);
    }

    /**
     * Can the user mark this signed outgoing document as physically released?
     * Records Section only — they handle dispatch/hand-out regardless of
     * which division originated the document — and only when the uploader
     * actually asked for release-by-Records-Section at submission time;
     * "for own consumption" documents never enter that queue.
     */
    public function release(User $user, Document $document): bool
    {
        return $document->type === DocumentType::Outgoing
            && $document->status === DocumentStatus::Signed
            && $document->requires_release
            && $user->division_id !== null
            && $user->division_id === Division::recordsSectionId();
    }

    /**
     * Can the user mark this already-released document as emailed —
     * confirming Records Section downloaded the released PDF and sent it
     * out? Only after release, and only once (re-marking isn't meaningful).
     */
    public function markEmailed(User $user, Document $document): bool
    {
        return $document->type === DocumentType::Outgoing
            && $document->status === DocumentStatus::Released
            && $document->emailed_at === null
            && $user->division_id !== null
            && $user->division_id === Division::recordsSectionId();
    }
}
