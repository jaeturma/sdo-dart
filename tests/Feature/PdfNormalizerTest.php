<?php

namespace Tests\Feature;

use App\Services\PdfNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;
use Tests\TestCase;

/**
 * Note: the specific bug PdfNormalizer exists to fix — FPDI's free parser
 * rejecting PDF 1.5+ compressed cross-reference/object streams — was
 * verified manually during development against real-world compressed-xref
 * PDFs (confirmed FPDI failed before normalizing and succeeded after,
 * with visually identical rendered output). That reproduction isn't
 * committed here as a fixture since the only samples available were real,
 * signed office documents from seeded dev data. These tests instead cover
 * the service's plumbing and failure-safety with synthetic PDFs.
 */
class PdfNormalizerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('framework/testing/pdf-normalizer-'.uniqid());
        File::ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_normalize_produces_a_still_valid_pdf(): void
    {
        $target = $this->workDir.'/doc.pdf';
        File::copy($this->makeSyntheticPdf(), $target);

        app(PdfNormalizer::class)->normalize($target);

        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($target);
        $this->assertSame(1, $pageCount);
    }

    public function test_normalize_leaves_the_file_untouched_when_mutool_is_missing(): void
    {
        config(['services.mutool.binary' => $this->workDir.'/does-not-exist.exe']);

        $target = $this->workDir.'/doc.pdf';
        file_put_contents($target, '%PDF-1.4 not a real pdf but that is fine for this test');

        app(PdfNormalizer::class)->normalize($target);

        $this->assertSame('%PDF-1.4 not a real pdf but that is fine for this test', file_get_contents($target));
    }

    public function test_store_and_hash_returns_the_hash_of_the_final_stored_bytes(): void
    {
        $disk = Storage::fake('documents');

        $upload = new UploadedFile($this->makeSyntheticPdf(), 'upload.pdf', 'application/pdf', null, true);

        [$path, $hash] = app(PdfNormalizer::class)->storeAndHash($upload, 'incoming/2026/07');

        $disk->assertExists($path);
        $this->assertSame(hash_file('sha256', $disk->path($path)), $hash);
    }

    /**
     * A PDF that already carries a signature (e.g. an originator's own
     * PNPKI signature applied before upload) must be stored byte-exact —
     * mutool's rewrite would break the signature's /ByteRange hash the same
     * way the old FPDI-based signing pipeline used to. Points
     * services.mutool.binary at a nonexistent path like the "mutool
     * missing" test above, but here that's proof normalize() was never
     * even attempted (see PdfSignatureDetectorTest for the detector itself).
     */
    public function test_store_and_hash_skips_normalization_when_the_upload_already_has_a_signature(): void
    {
        $disk = Storage::fake('documents');
        config(['services.mutool.binary' => $this->workDir.'/does-not-exist.exe']);

        $sourcePath = $this->workDir.'/pre-signed.pdf';
        $bytes = '%PDF-1.4 ... /Type /Sig /ByteRange [0 100 200 300] ... not a real pdf but that is fine for this test';
        file_put_contents($sourcePath, $bytes);

        $upload = new UploadedFile($sourcePath, 'pre-signed.pdf', 'application/pdf', null, true);

        [$path, $hash] = app(PdfNormalizer::class)->storeAndHash($upload, 'outgoing/2026/07');

        $this->assertSame($bytes, file_get_contents($disk->path($path)));
        $this->assertSame(hash('sha256', $bytes), $hash);
    }

    private function makeSyntheticPdf(): string
    {
        $path = $this->workDir.'/synthetic-source.pdf';

        $pdf = new \TCPDF;
        $pdf->AddPage();
        $pdf->Write(0, 'Synthetic test PDF — no real document content.');
        $pdf->Output($path, 'F');

        return $path;
    }
}
