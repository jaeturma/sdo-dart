<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reclaims disk space from soft-deleted documents. No restore feature
 * exists anywhere in this app, so deletion is already effectively
 * permanent — but the physical PDF(s) (original upload + every signed
 * version + every release-stamped version) were never being removed. This
 * command deletes those files, once a document has been soft-deleted for
 * longer than config('documents.file_retention_days'), giving a grace
 * period in case a delete needs to be undone via direct DB access.
 *
 * The documents/document_signatures/document_release_stamps DB rows are
 * NEVER touched by this command — only file bytes are reclaimed. This is
 * deliberate: control numbers, review history, and the signature audit
 * trail all remain intact and queryable forever, matching how the rest of
 * this app treats deletion as row-level, not history-erasing.
 *
 * Unlike the other backfill/fix commands in this directory, this one has
 * NO interactive confirm() prompt — it's meant to run unattended daily via
 * the scheduler (see routes/console.php), where a prompt would either hang
 * or rely on a fragile non-interactive default-answer behavior. --apply is
 * the only gate, and the dry-run report is always shown first regardless.
 */
class PurgeDeletedDocumentFiles extends Command
{
    protected $signature = 'documents:purge-deleted-files
        {--apply : Actually delete matched files and mark them purged (omit for a dry-run report only)}
        {--days= : Override config(\'documents.file_retention_days\') for this run}';

    protected $description = 'Delete the physical files of documents soft-deleted past the retention window (DB rows are never touched).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('documents.file_retention_days');

        $documents = Document::onlyTrashed()
            ->whereNull('files_purged_at')
            ->where('deleted_at', '<=', now()->subDays($days))
            ->with(['signatures', 'releaseStamps'])
            ->orderBy('deleted_at')
            ->get();

        if ($documents->isEmpty()) {
            $this->info("No soft-deleted documents past the {$days}-day retention window need purging.");

            return self::SUCCESS;
        }

        $this->table(
            ['Document ID', 'Title', 'Deleted At', 'File Count'],
            $documents->map(fn (Document $d) => [$d->id, $d->title, $d->deleted_at->toDateString(), count($this->filePathsFor($d))]),
        );

        if (! $apply) {
            $this->newLine();
            $this->info($documents->count().' document(s) would have their files purged. Re-run with --apply to actually delete them.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('documents');
        $purged = 0;
        $failed = 0;

        foreach ($documents as $document) {
            $ok = true;

            foreach ($this->filePathsFor($document) as $path) {
                try {
                    if ($disk->exists($path) && ! $disk->delete($path)) {
                        $ok = false;
                        Log::warning('documents:purge-deleted-files could not delete a file.', ['document_id' => $document->id, 'path' => $path]);
                    }
                } catch (\Throwable $e) {
                    $ok = false;
                    Log::warning('documents:purge-deleted-files failed to delete a file.', ['document_id' => $document->id, 'path' => $path, 'error' => $e->getMessage()]);
                }
            }

            // Marked purged even on a partial failure — a file that was
            // already gone isn't a failure, and a genuine delete failure is
            // logged for manual follow-up but must not make this command
            // retry the same document forever. The DB columns this touches
            // (files_purged_at only) are safe regardless of file state.
            $document->forceFill(['files_purged_at' => now()])->save();
            AuditLogger::log(null, $document, 'document.files_purged');

            $ok ? $purged++ : $failed++;
        }

        $this->newLine();
        $this->info("Done. {$purged} document(s) fully purged".($failed ? ", {$failed} with at least one file-delete failure (see storage/logs)." : '.'));

        return self::SUCCESS;
    }

    /**
     * Every physical file tied to this document: the original upload plus
     * every historical signed and release-stamped version — not just the
     * latest of each, since sign→undo→re-sign cycles leave prior signed
     * PDFs on disk too.
     */
    private function filePathsFor(Document $document): array
    {
        return collect([$document->file_path])
            ->merge($document->signatures->pluck('signed_file_path'))
            ->merge($document->releaseStamps->pluck('stamped_file_path'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
