<?php

namespace App\Services;

use App\Models\Document;
use App\Models\UserSigningCredential;
use Illuminate\Support\Facades\Storage;

class DocumentSigner
{
    /**
     * Sign the document PDF using the SDS's P12 certificate, stamping the
     * SDS's e-signature image at one or more places across the document.
     *
     * Each placement becomes its own independent, incremental-update PDF
     * signature (via PyhankoPdfSigner) rather than one signature with
     * cosmetic echoes — this also means a signature already embedded in
     * the uploaded PDF (e.g. an originator's own PNPKI signature applied
     * externally before upload) survives intact alongside the SDS's own,
     * instead of being silently discarded the way FPDI's page-import
     * mechanism used to (it never read a source PDF's /AcroForm at all).
     *
     * @param  Document  $document  The document to sign
     * @param  UserSigningCredential  $credential  The SDS's stored credential (P12 + optional esig)
     * @param  string  $password  P12 password entered by the SDS at signing time
     * @param  array<int, array{page: int, x_percent: float, y_percent: float, width_percent: float}>  $placements
     *                                                                                                              One or more signature placements, in the order the secretary added them.
     * @param  bool  $includeDetails  Whether to also print "Digitally signed by {name} / Date: {timestamp}"
     *                                next to the e-sig image at every placement, instead of just the plain
     *                                image (the classic Adobe-style signature stamp look).
     * @param  \DateTimeInterface|null  $signedAt  Timestamp to print when $includeDetails is true — passed in rather than
     *                                             read fresh here so it exactly matches the DocumentSignature row's own
     *                                             signed_at (the caller records both from the same instant).
     * @return string Path of the signed PDF relative to the `documents` disk
     *
     * @throws \RuntimeException on integrity failure, bad password, missing placements, or a signing-tool failure
     */
    public function sign(
        Document $document,
        UserSigningCredential $credential,
        string $password,
        array $placements,
        bool $includeDetails = false,
        ?\DateTimeInterface $signedAt = null,
    ): string {
        if (empty($placements)) {
            throw new \RuntimeException('At least one signature placement is required.');
        }

        // 1. Verify the original file has not been tampered with
        $originalPath = Storage::disk('documents')->path($document->file_path);
        $actualHash = hash_file('sha256', $originalPath);

        if ($actualHash !== $document->file_hash) {
            throw new \RuntimeException('Document integrity check failed — hash mismatch. The original file may have been altered.');
        }

        // 2. Fail fast on a bad password without spawning the signing tool —
        // the same pre-check SigningCredentialController::store() already
        // does at upload time.
        $p12Contents = Storage::disk('credentials')->get($credential->p12_path);
        $certs = [];

        if (! @openssl_pkcs12_read($p12Contents, $certs, $password)) {
            throw new \RuntimeException('Invalid P12 password or corrupted certificate file.');
        }

        $relativePath = 'signed/'.$document->id.'_'.time().'.pdf';
        $absolutePath = Storage::disk('documents')->path($relativePath);

        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->ensureSufficientDiskSpace($dir, $originalPath);

        app(PyhankoPdfSigner::class)->apply(
            sourceAbsolutePath: $originalPath,
            p12AbsolutePath: Storage::disk('credentials')->path($credential->p12_path),
            p12Password: $password,
            placements: $placements,
            esigAbsolutePath: $credential->esig_path ? Storage::disk('credentials')->path($credential->esig_path) : null,
            signerName: $credential->user?->name ?? 'Schools Division Superintendent',
            reason: 'Approved and digitally signed by the Schools Division Superintendent',
            location: '[Your Schools Division Office]',
            includeDetails: $includeDetails,
            signedAt: $signedAt ?? new \DateTime,
            outputAbsolutePath: $absolutePath,
            writeFailureMessage: 'Failed to write the signed document — the server may be low on disk space, or the PDF could not be processed. Please contact your system administrator.',
        );

        return $relativePath;
    }

    /**
     * Cheap guard against a disk-full mid-write: refuse cleanly before the
     * signing tool even starts, rather than after producing a truncated or
     * missing output file.
     */
    private function ensureSufficientDiskSpace(string $directory, string $sourceFilePath): void
    {
        $free = @disk_free_space($directory);

        if ($free === false) {
            return; // undeterminable — don't block signing over an unrelated platform quirk
        }

        $needed = max(50 * 1024 * 1024, (@filesize($sourceFilePath) ?: 0) * 3);

        if ($free < $needed) {
            throw new \RuntimeException('Cannot write the signed document — the server is low on disk space. Please contact your system administrator.');
        }
    }
}
