<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Mirrors DocumentPolicy::review()'s match arms, but as a query returning
 * every eligible user instead of checking one specific user. Keep the two in
 * sync by hand — the shapes differ enough (policy checks a User, this queries
 * the users table) that forcing a shared abstraction would hurt readability
 * more than the duplication hurts maintainability.
 */
class ReviewerResolver
{
    /** @return Collection<int, User> */
    public static function forStatus(Document $document): Collection
    {
        return match ($document->status) {
            DocumentStatus::PendingUnitHeadReview => User::role('unit_head')
                ->where('division_id', $document->division_id)
                ->get(),
            DocumentStatus::Submitted,
            DocumentStatus::PendingDivisionChiefReview => User::role('division_chief')
                ->where(fn ($q) => $q->where('division_id', $document->division_id)
                    ->orWhere('division_id', Division::where('id', $document->division_id)->value('parent_id')))
                ->get(),
            DocumentStatus::PendingAsdsReview => User::role('asds')->get(),
            DocumentStatus::PendingSdsReview  => User::role('sds')->get(),
            default => collect(),
        };
    }
}
