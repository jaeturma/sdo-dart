<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Http\Controllers\IncomingDocumentController;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use App\Services\IncomingAutoForwarder;
use Illuminate\Console\Command;

/**
 * Diagnoses why Records Section's "Receiving" tab might be missing documents
 * uploaded by the ORD Proper secretary. Both the auto-forward trigger
 * (IncomingDocumentController::store(), matched by division code === 'ORDP')
 * and Records Section's own visibility (DocumentScopesTrait::isRecordsSectionMember(),
 * matched by division id === Division::recordsSectionId()) depend on a user
 * being attached to the correct, canonically-coded division row — not just a
 * division with the right name. As CLAUDE.md documents, this codebase's dev
 * DB has accumulated near-duplicate division rows over time (e.g. two
 * "ORD Proper"-ish rows, only one with a code); if a real secretary or
 * Records Section user ends up on an uncoded duplicate, the mismatch fails
 * completely silently — no error, no warning, the document just never shows
 * up.
 *
 * Defaults to a dry-run report. With --apply, when there is exactly one
 * unambiguous uncoded "ORD Proper" division and no canonical ORDP division
 * already exists, it codes that division "ORDP" and then retroactively
 * auto-forwards any documents left stuck at Received because of the
 * misconfiguration — via the same IncomingAutoForwarder used by a live
 * upload, so the backfilled routings are indistinguishable from a normal
 * auto-forward. Documents that already have ANY routing (i.e. were already
 * handled manually despite the bug) are deliberately left untouched, to
 * avoid creating a conflicting second routing round.
 */
class DiagnoseAutoForward extends Command
{
    protected $signature = 'documents:diagnose-auto-forward
        {--apply : Fix an unambiguous uncoded ORD Proper division and retroactively auto-forward documents stuck at Received because of it}';

    protected $description = 'Report (and optionally fix) division/user misconfigurations that silently prevent ORD Proper incoming uploads from auto-forwarding to Records Section.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $foundIssue = false;

        $foundIssue = $this->checkRecordsSectionDivision() || $foundIssue;
        $foundIssue = $this->checkOrdProperDivision($apply) || $foundIssue;
        $foundIssue = $this->checkRecordsSectionUsers() || $foundIssue;
        $foundIssue = $this->checkOrdProperSecretaries($apply) || $foundIssue;

        $this->newLine();
        if ($foundIssue) {
            $this->warn($apply
                ? 'Issues found above — see individual sections for what was (or could not be) fixed automatically.'
                : 'Issues found above. This was a dry run — nothing was changed. Re-run with --apply to fix an unambiguous ORD Proper division code and backfill stuck documents, or fix remaining cases manually (`php artisan tinker` or the /users admin UI).');

            return self::FAILURE;
        }

        $this->info('No misconfiguration found — Records Section and ORD Proper both resolve to a single, correctly-coded division, and every relevant user is attached to it.');

