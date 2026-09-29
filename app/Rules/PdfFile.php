<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Confirms an uploaded file is genuinely a PDF by checking its magic-byte
 * signature ("%PDF-") instead of relying on Laravel's mimes: rule, which
 * trusts PHP's fileinfo MIME-sniffing. Some mobile scanning apps and
 * "print to PDF" tools produce valid PDFs that fileinfo reports as
 * application/octet-stream (or similar) rather than application/pdf,
 * which makes mimes:pdf wrongly reject a perfectly valid document.
 */
class PdfFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be a file.');

            return;
        }

        $handle = @fopen($value->getRealPath(), 'rb');
        $header = $handle ? fread($handle, 5) : false;

        if ($handle) {
            fclose($handle);
        }

        if ($header !== '%PDF-') {
            $fail('The :attribute must be a valid PDF file.');
        }
    }
}
