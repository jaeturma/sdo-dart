<?php

namespace App\Services;

use App\Models\Document;
use App\Models\UserSigningCredential;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors App\Services\DocumentSigner, retargeted at Records Section's
 * "release" step instead of the RD's approval signature. The visual stamp
 * (office seal + "RELEASED" styling + personal signature) is not drawn by
 * this class — it's baked into the releasing officer's own uploaded e-sig
 * image, exactly like the RD's esig_path. The only thing drawn dynamically
 * is the "Digitally signed by / Date" text, since that differs every
 * release — handled by PyhankoPdfSigner's stamp-style templating.
 *
 * Applies its own independent, incremental-update signature (via
 * PyhankoPdfSigner) on top of the already-signed file — this is what makes
 * the RD's signature actually survive into the released/*.pdf artifact
 * Records Section downloads and emails out, which the old FPDI/TCPDF
 * pipeline did not do (it silently dropped whatever signature already
 * existed in the source file it imported pages from).
 */
class DocumentReleaseStamper
{
    /**
     * Stamp the document's already-signed PDF with the releasing officer's
     * PNPKI signature + e-sig image.
     *
     * @param  Document  $document  The document being released (must already be Signed)
     * @param  UserSigningCredential  $credential  The releasing officer's own stored credential (P12 + esig)
     * @param  string  $password  P12 password entered at release time
     * @param  int  $page  1-indexed page where the stamp is placed
     * @param  float  $xPct  Left edge of stamp as % of page width
     * @param  float  $yPct  Top edge of stamp as % of page height
     * @param  float  $widthPct  Width of stamp as % of page width
     * @return string Path of the stamped PDF relative to the `documents` disk
     *
     * @throws \RuntimeException on integrity failure, bad password, or a signing-tool failure
     */
    public function stamp(
        Document $document,
        UserSigningCredential $credential,
        string $password,
        int $page,
        float $xPct,
        float $yPct,
        float $widthPct,
    ): string {
        $signature = $document->signature;

        if (! $signature) {
            throw new \RuntimeException('This document has not been signed yet — nothing to release-stamp.');
        }

        // 1. Verify the RD-signed file has not been tampered with since it was signed
        $originalPath = Storage::disk('documents')->path($signature->signed_file_path);
        $actualHash = hash_file('sha256', $originalPath);

        if ($actualHash !== $signature->signed_file_hash) {
            throw new \RuntimeException('Signed document integrity check failed — hash mismatch. The signed file may have been altered.');
        }

        // 2. Fail fast on a bad password without spawning the signing tool.
        $p12Contents = Storage::disk('credentials')->get($credential->p12_path);
        $certs = [];

        if (! @openssl_pkcs12_read($p12Contents, $certs, $password)) {
            throw new \RuntimeException('Invalid P12 password or corrupted certificate file.');
        }

        $relativePath = 'released/'.$document->id.'_'.time().'.pdf';
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
            placements: [[
                'page' => $page,
                'x_percent' => $xPct,
                'y_percent' => $yPct,
                'width_percent' => $widthPct,
            ]],
            esigAbsolutePath: $credential->esig_path ? Storage::disk('credentials')->path($credential->esig_path) : null,
            signerName: $credential->user?->name ?? 'Records Section',
            reason: 'Document released by Records Section',
            location: '[Your Schools Division Office]',
            includeDetails: true,
            signedAt: new \DateTime,
            outputAbsolutePath: $absolutePath,
            writeFailureMessage: 'Failed to write the released document — the server may be low on disk space, or the PDF could not be processed. Please contact your system administrator.',
        );

        return $relativePath;
    }

    /**
     * See DocumentSigner::ensureSufficientDiskSpace() — identical guard,
     * duplicated per class rather than shared (matching this codebase's
     * established convention for small identical private helpers).
     */
    private function ensureSufficientDiskSpace(string $directory, string $sourceFilePath): void
    {
        $free = @disk_free_space($directory);

        if ($free === false) {
            return; // undeterminable — don't block releasing over an unrelated platform quirk
        }

        $needed = max(50 * 1024 * 1024, (@filesize($sourceFilePath) ?: 0) * 3);

        if ($free < $needed) {
            throw new \RuntimeException('Cannot write the released document — the server is low on disk space. Please contact your system administrator.');
        }
    }
}
