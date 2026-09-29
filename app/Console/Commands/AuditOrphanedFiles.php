<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentReleaseStamp;
use App\Models\DocumentSignature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reclaims disk space already wasted by two pre-existing gaps: outgoing
 * resubmission storing a new file and overwriting documents.file_path
 * without ever deleting the old one (fixed going forward in
 * OutgoingDocumentController::updateResubmit(), but this backlog predates
 * that fix), and any file left behind by an interrupted PdfNormalizer run
 * (a stray *.normalized.tmp if the process was killed mid-write, e.g. by
 * disk exhaustion). Neither case leaves any DB reference at all, so this
 * has to work by diffing disk contents against every path referenced
 * anywhere, not by querying a "leaked" flag.
 *
 * A file is only ever considered orphaned if it matches NONE of:
 * documents.file_path (including soft-deleted, not-yet-purged rows —
 * see documents:purge-deleted-files for those), document_signatures.
 * signed_file_path, document_release_stamps.stamped_file_path.
 *
 * This is a manually-run, one-off backlog tool (matches
 * documents:backfill-control-numbers/documents:normalize-legacy-pdfs — a
 * human runs it at a terminal, with a confirm() prompt), not something
 * scheduled to run automatically.
 */
class AuditOrphanedFiles extends Command
{
    protected $signature = 'documents:audit-orphaned-files
        {--apply : Actually delete orphaned files found (omit for a dry-run report only)}';

    protected $description = 'Find (and optionally delete) files on the documents disk with no matching file_path/signed_file_path/stamped_file_path anywhere in the database.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $disk = Storage::disk('documents');

        $referenced = collect()
            ->merge(Document::withTrashed()->pluck('file_path'))
            ->merge(DocumentSignature::pluck('signed_file_path'))
            ->merge(DocumentReleaseStamp::pluck('stamped_file_path'))
            ->filter()
            ->unique()
            ->flip();

        $orphaned = collect($disk->allFiles())
            ->reject(fn (string $path) => $referenced->has($path));

        if ($orphaned->isEmpty()) {
            $this->info('No orphaned files found — every file on the documents disk is referenced by a document, signature, or release stamp.');

            return self::SUCCESS;
        }

        $rows = $orphaned->map(fn (string $path) => [
            $path,
            number_format(($disk->size($path) ?: 0) / 1024 / 1024, 2).' MB',
            date('Y-m-d H:i', $disk->lastModified($path) ?: 0),
        ]);

        $this->table(['Path', 'Size', 'Last Modified'], $rows);

        $totalBytes = $orphaned->sum(fn (string $path) => $disk->size($path) ?: 0);
        $this->newLine();
        $this->info($orphaned->count().' orphaned file(s) found, totalling '.number_format($totalBytes / 1024 / 1024, 2).' MB.');

        if (! $apply) {
            $this->info('Re-run with --apply to delete them.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Delete the '.$orphaned->count().' orphaned file(s) listed above now?', false)) {
            $this->comment('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($orphaned as $path) {
            if ($disk->delete($path)) {
                $deleted++;
            } else {
                $this->error("Failed to delete: {$path}");
            }
        }

        $this->newLine();
        $this->info("Done. {$deleted} file(s) deleted.");

        return self::SUCCESS;
    }
}
