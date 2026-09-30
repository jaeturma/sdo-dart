<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecipientStatus;
use App\Models\Document;
use App\Models\DocumentRoutingRecipient;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Retroactive fix for the "no Route Document button" bug: before this
 * codebase gave OSDS unit heads (ICTU/LU/PAU) the same "acknowledge without
 * auto-completing" deferral a division chief gets (see
 * DocumentRoutingRecipientController::acknowledge()'s isDirectRoutableUnitHead()
 * branch — called isOsdsUnitHead() at the time this bug existed; the OSDS-only
 * rule has since been generalized into an admin-configurable per-division
 * flag, but that's irrelevant here since this command only detects
 * historically-corrupted rows from before either version of the deferral
 * existed at all), an OSDS unit head's acknowledgement went straight to
 * Completed in one step — exactly like an ordinary staff recipient. If that
 * was the document's
 * only recipient, DocumentRoutingRecipientController::promoteDocumentIfFullyCompleted()
 * then immediately promoted the whole document to Completed, permanently
 * hiding "Route Document" (DocumentPolicy::route()'s status guard only
 * allows Received/PendingRouting/Routed) before the unit head ever got a
 * chance to forward it to their own staff.
 *
 * Deploying the code fix only prevents this for *future* acknowledgements —
 * it does nothing for documents that already got stuck this way. This
 * command finds and (with --apply) reverts them.
 *
 * Detection relies on a reliable fingerprint: the old single-step code path
 * set `acknowledged_at` and `completed_at` to the exact same timestamp (one
 * `now()` call reused for both columns). The fixed deferred path always sets
 * them at genuinely different times (acknowledge, then a separate later
 * complete() call or routing-onward auto-close) — so this only catches rows
 * that went through the old buggy path, never a legitimately-completed one.
 *
 * Scoped to recipients who are CURRENTLY a division_chief or OSDS unit head
 * (the roles that get this deferral) — an ordinary staff recipient
 * completing in one step is correct, expected behavior, not a bug.
 */
class FixPrematureRoutingCompletions extends Command
{
    protected $signature = 'documents:fix-premature-routing-completions
        {--apply : Actually revert affected recipients/documents (omit for a dry-run report only)}';

    protected $description = 'Find (and optionally fix) incoming documents stuck at Completed because a division chief/OSDS unit head auto-completed before the routing-deferral fix existed.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $affected = Document::where('type', DocumentType::Incoming)
            ->where('status', DocumentStatus::Completed)
            ->whereHas('routings.recipients', fn ($q) => $this->suspectRecipientQuery($q))
            ->with(['routings.recipients.user.roles', 'routings.recipients.user.division.parent'])
            ->get();

        if ($affected->isEmpty()) {
            $this->info('No affected documents found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($affected as $document) {
            foreach ($document->routings as $routing) {
                foreach ($routing->recipients as $recipient) {
                    if ($this->isSuspectRecipient($recipient)) {
                        $rows[] = [$document->id, $document->title, $recipient->user?->name ?? '—', $recipient->acknowledged_at];
                    }
                }
            }
        }

        $this->table(['Document ID', 'Title', 'Stuck Recipient', 'Acknowledged/Completed At'], $rows);

        if (! $apply) {
            $this->newLine();
            $this->info(count($rows).' document(s) would be reverted to Routed. Re-run with --apply to actually fix them.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Revert the '.$affected->count().' document(s) listed above to Routed now?', false)) {
            $this->comment('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($affected as $document) {
            $reverted = false;

            foreach ($document->routings as $routing) {
                foreach ($routing->recipients as $recipient) {
                    if (! $this->isSuspectRecipient($recipient)) {
                        continue;
                    }

                    $recipient->update([
                        'status' => RecipientStatus::Acknowledged,
                        'completed_at' => null,
                        'completed_by' => null,
                    ]);

                    $reverted = true;
                }
            }

            if ($reverted) {
                $document->update(['status' => DocumentStatus::Routed]);
                AuditLogger::log(null, $document, 'document.premature_completion_reverted');
                $fixed++;
                $this->line("#{$document->id} \"{$document->title}\" — reverted to Routed.");
            }
        }

        $this->newLine();
        $this->info("Done. {$fixed} document(s) reverted.");

        return self::SUCCESS;
    }

    private function suspectRecipientQuery($q)
    {
        return $q->whereColumn('acknowledged_at', 'completed_at')
            ->whereNotNull('completed_at')
            ->where('status', RecipientStatus::Completed->value)
            ->whereHas('user.roles', fn ($r) => $r->whereIn('name', ['division_chief', 'unit_head']));
    }

    private function isSuspectRecipient(DocumentRoutingRecipient $recipient): bool
    {
        if ($recipient->status !== RecipientStatus::Completed
            || $recipient->completed_at === null
            || $recipient->acknowledged_at === null
            || ! $recipient->acknowledged_at->equalTo($recipient->completed_at)) {
            return false;
        }

        $user = $recipient->user;
        if (! $user) {
            return false;
        }

        if ($user->hasRole('division_chief')) {
            return true;
        }

        return $user->hasRole('unit_head')
            && $user->division_id !== null
            && $user->division?->parent?->code === 'OSDS';
    }
}
