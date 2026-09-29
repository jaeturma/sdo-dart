<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an uploaded PDF and rewrites it via mutool (MuPDF CLI) so it never
 * uses PDF 1.5+ compressed cross-reference/object streams — a format the
 * free FPDI parser (used for signing and release-stamping) cannot read at
 * all, previously forcing users to manually re-save affected uploads. Also
 * yields a modest, safe file-size reduction as a side effect.
 *
 * `mutool convert` was tried first but discarded: on a real Canva-exported
 * PDF it silently dropped a background gradient it couldn't correctly
 * recompose. `mutool clean -d -z -g` (decompress, deflate, garbage-collect)
 * round-trips visually identical output and was verified against that same
 * sample before being adopted here.
 */
class PdfNormalizer
{
    /**
     * Store the uploaded file on the given disk, normalize it in place, and
     * return [relative path, sha256 hash of the final stored bytes] — the
     * hash always reflects whatever ended up on disk, whether normalization
     * succeeded or (on failure) the original upload was left untouched.
     *
     * @return array{0: string, 1: string}
     */
    public function storeAndHash(UploadedFile $file, string $directory, string $disk = 'documents'): array
    {
        $path = $file->store($directory, $disk);
        $absolutePath = Storage::disk($disk)->path($path);

        // A PDF that already carries a digital signature (e.g. an
        // originator's own PNPKI signature applied externally before
        // upload) must not go through mutool's rewrite — that changes byte
        // offsets and would break the signature's /ByteRange hash just as
        // surely as the old FPDI-based signing pipeline did. See
        // App\Services\PdfSignatureDetector.
        if (! app(PdfSignatureDetector::class)->hasExistingSignature($absolutePath)) {
            $this->normalize($absolutePath);
        }

        return [$path, hash_file('sha256', $absolutePath)];
    }

    /**
     * Rewrite the PDF at $absolutePath in place. Failures (mutool missing,
     * non-zero exit, corrupt input) are logged and swallowed — the original
     * upload is left as-is rather than blocking the request, since an
     * unnormalized PDF is still a valid, readable document.
     */
    public function normalize(string $absolutePath): void
    {
        $tempOutput = $absolutePath.'.normalized.tmp';

        $result = Process::timeout(60)->run([
            config('services.mutool.binary', 'mutool'),
            'clean', '-d', '-z', '-g',
            $absolutePath,
            $tempOutput,
        ]);

        if (! $result->successful() || ! is_file($tempOutput) || filesize($tempOutput) === 0) {
            Log::warning('PDF normalization via mutool failed; keeping original upload as-is.', [
                'path' => $absolutePath,
                'error' => $result->errorOutput(),
            ]);
            @unlink($tempOutput);

            return;
        }

        // copy()+unlink() rather than rename(): on Windows, rename() can fail
        // with "Access is denied" when overwriting an existing file that a
        // background process (e.g. antivirus) still has a transient handle on.
        copy($tempOutput, $absolutePath);
        @unlink($tempOutput);
    }
}
