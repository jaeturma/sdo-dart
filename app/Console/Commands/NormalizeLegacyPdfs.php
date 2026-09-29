<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Services\PdfNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Retroactive fix for outgoing documents uploaded before PdfNormalizer
 * existed: any whose PDF uses PDF 1.5+ compressed cross-reference/object
 * streams will fail at DocumentSigner's setSourceFile() step exactly like a
 * fresh upload would have. Already-signed/released documents are excluded —
 * their signed PDF is a separate file (signed/released paths), so touching
 * the original upload here has no effect on it and no benefit.
 *
 * Defaults to a dry-run report; pass --apply to actually rewrite files.
 */
class NormalizeLegacyPdfs extends Command
{
    protected $signature = 'documents:normalize-legacy-pdfs
        {--apply : Actually rewrite affected files (omit for a dry-run report only)}
        {--limit= : Stop after finding/processing this many affected documents}';

    protected $description = 'Find outgoing documents (not yet signed/released) whose PDF uses compressed cross-reference/object streams that FPDI cannot sign, and rewrite them via mutool.';

    public function handle(PdfNormalizer $normalizer): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $apply = (bool) $this->option('apply');

        $affected = [];
        $missing = [];

        Document::where('type', DocumentType::Outgoing)
            ->whereNotIn('status', [DocumentStatus::Signed, DocumentStatus::Released])
            ->orderBy('id')
            ->with('uploader')
            ->chunkById(100, function ($documents) use (&$affected, &$missing, $limit) {
                foreach ($documents as $document) {
                    if ($limit !== null && count($affected) >= $limit) {
                        return false;
                    }

                    $absolutePath = Storage::disk('documents')->path($document->file_path);

                    if (! is_file($absolutePath)) {
                        $missing[] = $document;
                        continue;
                    }

                    if ($this->usesCompressedXref($absolutePath)) {
                        $affected[] = $document;
                    }
                }
            });

        if ($missing) {
            $this->warn(count($missing) . ' document(s) reference a file that no longer exists on disk (skipped):');
            foreach ($missing as $document) {
                $this->line("  - #{$document->id} \"{$document->title}\" ({$document->file_path})");
            }
            $this->newLine();
        }

        if (! $affected) {
            $this->info('No affected documents found — nothing uses compressed cross-reference/object streams.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Title', 'Status', 'Uploaded By', 'File Path'],
            collect($affected)->map(fn ($d) => [
                $d->id, $d->title, $d->status->value, $d->uploader?->name ?? '—', $d->file_path,
            ])
        );

        if (! $apply) {
            $this->newLine();
            $this->info(count($affected) . ' document(s) would be rewritten. Re-run with --apply to actually fix them.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Rewrite the ' . count($affected) . ' document(s) listed above now?', false)) {
            $this->comment('Aborted — nothing was changed.');
            return self::SUCCESS;
        }

        $fixed = 0;
        $stillFailing = 0;

        foreach ($affected as $document) {
            $absolutePath = Storage::disk('documents')->path($document->file_path);

            $normalizer->normalize($absolutePath);

            if ($this->usesCompressedXref($absolutePath)) {
                $stillFailing++;
                $this->error("#{$document->id} \"{$document->title}\" — still unreadable by FPDI after normalization (check logs for mutool errors).");
                continue;
            }

            $document->update(['file_hash' => hash_file('sha256', $absolutePath)]);
            $fixed++;
            $this->line("#{$document->id} \"{$document->title}\" — fixed.");
        }

        $this->newLine();
        $this->info("Done. {$fixed} fixed, {$stillFailing} still failing.");

        return $stillFailing > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function usesCompressedXref(string $absolutePath): bool
    {
        try {
            (new Fpdi())->setSourceFile($absolutePath);
            return false;
        } catch (CrossReferenceException) {
            return true;
        }
    }
}
