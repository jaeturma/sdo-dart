<?php

namespace App\Services;

use App\Models\Document;
use App\Models\UserSigningCredential;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors App\Services\DocumentSigner, retargeted at Records Section's
 * "release" step instead of the SDS's approval signature. The visual stamp
 * (office seal + "RELEASED" styling + personal signature) is not drawn by
 * this class — it's baked into the releasing officer's own uploaded e-sig
 * image, exactly like the SDS's esig_path. The only thing drawn dynamically
 * is the "Digitally signed by / Date" text, since that differs every
 * release — handled by PyhankoPdfSigner's stamp-style templating.
 *
 * Applies its own independent, incremental-update signature (via
 * PyhankoPdfSigner) on top of the already-signed file — this is what makes
 * the SDS's signature actually survive into the released/*.pdf artifact
 * Records Section downloads and emails out, which the old FPDI/TCPDF
 * pipeline did not do (it silently dropped whatever signature already
 * existed in the source file it imported pages from).
 */
class DocumentReleaseStamper
{
    /**
     * Records Section's e-sig image is typically a scanned rubber stamp whose
     * fine lettering blurs at the SDS signature's default 40% image / 60% text
     * split, so the image gets most of the box...
     */
    private const IMAGE_WIDTH_RATIO = 0.6;

    /**
     * ...but never taller than this share of the box width: a tall
     * handwritten signature at 60% comes out nearly as tall as the box is
     * wide. A wide stamp (~0.45 height/width) is unaffected.
     */
    private const MAX_IMAGE_HEIGHT_RATIO = 0.53;

    /**
     * Details font size as a share of the image's rendered height, which puts
     * the 3–4 line text block at roughly ¾ of the image height whatever the
     * image's shape (tuned on a real wide stamp, where it works out to the
     * equivalent of a 0.10 --details-font-scale)...
     */
    private const FONT_TO_IMAGE_HEIGHT = 0.136;

    /**
     * ...measured against at most this image height (share of box width).
     * Past it the text stops growing, so a tall signature drawn up to
     * MAX_IMAGE_HEIGHT_RATIO stands about as tall as its text block -
     * Records Section asked for the signature a third bigger than the 0.4
     * cap it used to share with the text, with the text left as it was.
     */
    private const MAX_FONT_REFERENCE_HEIGHT_RATIO = 0.4;

    /** Used when the image's dimensions can't be read. */
    private const FALLBACK_DETAILS_FONT_SCALE = 0.10;

    private const POINTS_PER_MM = 72 / 25.4;

    // Documents/ReleaseStamp.vue's preview (imageWidthPercent /
    // detailsFontSizePx) mirrors all of the above — keep them in sync.

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

        // 1. Verify the SDS-signed file has not been tampered with since it was signed
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

        $esigPath = $credential->esig_path ? Storage::disk('credentials')->path($credential->esig_path) : null;
        [$imageWidthRatio, $detailsFontScale] = $this->stampLayout($esigPath);

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
            esigAbsolutePath: $esigPath,
            signerName: $credential->user?->name ?? 'Records Section',
            reason: 'Document released by Records Section',
            location: '[Your Schools Division Office]',
            includeDetails: true,
            signedAt: new \DateTime,
            outputAbsolutePath: $absolutePath,
            writeFailureMessage: 'Failed to write the released document — the server may be low on disk space, or the PDF could not be processed. Please contact your system administrator.',
            imageWidthRatio: $imageWidthRatio,
            detailsFontScale: $detailsFontScale,
        );

        return $relativePath;
    }

    /**
     * The image's share of the box width, and the details font scale (pt per
     * mm of box width, as sign_pdf.py's --details-font-scale expects) that
     * keeps the text proportionate to the image actually drawn.
     *
     * @return array{float, float}
     */
    private function stampLayout(?string $esigPath): array
    {
        $size = $esigPath ? @getimagesize($esigPath) : false;

        if (! $size || $size[0] <= 0 || $size[1] <= 0) {
            return [self::IMAGE_WIDTH_RATIO, self::FALLBACK_DETAILS_FONT_SCALE];
        }

        $aspect = $size[1] / $size[0]; // height / width
        $widthRatio = min(self::IMAGE_WIDTH_RATIO, self::MAX_IMAGE_HEIGHT_RATIO / $aspect);
        $imageHeightRatio = $widthRatio * $aspect; // image height as a share of box width
        $fontReferenceRatio = min($imageHeightRatio, self::MAX_FONT_REFERENCE_HEIGHT_RATIO);

        return [$widthRatio, self::FONT_TO_IMAGE_HEIGHT * $fontReferenceRatio * self::POINTS_PER_MM];
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
