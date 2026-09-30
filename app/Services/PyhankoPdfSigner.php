<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Shells out to scripts/pyhanko/sign_pdf.py to apply one or more
 * independent, incremental-update PDF signatures from a single P12
 * credential — see that script's own docblock for why this replaces
 * FPDI/TCPDF for the actual cryptographic signing step (FPDI's page-import
 * mechanism never reads a source PDF's /AcroForm at all, so any signature
 * already in the file — an originator's own PNPKI, a prior SDS signature —
 * was structurally unreachable and got silently dropped every time).
 *
 * Shared between DocumentSigner (SDS) and DocumentReleaseStamper (Records
 * Section), the same way PdfNormalizer is already shared across multiple
 * upload call sites in this codebase — this wrapper's Process-building/
 * exit-code-translation/passfile-lifecycle logic is meaningfully larger and
 * more failure-prone than the small ensureSufficientDiskSpace() guard each
 * caller still duplicates per its own established per-class convention.
 */
final class PyhankoPdfSigner
{
    private const EXIT_BAD_PASSWORD = 10;

    private const EXIT_WRITE_FAILED = 12;

    /**
     * @param  array<int, array{page: int, x_percent: float, y_percent: float, width_percent: float}>  $placements
     *                                                                                                              One or more signature placements — each becomes its own independent,
     *                                                                                                              separately-verifiable signature (not one signature with cosmetic
     *                                                                                                              echoes, which is what the old TCPDF pipeline produced).
     * @param  string  $writeFailureMessage  Caller-specific wording for an output-write failure (e.g. disk full) —
     *                                       kept as a parameter so DocumentSigner/DocumentReleaseStamper can each
     *                                       keep their own existing exact message text.
     *
     * @throws \RuntimeException
     */
    public function apply(
        string $sourceAbsolutePath,
        string $p12AbsolutePath,
        string $p12Password,
        array $placements,
        ?string $esigAbsolutePath,
        string $signerName,
        string $reason,
        string $location,
        bool $includeDetails,
        \DateTimeInterface $signedAt,
        string $outputAbsolutePath,
        string $writeFailureMessage,
    ): void {
        $this->ensurePyhankoAvailable();

        $dir = dirname($outputAbsolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Password lifecycle mirrors DocumentSigner::sign()'s existing
        // esig-temp-file pattern: written right before the call, deleted
        // in a finally block right after — never logged.
        $passfilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('pyhanko_pass_', true).'.txt';
        file_put_contents($passfilePath, $p12Password);

        try {
            $command = [
                config('services.pyhanko.python_binary', 'python'),
                base_path('scripts/pyhanko/sign_pdf.py'),
                '--input', $sourceAbsolutePath,
                '--output', $outputAbsolutePath,
                '--p12', $p12AbsolutePath,
                '--passfile', $passfilePath,
                '--placements', json_encode(array_values($placements)),
                '--signer-name', $signerName,
                '--reason', $reason,
                '--location', $location,
                '--signed-at', $signedAt->format(\DATE_ATOM),
            ];

            if ($esigAbsolutePath !== null) {
                $command[] = '--esig-image';
                $command[] = $esigAbsolutePath;
            }

            if ($includeDetails) {
                $command[] = '--include-details';
            }

            $result = Process::timeout(120)->run($command);

            // Mirrors PdfNormalizer::normalize()'s success check — don't
            // trust the exit code alone, confirm the output actually exists
            // with real content.
            if ($result->successful() && is_file($outputAbsolutePath) && filesize($outputAbsolutePath) > 0) {
                return;
            }

            $exitCode = $result->exitCode();

            Log::warning('pyhanko signing failed.', [
                'exit_code' => $exitCode,
                'error' => $result->errorOutput(),
            ]);

            throw match ($exitCode) {
                self::EXIT_BAD_PASSWORD => new \RuntimeException('Invalid P12 password or corrupted certificate file.'),
                self::EXIT_WRITE_FAILED => new \RuntimeException($writeFailureMessage),
                default => new \RuntimeException('Digital signing is temporarily unavailable — please contact your system administrator.'),
            };
        } finally {
            @unlink($passfilePath);
        }
    }

    /**
     * A missing interpreter/wrapper has no safe fallback the way a missing
     * mutool does for PdfNormalizer (an unnormalized upload is still a
     * valid file to keep; an unsigned "signed" document is not) — always a
     * hard failure here, matching this pipeline's existing contract of
     * always throwing RuntimeException on any failure.
     */
    private function ensurePyhankoAvailable(): void
    {
        if (! is_file(base_path('scripts/pyhanko/sign_pdf.py'))) {
            throw new \RuntimeException('Digital signing is not available on this server — the signing tool is missing. Please contact your system administrator.');
        }

        $python = config('services.pyhanko.python_binary', 'python');

        // $python may be a bare command name relying on PATH (e.g. 'python'),
        // not a file path — only check existence when it looks like one.
        if ((str_contains($python, '/') || str_contains($python, '\\')) && ! is_file($python)) {
            throw new \RuntimeException('Digital signing is not available on this server — the Python interpreter is missing. Please contact your system administrator.');
        }
    }
}
