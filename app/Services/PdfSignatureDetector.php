<?php

namespace App\Services;

/**
 * Cheap raw-byte check for whether a PDF already contains an applied
 * digital signature — used by PdfNormalizer to skip its own destructive
 * mutool rewrite for an already-signed upload (e.g. an originator's own
 * PNPKI signature applied externally before upload), since that rewrite
 * changes byte offsets and would break the signature's /ByteRange hash
 * just as surely as the old FPDI-based signing pipeline did.
 *
 * Deliberately a raw string scan rather than a full parse or a
 * `pyhanko sign validate` subprocess — mirrors this codebase's existing
 * precedent for cheap raw-byte PDF heuristics (see App\Rules\PdfFile's
 * `%PDF-` magic-byte check) and avoids spawning a Python process on every
 * upload, since uploads vastly outnumber signs in this app's traffic.
 *
 * Known, self-bounded blind spot: a signature dictionary compressed inside
 * a /Type /ObjStm object stream wouldn't contain a literal "/ByteRange"
 * string and would false-negative here. Worst case is the pre-existing
 * status-quo bug (the signature gets destroyed by normalization) — not a
 * new hazard — since real-world signing tools conventionally keep the
 * signature dictionary as a plain top-level indirect object even in an
 * otherwise-compressed-xref file.
 */
class PdfSignatureDetector
{
    public function hasExistingSignature(string $absolutePath): bool
    {
        $bytes = @file_get_contents($absolutePath);

        return $bytes !== false && str_contains($bytes, '/ByteRange');
    }
}