        return self::SUCCESS;
    }

    private function checkRecordsSectionDivision(): bool
    {
        $this->line('== Records Section division ==');

        $canonicalId = Division::recordsSectionId();
        $candidates = Division::where('name', 'like', '%Records%')->get(['id', 'name', 'code', 'parent_id']);

        if (! $canonicalId) {
            $this->error('No division is coded "RS" — Division::recordsSectionId() returns null. Records Section visibility (the Receiving/Acknowledged/Ready-to-release/Released tabs) is unreachable for everyone until a division is coded "RS".');
        } else {
            $this->info("Canonical Records Section division: id={$canonicalId}.");
        }

        $duplicates = $candidates->where('id', '!==', $canonicalId);
        if ($duplicates->isNotEmpty()) {
            $this->warn('Found division row(s) named like "Records" that are NOT the canonical one — any user attached to these will not be treated as Records Section:');
            $this->printDivisions($duplicates);

            return true;
        }

        return ! $canonicalId;
    }

    private function checkOrdProperDivision(bool $apply): bool
    {
        $this->newLine();
        $this->line('== ORD Proper division ==');

        $ordId = Division::where('code', 'ORD')->value('id');
        $canonical = Division::where('code', 'ORDP')->first();

        $suspects = Division::where(function ($q) use ($ordId) {
            $q->where('name', 'like', '%Proper%');
            if ($ordId) {
                $q->orWhere('parent_id', $ordId);
            }
        })
            ->get(['id', 'name', 'code', 'parent_id'])
            ->filter(fn ($d) => $d->name === 'Office of the Regional Director Proper' || str_contains(strtolower($d->name), 'proper'))
            ->where('id', '!==', $canonical?->id);

        if ($canonical) {
            $this->info("Canonical ORD Proper division: id={$canonical->id}.");

            if ($suspects->isNotEmpty()) {
                $this->warn('Found ORD-child division row(s) that ALSO look like "ORD Proper" but are not the canonical one — a secretary attached to one of these will upload incoming documents that never auto-forward:');
                $this->printDivisions($suspects);

                return true;
            }

            return false;
        }

        $this->error('No division is coded "ORDP" — no ORD Proper upload will ever auto-forward to Records Section, regardless of who uploads it.');

        if ($suspects->isEmpty()) {
            $this->warn('No candidate division found either (nothing named like "ORD Proper") — this needs to be set up from scratch.');

            return true;
        }

        $this->warn('Found ORD-child division row(s) that look like "ORD Proper" but are NOT coded "ORDP":');
        $this->printDivisions($suspects);

        if (! $apply) {
            return true;
        }

        if ($suspects->count() > 1) {
            $this->error('More than one candidate — refusing to guess which one is real. Fix manually, then re-run.');

            return true;
        }

        $target = $suspects->first();
        if (! $this->confirm("Code division #{$target->id} \"{$target->name}\" as \"ORDP\" now?", false)) {
            $this->comment('Skipped — division left unchanged.');

            return true;
        }

        $target->update(['code' => 'ORDP']);
        $this->info("Division #{$target->id} is now coded \"ORDP\". Future uploads by staff in this division will auto-forward.");

        return true;
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
            $this->warn('User(s) sitting in a "Records"-named division that is NOT the canonical one — they will not see the Receiving/Acknowledged/Ready-to-release/Released tabs at all:');
            foreach ($misassigned as $u) {
                $this->line("  - #{$u->id} {$u->name} <{$u->email}> — division_id={$u->division_id}");
            }

            return true;
        }

        $current = User::where('division_id', $canonicalId)->get(['id', 'name', 'email']);
        if ($current->isEmpty()) {
            $this->warn('No user is currently assigned to the canonical Records Section division — nobody will ever see the Receiving tab.');

            return true;
        }

        $this->info('Correctly assigned: ' . $current->map(fn ($u) => "{$u->name} <{$u->email}>")->implode(', '));

        return false;
    }

    private function checkOrdProperSecretaries(bool $apply): bool
    {
        $this->newLine();
        $this->line('== Secretaries and their auto-forward eligibility ==');

        $foundIssue = false;
        $toBackfill = collect();

        $secretaries = User::role('secretary')->with('division')->get();

        if ($secretaries->isEmpty()) {
            $this->comment('No users with the "secretary" role found.');

            return false;
        }

        foreach ($secretaries as $secretary) {
            // Re-fetch rather than trust the eager-loaded relation — checkOrdProperDivision()
            // may have just changed this secretary's division's code moments ago.
            $division = $secretary->division()->first();
            $code = $division?->code;
            $autoForwards = $code === 'ORDP';

            $this->line(sprintf(
                '  - #%d %s <%s> — division: %s (id=%s, code=%s) — auto-forward on upload: %s',
                $secretary->id,
                $secretary->name,
                $secretary->email,
                $division?->name ?? 'NONE',
                $division?->id ?? 'n/a',
                $code ?? 'NULL',
                $autoForwards ? 'YES' : 'NO',
            ));

            // Only documents still sitting untouched at Received are safe to
            // backfill — anything else already has a routing of some kind
            // (handled manually despite the bug), and auto-forwarding it now
            // would create a conflicting second routing round on top of
            // whatever's already in progress.
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
                }

                if ($autoForwards) {
                    foreach ($stuck as $doc) {
                        $toBackfill->push(['document' => $doc, 'secretary' => $secretary]);
                    }
                } else {
                    $this->comment('    Not backfilled — this secretary is still on a division that does not auto-forward.');
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
