<?php

namespace App\Traits;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecipientStatus;
use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait DocumentScopesTrait
{
    protected function outgoingScope(Builder $q, User $user): Builder
    {
        $q->where('type', DocumentType::Outgoing);

        if ($user->hasRole('admin')) {
            return $q;
        }

        // RD/ARD only need visibility once a document has cleared the earlier
        // review levels (division chief, or — for ORD's own sub-units, which
        // have no division chief — the unit head instead; see
        // DocumentReviewController::nextStatusAfterUnitHead()) — documents
        // still sitting with those reviewers aren't yet ARD's/RD's concern.
        if ($user->hasAnyRole(['rd', 'ard'])) {
            return $q->whereNotIn('status', [
                DocumentStatus::Draft->value,
                DocumentStatus::Submitted->value,
                DocumentStatus::PendingUnitHeadReview->value,
                DocumentStatus::PendingDivisionChiefReview->value,
            ]);
        }

        // selfAndChildrenIds() rather than an exact match: a division chief
        // must also see documents from their own sub-units (e.g. Procurement
        // Unit/Personnel Section under Administrative Division) — including
        // a sub-unit's own unit head's uploads, which start at
        // PendingDivisionChiefReview against the chief per
        // OutgoingDocumentController::initialStatus(). No behavior change for
        // a unit_head themselves — their division has no children of its own,
        // so this still resolves to just their own division id.
        if ($user->hasAnyRole(['division_chief', 'unit_head']) && $user->division_id) {
            return $q->whereIn('division_id', Division::selfAndChildrenIds($user->division_id));
        }

        if ($user->hasRole('secretary')) {
            return $q->where(function ($sub) use ($user) {
                $sub->where('uploaded_by', $user->id)
                    ->orWhereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Signed->value]);
            });
        }

        // A plain user (originator/kmt_focal/detailed_staff/etc.) sees every
        // outgoing document — any status — uploaded by anyone in their own
        // division or its direct sub-units, not just their own uploads, so
        // they can track where a colleague's document currently sits in the
        // approval chain. Mirrors incomingScope()'s equivalent division-wide
        // branch below.
        if ($user->division_id) {
            return $q->where(function ($sub) use ($user) {
                $sub->where('uploaded_by', $user->id)
                    ->orWhereIn('division_id', Division::selfAndChildrenIds($user->division_id));
            });
        }

        return $q->where('uploaded_by', $user->id);
    }

    protected function incomingScope(Builder $q, User $user): Builder
    {
        $q->where('type', DocumentType::Incoming);

        if ($user->hasAnyRole(['admin', 'rd', 'ard']) || $this->isOrdSecretary($user)) {
            return $q;
        }

        // A user sees every incoming document uploaded by anyone in their own
        // division or its direct sub-units, not just their own uploads — one
        // person (usually the secretary) uploads on behalf of the whole
        // office, so the rest of that office needs visibility too.
        $divisionIds = Division::selfAndChildrenIds($user->division_id);

        return $q->where(function ($sub) use ($user, $divisionIds) {
            $sub->where('uploaded_by', $user->id)
                ->orWhereHas('routings.recipients', fn ($r) => $r->where('user_id', $user->id)
                    ->when($user->division_id, fn ($r2) => $r2->orWhere('division_id', $user->division_id)))
                ->when($divisionIds, fn ($s2) => $s2->orWhereHas('uploader', fn ($u) => $u->whereIn('division_id', $divisionIds)));
        });
    }

    protected function forActionScope(Builder $q, User $user): Builder
    {
        return $q->where(function ($root) use ($user) {
            $root->where(function ($sub) use ($user) {
                $sub->where('uploaded_by', $user->id)
                    ->whereIn('status', [
                        DocumentStatus::ReturnedForRevision->value,
                        DocumentStatus::ForComment->value,
                    ]);
            });

            // A reviewer never sees their own upload in their own For Action —
            // initialStatus() already routes a unit head's/division chief's own
            // upload past their own review level, but this guards against it
            // getting stuck there anyway (e.g. their role changed after they
            // submitted it). Matches the same exclusion in DocumentPolicy::review().
            if ($user->hasRole('unit_head') && $user->division_id) {
                $root->orWhere(function ($sub) use ($user) {
                    $sub->where('type', DocumentType::Outgoing->value)
                        ->where('status', DocumentStatus::PendingUnitHeadReview->value)
                        ->where('division_id', $user->division_id)
                        ->where('uploaded_by', '!=', $user->id);
                });
            }

            if ($user->hasRole('division_chief') && $user->division_id) {
                $root->orWhere(function ($sub) use ($user) {
                    // `Submitted` is included alongside `PendingDivisionChiefReview`:
                    // OutgoingDocumentController::initialStatus() leaves a document
                    // at `Submitted` (skipping unit-head review entirely) when the
                    // originator's division has no unit head — the chief is still
                    // the one who needs to act on it. Matches DocumentPolicy::review().
                    // selfAndChildrenIds() (not an exact match) so a sub-unit's own
                    // unit head's upload — which targets the parent chief per
                    // initialStatus() — actually shows up in that chief's queue.
                    $sub->where('type', DocumentType::Outgoing->value)
                        ->whereIn('status', [
                            DocumentStatus::Submitted->value,
                            DocumentStatus::PendingDivisionChiefReview->value,
                        ])
                        ->whereIn('division_id', Division::selfAndChildrenIds($user->division_id))
                        ->where('uploaded_by', '!=', $user->id);
                });
            }

            if ($user->hasRole('ard')) {
                $root->orWhere(function ($sub) use ($user) {
                    $sub->where('type', DocumentType::Outgoing->value)
                        ->where('status', DocumentStatus::PendingArdReview->value)
                        ->where('uploaded_by', '!=', $user->id);
                });
            }

            if ($user->hasRole('rd')) {
                $root->orWhere(function ($sub) use ($user) {
                    $sub->where('type', DocumentType::Outgoing->value)
                        ->where('status', DocumentStatus::PendingRdReview->value)
                        ->where('uploaded_by', '!=', $user->id);
                });
            }

            if ($user->hasRole('secretary')) {
                $root->orWhere(function ($sub) {
                    $sub->where('type', DocumentType::Outgoing->value)
                        ->where('status', DocumentStatus::Approved->value);
                });
            }

            if ($user->hasAnyRole(['rd', 'ard']) || $this->isOrdSecretary($user)) {
                // For the RD/her secretary specifically, "already routed" is
                // shared between the two of them via the `is_rd_routing` flag
                // (stamped at creation time — see DocumentRoutingController::store())
                // rather than tracked by *current* user identity — the
                // secretary routes *as* the RD, not as a separate independent
                // channel, so a routing issued by either drops it from both
                // of their queues, and this stays true even if she's later
                // reassigned to a different division. ARD stays independent
                // — she's a genuinely independent reviewer, not a delegate,
                // so only her own routing decision (by her own stable
                // routed_by id) counts for her. Matches DocumentPolicy::route()'s
                // identical exclusion.
                $isRdOrSecretary = $user->hasRole('rd') || $this->isOrdSecretary($user);

                $root->orWhere(function ($sub) use ($user, $isRdOrSecretary) {
                    $sub->where('type', DocumentType::Incoming->value)
                        ->where(function ($status) use ($user, $isRdOrSecretary) {
                            $status->whereIn('status', [
                                DocumentStatus::Received->value,
                                DocumentStatus::PendingRouting->value,
                            ])
                            // `Routed` also counts (e.g. the RD's secretary auto-forwarded
                            // it) but only until this user (or, for the RD/secretary pair,
                            // either of them) has personally issued a routing round for it.
                            // `is_rd_routing` is never true for the auto-forward routing
                            // itself — it's a system action, not a deliberate routing
                            // decision (see DocumentPolicy::route()'s matching exclusion).
                            ->orWhere(function ($routed) use ($user, $isRdOrSecretary) {
                                $routed->where('status', DocumentStatus::Routed->value)
                                    ->whereDoesntHave('routings', function ($r) use ($user, $isRdOrSecretary) {
                                        if ($isRdOrSecretary) {
                                            // A cancelled RD/secretary routing (see
                                            // DocumentRoutingPolicy::cancel()) doesn't count
                                            // as "personally issued" — cancelling it is
                                            // exactly what's meant to bring the document
                                            // back into the shared queue so either of them
                                            // can route it again. Matches DocumentPolicy::route()'s
                                            // equivalent exclusion.
                                            $r->where('is_rd_routing', true)->whereNull('cancelled_at');
                                        } else {
                                            $r->where('routed_by', $user->id)
                                                ->where(fn ($r2) => $r2->whereNull('remarks')
                                                    ->orWhere('remarks', '!=', \App\Http\Controllers\IncomingDocumentController::AUTO_FORWARD_REMARKS));
                                        }
                                    });
                            });
                        });
                });
            }

            if ($user->hasRole('admin')) {
                $root->orWhereIn('status', [
                    DocumentStatus::PendingUnitHeadReview->value,
                    DocumentStatus::Submitted->value,
                    DocumentStatus::PendingDivisionChiefReview->value,
                    DocumentStatus::PendingArdReview->value,
                    DocumentStatus::PendingRdReview->value,
                    DocumentStatus::Received->value,
                    DocumentStatus::PendingRouting->value,
                ]);
            }

            // Pending or Acknowledged both mean "still needs action from me"
            // (a recipient's work isn't done until Completed) — but a still-open
            // recipient row shouldn't keep a document in this user's queue once
            // they've personally issued their own routing round for it (e.g. a
            // division chief forwarding to staff after acknowledging) — that is
            // itself the action.
            $root->orWhere(function ($sub) use ($user) {
                $sub->whereHas('routings.recipients', fn ($r) =>
                    $r->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Acknowledged->value])
                      ->where(fn ($r2) => $r2->where('user_id', $user->id)
                          ->when($user->division_id, fn ($r3) => $r3->orWhere('division_id', $user->division_id)))
                // A cancelled routing (see DocumentRoutingPolicy::cancel()) doesn't
                // count as "personally issued" — cancelling it is exactly what's
                // meant to bring the document back into the chief's own queue so
                // they can route it again. Matches DocumentPolicy::route()'s
                // equivalent exclusion.
                )->whereDoesntHave('routings', fn ($r) => $r->where('routed_by', $user->id)->whereNull('cancelled_at'));
            });
        });
    }

    /**
     * RD-only split of forActionScope(): the outgoing-document half — items
     * awaiting the RD's own review/approval (plus the RD's own
     * returned/for-comment uploads, same as forActionScope). Reuses
     * forActionScope() rather than re-deriving its role logic, just narrowed
     * by type, so the two split lists can never drift from the combined one.
     */
    protected function forApprovalScope(Builder $q, User $user): Builder
    {
        return $this->forActionScope($q, $user)->where('type', DocumentType::Outgoing);
    }

    /**
     * RD-only split of forActionScope(): the incoming-document half — items
     * awaiting the RD to route/dispatch them (or act on a routing assignment).
     */
    protected function forRoutingScope(Builder $q, User $user): Builder
    {
        return $this->forActionScope($q, $user)->where('type', DocumentType::Incoming);
    }

    /**
     * Records Section's inbox of documents the secretary auto-forwarded on
     * intake that are still Pending (not yet acknowledged) — narrower than
     * the generic incomingScope, which also includes anything manually
     * routed to their division by the RD/ARD. Identified by the fixed
     * remarks string IncomingDocumentController::autoForward() writes — the
     * only code path that ever writes it.
     */
    protected function recordsSectionIncomingScope(Builder $q, User $user): Builder
    {
        return $this->recordsSectionAutoForwardedScope($q, $user, RecipientStatus::Pending);
    }

    /**
     * Records Section documents the secretary auto-forwarded that have
     * already been acknowledged. Records Section staff aren't division
     * chiefs, so acknowledging and completing happen in the same step (see
     * DocumentRoutingRecipientController::acknowledge()) — there's no
     * separate "acknowledged but not yet handled" state for them, so this is
     * the Completed recipient status.
     */
    protected function recordsSectionHandledScope(Builder $q, User $user): Builder
    {
        return $this->recordsSectionAutoForwardedScope($q, $user, RecipientStatus::Completed);
    }

    private function recordsSectionAutoForwardedScope(Builder $q, User $user, RecipientStatus $recipientStatus): Builder
    {
        $q->where('type', DocumentType::Incoming);

        if (! $this->isRecordsSectionMember($user)) {
            return $q->whereRaw('1 = 0');
        }

        return $q->whereHas('routings', fn ($r) => $r->where('remarks', \App\Http\Controllers\IncomingDocumentController::AUTO_FORWARD_REMARKS)
            ->whereHas('recipients', fn ($r2) => $r2->where('status', $recipientStatus->value)));
    }

    /**
     * Records Section's view of signed outgoing documents still awaiting
     * physical release/dispatch. Only documents the uploader flagged as
     * needing release at submission time (`requires_release`) show up here —
     * documents marked "for own consumption" are never routed to Records
     * Section at all; the uploader is just notified once signed.
     */
    protected function readyToReleaseScope(Builder $q, User $user): Builder
    {
        return $this->recordsSectionOutgoingScope($q, $user, DocumentStatus::Signed)
            ->where('requires_release', true);
    }

    /**
     * Records Section's view of outgoing documents already released.
     */
    protected function releasedScope(Builder $q, User $user): Builder
    {
        return $this->recordsSectionOutgoingScope($q, $user, DocumentStatus::Released);
    }

    private function recordsSectionOutgoingScope(Builder $q, User $user, DocumentStatus $status): Builder
    {
        $q->where('type', DocumentType::Outgoing)->where('status', $status);

        if (! $this->isRecordsSectionMember($user)) {
            return $q->whereRaw('1 = 0');
        }

        return $q;
    }

    private function isRecordsSectionMember(User $user): bool
    {
        return $user->hasRole('admin')
            || ($user->division_id !== null && $user->division_id === Division::recordsSectionId());
    }

    /**
     * The RD's own secretary — same identification as
     * DocumentPolicy::isOrdSecretary() (kept as a separate private copy here
     * rather than shared, matching how isRecordsSectionMember() above is
     * also not shared with the policy's own records-section checks).
     */
    private function isOrdSecretary(User $user): bool
    {
        if (! $user->hasRole('secretary') || $user->division_id === null) {
            return false;
        }

        $division = $user->division;

        return $division?->code === 'ORD' || $division?->parent?->code === 'ORD';
    }

    protected function visibleScope(Builder $q, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $q;
        }

        // Same early-stage restriction as outgoingScope() — RD/ARD shouldn't
        // see documents still with unit head/division chief here either
        // (this scope feeds the Dashboard's "Recent Documents" widget). The
        // RD's secretary gets the same unrestricted incoming visibility (she
        // routes in lieu of the RD) but not the outgoing bypass — she has her
        // own distinct outgoing-signing rules, handled in the branch below.
        if ($user->hasAnyRole(['rd', 'ard'])) {
            return $q->where(function ($root) {
                $root->where(function ($out) {
                    $out->where('type', DocumentType::Outgoing)
                        ->whereNotIn('status', [
                            DocumentStatus::Draft->value,
                            DocumentStatus::Submitted->value,
                            DocumentStatus::PendingUnitHeadReview->value,
                            DocumentStatus::PendingDivisionChiefReview->value,
                        ]);
                })->orWhere('type', DocumentType::Incoming);
            });
        }

        return $q->where(function ($root) use ($user) {
            $root->where(function ($out) use ($user) {
                $out->where('type', DocumentType::Outgoing);
                if ($user->hasAnyRole(['division_chief', 'unit_head']) && $user->division_id) {
                    $out->whereIn('division_id', Division::selfAndChildrenIds($user->division_id));
                } elseif ($user->hasRole('secretary')) {
                    $out->where(function ($sub) use ($user) {
                        $sub->where('uploaded_by', $user->id)
                            ->orWhereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Signed->value]);
                    });
                } elseif ($user->division_id) {
                    // Mirrors outgoingScope()'s equivalent plain-user branch — see the comment there.
                    $out->where(function ($sub) use ($user) {
                        $sub->where('uploaded_by', $user->id)
                            ->orWhereIn('division_id', Division::selfAndChildrenIds($user->division_id));
                    });
                } else {
                    $out->where('uploaded_by', $user->id);
                }
            })->orWhere(function ($in) use ($user) {
                $in->where('type', DocumentType::Incoming);

                if ($this->isOrdSecretary($user)) {
                    return;
                }

                $divisionIds = Division::selfAndChildrenIds($user->division_id);

                $in->where(fn ($s) => $s
                    ->where('uploaded_by', $user->id)
                    ->orWhereHas('routings.recipients', fn ($r) => $r->where('user_id', $user->id)
                        ->when($user->division_id, fn ($r2) => $r2->orWhere('division_id', $user->division_id)))
                    ->when($divisionIds, fn ($s2) => $s2->orWhereHas('uploader', fn ($u) => $u->whereIn('division_id', $divisionIds)))
                );
            });
        });
    }
}
