<?php

namespace App\Policies;

use App\Enums\RecipientStatus;
use App\Models\DocumentRouting;
use App\Models\User;

class DocumentRoutingPolicy
{
    /**
     * Can the user undo this routing round (e.g. they picked the wrong staff
     * member/chief)?
     *
     * The RD and her ORD secretary may do this **anytime**, regardless of
     * whether the recipient has already acknowledged or even completed it —
     * she acts as the RD's delegate for dispatch decisions, so a recipient
     * having already acted on a routing SHE issued doesn't make it someone
     * else's final word the way it would for a division chief's own onward
     * routing to their staff. This mirrors the identical unconditional
     * override already given to her for deleting her own incoming uploads
     * (see DocumentPolicy::delete()) — a real reported case: the secretary
     * needed to reroute a document after the recipient had already
     * acknowledged it, and the previous "only while every recipient is still
     * Pending" rule (shared with the division-chief case below) blocked her
     * entirely with no way to correct it. They share cancel ownership via
     * `is_rd_routing`, mirroring how DocumentPolicy::route() already treats
     * the two of them as one pool for "already routed": the secretary routes
     * *as* the RD, not independently, so either of them must be able to undo
     * a mistake the other made.
     *
     * A division chief stays restricted to before-acknowledgment only —
     * scoped to their own `routed_by` id, no sharing — since their routing
     * dispatches to someone else's real work, not the RD's own delegated
     * authority; once their staff has acknowledged, it's been acted on and
     * can no longer be silently withdrawn.
     *
     * ARD and ORD unit heads have no path into this method — ARD is a
     * genuinely independent reviewer (excluded from the shared pool
     * everywhere else too), and unit heads simply aren't part of this
     * request.
     */
    public function cancel(User $user, DocumentRouting $routing): bool
    {
        if ($routing->cancelled_at !== null) {
            return false;
        }

        if ($user->hasRole('rd') || $this->isOrdSecretary($user)) {
            return $routing->is_rd_routing;
        }

        if ($user->hasRole('division_chief')) {
            return $routing->routed_by === $user->id
                && ! $routing->recipients()
                    ->where('status', '!=', RecipientStatus::Pending->value)
                    ->exists();
        }

        return false;
    }

    /**
     * The RD's own secretary — same identification as
     * DocumentPolicy::isOrdSecretary() (kept as a separate private copy here
     * rather than shared, matching how isOrdSecretary() is duplicated
     * per-class elsewhere in this codebase rather than shared).
     */
    private function isOrdSecretary(User $user): bool
    {
        if (! $user->hasRole('secretary') || $user->division_id === null) {
            return false;
        }

        return $user->division?->code === 'ORD' || $user->division?->parent?->code === 'ORD';
    }
}
