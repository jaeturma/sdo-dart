<?php

namespace App\Policies;

use App\Enums\RecipientStatus;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;

class DocumentRoutingRecipientPolicy
{
    /**
     * Can the user acknowledge (and, for most roles, complete in the same
     * step) this routing assignment? Only the assigned recipient may act,
     * and only while pending.
     */
    public function acknowledge(User $user, DocumentRoutingRecipient $recipient): bool
    {
        return $this->isAssigned($user, $recipient)
            && $recipient->status === RecipientStatus::Pending;
    }

    /**
     * The deferred completion step for a division chief who acknowledged but
     * decided to handle the assignment themselves rather than route it to
     * staff (see DocumentRoutingRecipientController::acknowledge()).
     */
    public function complete(User $user, DocumentRoutingRecipient $recipient): bool
    {
        return $this->isAssigned($user, $recipient)
            && $recipient->status === RecipientStatus::Acknowledged;
    }

    /**
     * The recipient row targets either one specific user, or an entire
     * division/section — in which case any member of that division may act.
     */
    private function isAssigned(User $user, DocumentRoutingRecipient $recipient): bool
    {
        return $user->id === $recipient->user_id
            || ($recipient->division_id !== null && $user->division_id === $recipient->division_id);
    }

    /**
     * Undo this one recipient's assignment — e.g. a routing round meant for
     * one chief was mistakenly sent to two, and only the wrong one should be
     * withdrawn while the correctly-routed chief's assignment continues
     * untouched. This is deliberately scoped to a single
     * DocumentRoutingRecipient row rather than DocumentRoutingPolicy::cancel()'s
     * whole-routing-round scope, which cancels every recipient under that
     * routing — exactly the behavior that made an unrelated, correctly
     * routed chief's assignment disappear alongside the mistaken one (the
     * reported bug this fixes).
     *
     * Permission mirrors DocumentRoutingPolicy::cancel() at the recipient
     * level: the SDS/her OSDS secretary may withdraw any recipient under a
     * routing round either of them issued (is_sds_routing), anytime,
     * regardless of whether that recipient has already acknowledged or
     * completed it — same delegate relationship as the whole-routing case.
     * A division chief may only withdraw a recipient under their own
     * routing while that specific recipient is still Pending — chief onward
     * routings dispatch to someone else's real work, so once acted on it's
     * no longer silently withdrawable, same restriction as the whole-round
     * case just narrowed to the one row.
     */
    public function cancel(User $user, DocumentRoutingRecipient $recipient): bool
    {
        if ($recipient->status === RecipientStatus::Cancelled) {
            return false;
        }

        $routing = $recipient->routing;

        if ($routing->cancelled_at !== null) {
            return false;
        }

        if ($user->hasRole('sds') || $this->isOsdsSecretary($user)) {
            return $routing->is_sds_routing;
        }

        if ($user->hasRole('division_chief')) {
            return $routing->routed_by === $user->id
                && $recipient->status === RecipientStatus::Pending;
        }

        return false;
    }

    /**
     * The SDS's own secretary — same identification as
     * DocumentPolicy::isOsdsSecretary() (kept as a separate private copy here
     * rather than shared, matching how isOsdsSecretary() is duplicated
     * per-class elsewhere in this codebase rather than shared).
     */
    private function isOsdsSecretary(User $user): bool
    {
        if (! $user->hasRole('secretary') || $user->division_id === null) {
            return false;
        }

        return $user->division?->code === 'OSDS' || $user->division?->parent?->code === 'OSDS';
    }
}
