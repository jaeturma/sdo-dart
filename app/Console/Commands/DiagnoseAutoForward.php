<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use App\Services\IncomingAutoForwarder;
use Illuminate\Console\Command;

/**
 * Diagnoses why Records Section's "Receiving" tab might be missing documents
 * uploaded by the SDS's secretary. The auto-forward (IncomingDocumentController::store(),
 * gated by IncomingAutoForwarder::appliesTo()) and Records Section's own
 * visibility (DocumentScopesTrait::isRecordsSectionMember(), matched by
 * division id === Division::recordsSectionId()) both depend on an office
 * being designated as the Records Section and on the right users being
 * attached to it — if not, the mismatch fails silently for Records Section
 * staff: the document just never shows up in their tabs.
 *
 * Defaults to a dry-run report. With --apply, it retroactively auto-forwards
 * any of the secretary's uploads left stuck at Received (typically uploaded
 * before a Records Section was designated) — via the same
 * IncomingAutoForwarder used by a live upload, so the backfilled routings are
 * indistinguishable from a normal auto-forward. Documents that already have
 * ANY routing (i.e. were already handled manually) are deliberately left
 * untouched, to avoid creating a conflicting second routing round.
 */
class DiagnoseAutoForward extends Command
{
    protected $signature = 'documents:diagnose-auto-forward
        {--apply : Retroactively auto-forward the SDS secretary\'s documents stuck at Received}';

    protected $description = 'Report (and optionally fix) Records Section/secretary misconfigurations that silently prevent incoming uploads from auto-forwarding to Records Section.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $foundIssue = false;

        $foundIssue = $this->checkRecordsSectionDivision() || $foundIssue;
        $foundIssue = $this->checkRecordsSectionUsers() || $foundIssue;
        $foundIssue = $this->checkSecretaries($apply) || $foundIssue;

        $this->newLine();
        if ($foundIssue) {
            $this->warn($apply
                ? 'Issues found above — see individual sections for what was (or could not be) fixed automatically.'
                : 'Issues found above. This was a dry run — nothing was changed. Re-run with --apply to backfill stuck documents, or fix remaining cases from the Offices/Users admin screens.');

            return self::FAILURE;
        }

        $this->info('No misconfiguration found — a Records Section is designated, staff are assigned to it, and no secretary upload is stuck unforwarded.');

        return self::SUCCESS;
    }

    private function checkRecordsSectionDivision(): bool
    {
        $this->line('== Records Section office ==');

        $canonicalId = Division::recordsSectionId();
        $candidates = Division::where('name', 'like', '%Records%')->get(['id', 'name', 'code', 'parent_id']);

        if (! $canonicalId) {
            $this->error('No office is designated as the Records Section — Division::recordsSectionId() returns null. Records Section visibility (the Receiving/Acknowledged/Ready-to-release/Released tabs) is unreachable for everyone until an admin designates one (Offices > Edit > "This Office Is the Records Section").');
        } else {
            $this->info("Designated Records Section office: id={$canonicalId}.");
        }

        $duplicates = $candidates->where('id', '!==', $canonicalId);
        if ($duplicates->isNotEmpty()) {
            $this->warn('Found office(s) named like "Records" that are NOT the designated one — any user attached to these will not be treated as Records Section:');
            $this->printDivisions($duplicates);

            return true;
        }

        return ! $canonicalId;
    }

