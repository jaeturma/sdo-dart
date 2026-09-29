<?php

namespace App\Console\Commands;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\ControlNumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for documents.control_number, for documents created
 * before this feature existed. Groups documents into the exact same scopes
 * a live document would use (outgoing: division code — own, else parent's,
 * else 'GEN' — plus year; incoming: year only), orders each group by
 * created_at then id, and assigns numbers in that order using the same
 * ControlNumberGenerator::next() atomic counter a live submission uses —
 * so a new document created after this runs continues the sequence with
 * no gap or collision.
 *
 * Defaults to a dry-run report; pass --apply to actually write. The dry-run
 * preview never calls next() (it uses peek() plus a local simulation), so
 * running it repeatedly — or running it and never applying — can never
 * skip series numbers.
 */
class BackfillControlNumbers extends Command
{
    protected $signature = 'documents:backfill-control-numbers
        {--apply : Actually assign control numbers on the matched documents (omit for a dry-run report only)}';

    protected $description = 'Backfill documents.control_number for documents created before this feature existed.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $outgoing = Document::withTrashed()
            ->where('type', DocumentType::Outgoing)
            ->whereNull('control_number')
            ->with('division.parent')
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->groupBy(fn (Document $d) => ControlNumberGenerator::scopeForOutgoing(
                ControlNumberGenerator::resolveDivisionCode($d->division),
                (int) $d->created_at->format('Y'),
            ));

        $incoming = Document::withTrashed()
            ->where('type', DocumentType::Incoming)
            ->whereNull('control_number')
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->groupBy(fn (Document $d) => ControlNumberGenerator::scopeForIncoming((int) $d->created_at->format('Y')));

        if ($outgoing->isEmpty() && $incoming->isEmpty()) {
            $this->info('No documents need a control number — nothing to do.');

            return self::SUCCESS;
        }

        $preview = $this->buildPreview($outgoing, $incoming);

        $this->table(
            ['Scope', 'Doc ID', 'Type', 'Title', 'Created At', 'Control Number'],
            $preview->map(fn ($r) => [$r['scope'], $r['id'], $r['type'], $r['title'], $r['created_at'], $r['control_number']]),
        );

        if (! $apply) {
            $this->newLine();
            $this->info($preview->count() . ' document(s) would be assigned a control number. Re-run with --apply to write them.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Assign control numbers to ' . $preview->count() . ' document(s) now?', false)) {
            $this->comment('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        // All-or-nothing: if any single assignment fails partway through
        // (e.g. a bug, or a genuine duplicate), everything rolls back rather
        // than leaving some documents numbered and others not — re-running
        // the command afterwards then starts from a clean, fully-untouched
        // state instead of a partial mess.
        DB::transaction(function () use ($outgoing, $incoming) {
            foreach ($outgoing as $documents) {
                foreach ($documents as $document) {
                    $document->update(['control_number' => ControlNumberGenerator::forOutgoing($document->division, $document->created_at)]);
                }
            }

            foreach ($incoming as $documents) {
                foreach ($documents as $document) {
                    $document->update(['control_number' => ControlNumberGenerator::forIncoming($document->created_at)]);
                }
            }
        });

        $this->info($preview->count() . ' document(s) updated.');

        return self::SUCCESS;
    }

    /**
     * Read-only simulation of the sequence --apply will produce — never
     * calls next(), only peek(), so a dry-run can never consume a series
     * number.
     */
    private function buildPreview($outgoing, $incoming): \Illuminate\Support\Collection
    {
        $rows = collect();
        $localLastNumber = [];

        foreach ($outgoing as $scope => $documents) {
            $localLastNumber[$scope] ??= ControlNumberGenerator::peek($scope);
            foreach ($documents as $document) {
                $series = ++$localLastNumber[$scope];
                $code = ControlNumberGenerator::resolveDivisionCode($document->division);
                $rows->push([
                    'scope'          => $scope,
                    'id'             => $document->id,
                    'type'           => 'outgoing',
                    'title'          => $document->title,
                    'created_at'     => $document->created_at->toDateString(),
                    'control_number' => ControlNumberGenerator::formatOutgoing(
                        $code,
                        (int) $document->created_at->format('Y'),
                        (int) $document->created_at->format('m'),
                        $series,
                    ),
                ]);
            }
        }

        foreach ($incoming as $scope => $documents) {
            $localLastNumber[$scope] ??= ControlNumberGenerator::peek($scope);
            foreach ($documents as $document) {
                $series = ++$localLastNumber[$scope];
                $rows->push([
                    'scope'          => $scope,
                    'id'             => $document->id,
                    'type'           => 'incoming',
                    'title'          => $document->title,
                    'created_at'     => $document->created_at->toDateString(),
                    'control_number' => ControlNumberGenerator::formatIncoming(
                        (int) $document->created_at->format('Y'),
                        (int) $document->created_at->format('m'),
                        $series,
                    ),
                ]);
            }
        }

        return $rows;
    }
}
