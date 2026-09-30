<?php

namespace App\Console\Commands;

use App\Http\Controllers\IncomingDocumentController;
use App\Models\DocumentRouting;
use Illuminate\Console\Command;

/**
 * One-time backfill for `document_routings.is_sds_routing` (added after this
 * flag started being stamped at creation time instead of re-derived from the
 * router's current role/division — see DocumentPolicy::route()'s doc
 * comment). Existing rows created before that column existed all default to
 * false, which would make the SDS/her secretary see every already-routed
 * incoming document reappear in their queue again the moment this ships,
 * until this command runs.
 *
 * Detection: a routing counts as "SDS-tier" (real, deliberate, issued by the
 * SDS or an OSDS secretary acting in her lieu — never the auto-forward, never
 * a division chief/unit head's own onward routing) if:
 *   1. It's not the OSDS-Proper auto-forward (remarks check).
 *   2. Its own `routed_by` user was NOT a recipient of any earlier routing on
 *      the same document — i.e. it's a top-level dispatch, not someone
 *      forwarding a document that was routed to them first. This distinguishes
 *      SDS/ASDS/secretary dispatch from a chief/unit head's onward routing
 *      without depending on the router's current role at all.
 *   3. Its `routed_by` user's CURRENT role is `sds` or `secretary` (not `asds`
 *      — she stays independent, and isn't part of this shared flag; not
 *      anything else, since only sds/asds/secretary could ever have been
 *      authorized to issue a top-level routing in the first place).
 *
 * Any row matching #1 and #2 but NOT #3 (a top-level dispatch whose router's
 * current role is neither sds nor secretary — e.g. she's since been
 * reassigned away from the secretary role entirely, not just moved
 * divisions) is reported separately as needing manual review, since it can't
 * be safely auto-classified — pass --also-mark-ambiguous to include them too
 * if you've confirmed by other means (e.g. audit logs) that they were issued
 * by the SDS's secretary at the time.
 */
class BackfillSdsRoutingFlag extends Command
{
    protected $signature = 'documents:backfill-sds-routing-flag
        {--apply : Actually set is_sds_routing on the matched rows (omit for a dry-run report only)}
        {--also-mark-ambiguous : Also mark rows whose router now has neither the sds nor secretary role (requires manual confirmation of intent)}';

    protected $description = 'Backfill document_routings.is_sds_routing for routings issued before this flag existed.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $includeAmbiguous = (bool) $this->option('also-mark-ambiguous');

        $candidates = DocumentRouting::where('is_sds_routing', false)
            ->where(fn ($q) => $q->whereNull('remarks')
                ->orWhere('remarks', '!=', IncomingDocumentController::AUTO_FORWARD_REMARKS))
            ->with(['routedBy.roles', 'document'])
            ->get()
            ->filter(function (DocumentRouting $routing) {
                // Top-level dispatch only — the router must NOT have been a
                // recipient of an earlier routing on the same document.
                return ! $routing->document->routings()
                    ->where('id', '!=', $routing->id)
                    ->where('routed_at', '<', $routing->routed_at)
                    ->whereHas('recipients', fn ($q) => $q->where('user_id', $routing->routed_by))
                    ->exists();
            });

        if ($candidates->isEmpty()) {
            $this->info('No affected routings found.');

            return self::SUCCESS;
        }

        $clear = $candidates->filter(fn ($r) => $r->routedBy && $r->routedBy->hasAnyRole(['sds', 'secretary']));
        $ambiguous = $candidates->reject(fn ($r) => $r->routedBy && $r->routedBy->hasAnyRole(['sds', 'secretary']));

        $this->line('Top-level dispatch routings not yet flagged, router currently sds/secretary (will be marked):');
        $this->table(
            ['Routing ID', 'Document', 'Routed By', 'Router Role(s)', 'Routed At'],
            $clear->map(fn ($r) => [
                $r->id, "#{$r->document_id} \"{$r->document->title}\"", $r->routedBy?->name ?? '—',
                $r->routedBy?->getRoleNames()->implode(',') ?? '—', $r->routed_at,
            ]),
        );

        if ($ambiguous->isNotEmpty()) {
            $this->newLine();
            $this->warn('Top-level dispatch routings whose router is currently neither sds nor secretary — NOT auto-fixed (needs manual confirmation):');
            $this->table(
                ['Routing ID', 'Document', 'Routed By', 'Router Role(s)', 'Routed At'],
                $ambiguous->map(fn ($r) => [
                    $r->id, "#{$r->document_id} \"{$r->document->title}\"", $r->routedBy?->name ?? '(deleted user)',
                    $r->routedBy?->getRoleNames()->implode(',') ?? '—', $r->routed_at,
                ]),
            );
        }

        $toApply = $includeAmbiguous ? $candidates : $clear;

        if (! $apply) {
            $this->newLine();
            $this->info(count($toApply) . ' row(s) would be marked is_sds_routing = true. Re-run with --apply to fix them.');

            return self::SUCCESS;
        }

        if ($toApply->isEmpty()) {
            $this->info('Nothing to apply.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Mark ' . count($toApply) . ' routing(s) as is_sds_routing = true now?', false)) {
            $this->comment('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        DocumentRouting::whereIn('id', $toApply->pluck('id'))->update(['is_sds_routing' => true]);

        $this->info(count($toApply) . ' routing(s) updated.');

        return self::SUCCESS;
    }
}