    private function checkRecordsSectionUsers(): bool
    {
        $this->newLine();
        $this->line('== Users who look like Records Section staff ==');

        $canonicalId = Division::recordsSectionId();
        $recordsLikeDivisionIds = Division::where('name', 'like', '%Records%')->pluck('id');

        $misassigned = User::whereIn('division_id', $recordsLikeDivisionIds)
            ->when($canonicalId, fn ($q) => $q->where('division_id', '!=', $canonicalId))
            ->get(['id', 'name', 'email', 'division_id']);

        if ($misassigned->isNotEmpty()) {
            $this->warn('User(s) sitting in a "Records"-named office that is NOT the designated one — they will not see the Receiving/Acknowledged/Ready-to-release/Released tabs at all:');
            foreach ($misassigned as $u) {
                $this->line("  - #{$u->id} {$u->name} <{$u->email}> — division_id={$u->division_id}");
            }

            return true;
        }

        if (! $canonicalId) {
            // Already reported above — and where('division_id', null) would
            // otherwise "find" every user who has no office at all.
            $this->comment('Skipped — no Records Section office is designated.');

            return false;
        }

        $current = User::where('division_id', $canonicalId)->get(['id', 'name', 'email']);
        if ($current->isEmpty()) {
            $this->warn('No user is currently assigned to the designated Records Section office — nobody will ever see the Receiving tab.');

            return true;
        }

        $this->info('Correctly assigned: '.$current->map(fn ($u) => "{$u->name} <{$u->email}>")->implode(', '));

        return false;
    }

    private function checkSecretaries(bool $apply): bool
    {
        $this->newLine();
        $this->line('== Secretaries and their auto-forward eligibility ==');

        $foundIssue = false;
        $toBackfill = collect();

        $secretaries = User::role('secretary')->with('division.parent')->get();

        if ($secretaries->isEmpty()) {
            $this->comment('No users with the "secretary" role found.');

            return false;
        }

        foreach ($secretaries as $secretary) {
            $division = $secretary->division;
            $autoForwards = IncomingAutoForwarder::appliesTo($secretary);

            $this->line(sprintf(
                '  - #%d %s <%s> — office: %s (id=%s, code=%s) — auto-forward on upload: %s',
                $secretary->id,
                $secretary->name,
                $secretary->email,
                $division?->name ?? 'NONE',
                $division?->id ?? 'n/a',
                $division?->code ?? 'NULL',
                $autoForwards ? 'YES' : 'NO (not in OSDS or one of its sub-offices)',
            ));

            if (! $autoForwards) {
                continue;
            }

            // Only documents still sitting untouched at Received are safe to
            // backfill — anything else already has a routing of some kind
            // (handled manually), and auto-forwarding it now would create a
            // conflicting second routing round on top of whatever's already
            // in progress.
            $stuck = Document::where('type', 'incoming')
                ->where('uploaded_by', $secretary->id)
                ->where('status', DocumentStatus::Received)
                ->whereDoesntHave('routings')
                ->get(['id', 'title', 'created_at']);

            if ($stuck->isNotEmpty()) {
                $foundIssue = true;
                $this->warn("    Stuck at Received, never routed ({$stuck->count()} document(s)):");
                foreach ($stuck as $doc) {
                    $this->line("      #{$doc->id} \"{$doc->title}\" uploaded {$doc->created_at}");
                    $toBackfill->push(['document' => $doc, 'secretary' => $secretary]);
                }
            }
        }

        if ($apply && $toBackfill->isNotEmpty()) {
            $this->newLine();
            if ($this->confirm("Retroactively auto-forward {$toBackfill->count()} stuck document(s) to Records Section now?", false)) {
                $forwarded = 0;
                foreach ($toBackfill as $item) {
                    $document = $item['document']->fresh();
                    if (! $document || $document->status !== DocumentStatus::Received || $document->routings()->exists()) {
                        $this->comment("    Skipped #{$item['document']->id} — no longer in the expected state.");

                        continue;
                    }

                    if (IncomingAutoForwarder::forward($document, $item['secretary'])) {
                        $forwarded++;
                        $this->line("    Forwarded #{$document->id} \"{$document->title}\".");
                    } else {
                        $this->error("    Failed to forward #{$document->id} \"{$document->title}\" — see log.");
                    }
                }
                $this->info("Backfilled {$forwarded} of {$toBackfill->count()} document(s).");
            } else {
                $this->comment('Skipped — no documents were forwarded.');
            }
        }

        return $foundIssue;
    }

    private function printDivisions($divisions): void
    {
        $this->table(
            ['ID', 'Name', 'Code', 'Parent ID'],
            $divisions->map(fn ($d) => [$d->id, $d->name, $d->code ?? 'NULL', $d->parent_id ?? 'NULL']),
        );
    }
}
