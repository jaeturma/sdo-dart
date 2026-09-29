<?php

namespace App\Services;

use App\Models\Division;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The full set of users a given user may route an incoming document to.
 * Shared by DocumentRoutingController::candidateUsers() (populates the
 * routing form's candidate list) and RouteDocumentRequest (validates a
 * submitted routing request's recipients) so the two can never drift apart
 * — previously only the division-chief/unit-head branch was validated
 * server-side, letting an RD/ARD/secretary request route to an arbitrary
 * user id with no role/division/active-status check at all.
 */
class RoutingCandidateResolver
{
    // Prescribed display order for division chiefs
    private const CHIEF_ORDER = ['CLMD', 'ESSD', 'QAD', 'FTAD', 'HRDD', 'PPRD', 'AD', 'FD'];

    public static function forUser(User $user): Collection
    {
        if ($user->hasRole('division_chief') || self::isDirectRoutableUnitHead($user)) {
            // A division chief (or a unit head whose division has been
            // flagged allow_rd_direct_routing — e.g. ICTU/LU/PAU, or any
            // other unit an admin has opted in via Office Management, who
            // the RD routes to directly the same way she routes to chiefs)
            // may only forward to their own staff (their division plus any
            // of its sub-units — a no-op for a unit head, whose division has
            // none), not to peer chiefs or RD/ARD — and only to staff whose
            // account is currently active, since a deactivated user can't
            // log in to act on it anyway.
            return User::with(['division:id,name,code', 'roles'])
                ->where('id', '!=', $user->id)
                ->where('is_active', true)
                ->whereIn('division_id', Division::selfAndChildrenIds($user->division_id))
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['originator', 'unit_head']))
                ->orderBy('name')
                ->get(['id', 'name', 'division_id']);
        }

        // The ARD — offered alongside chiefs/unit heads so the RD (or her ORD
        // secretary) can route directly to her too. Placed first in the list
        // since she outranks every other candidate here. Excluded for the
        // ARD's own routing turn via the `id != $user->id` filter, same as
        // everyone else in this list. `is_active` is required on every branch
        // here — a deactivated ARD/chief/unit-head can't log in to act on a
        // routing assigned to them.
        $ard = User::with(['division:id,name,code', 'roles'])
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'ard'))
            ->get(['id', 'name', 'division_id']);

        // Division chiefs — sorted by prescribed division order
        $chiefs = User::with(['division:id,name,code', 'roles'])
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'division_chief'))
            ->get(['id', 'name', 'division_id'])
            ->sortBy(fn ($u) => ($pos = array_search($u->division?->code, self::CHIEF_ORDER)) !== false ? $pos : 999)
            ->values();

        // Unit heads whose division has been admin-flagged
        // allow_rd_direct_routing (Office Management — see
        // DivisionController) — sorted alphabetically by division name since
        // there's no prescribed fixed order the way CHIEF_ORDER has for this
        // admin-extensible set.
        $unitHeads = User::with(['division:id,name,code', 'roles'])
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'unit_head'))
            ->whereHas('division', fn ($q) => $q->where('allow_rd_direct_routing', true))
            ->get(['id', 'name', 'division_id'])
            ->sortBy(fn ($u) => $u->division?->name)
            ->values();

        $candidates = $ard->merge($chiefs)->merge($unitHeads);

        // Staff temporarily detailed to the Regional Office (e.g. from a
        // School Division Office) who don't fit anywhere in the division
        // hierarchy — offered only to the RD and her ORD secretary, not the
        // ARD, who isn't part of this shared "acts as the RD" pool anywhere
        // else in this codebase either (see DocumentPolicy::route()'s
        // is_rd_routing treatment).
        if ($user->hasRole('rd') || self::isOrdSecretary($user)) {
            $detailedStaff = User::with(['division:id,name,code', 'roles'])
                ->where('id', '!=', $user->id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('name', 'detailed_staff'))
                ->orderBy('name')
                ->get(['id', 'name', 'division_id']);

            $candidates = $candidates->merge($detailedStaff);
        }

        return $candidates->values();
    }

    /**
     * The RD's own secretary — same identification as
     * DocumentPolicy::isOrdSecretary() (kept as a separate private copy here
     * rather than shared, matching how isDirectRoutableUnitHead() below is
     * also duplicated per-class rather than shared).
     */
    private static function isOrdSecretary(User $user): bool
    {
        if (! $user->hasRole('secretary') || $user->division_id === null) {
            return false;
        }

        $division = $user->division;

        return $division?->code === 'ORD' || $division?->parent?->code === 'ORD';
    }

    /**
     * A unit head whose division has been admin-flagged
     * allow_rd_direct_routing (e.g. ICTU/LU/PAU, or any other unit opted in
     * via Office Management) — same identification as
     * DocumentPolicy::isDirectRoutableUnitHead() (kept as a separate private
     * copy here rather than shared, matching how isOrdSecretary() is
     * duplicated per-class elsewhere in this codebase rather than shared).
     */
    private static function isDirectRoutableUnitHead(User $user): bool
    {
        if (! $user->hasRole('unit_head') || $user->division_id === null) {
            return false;
        }

        return (bool) $user->division?->allow_rd_direct_routing;
    }
}
