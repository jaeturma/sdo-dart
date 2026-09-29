<?php

namespace Tests\Feature;

use App\Services\PdfSignatureDetector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PdfSignatureDetectorTest extends TestCase
{
    private string $scratchDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratchDir = storage_path('framework/testing/pdf-signature-detector');
        File::ensureDirectoryExists($this->scratchDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratchDir);

        parent::tearDown();
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->scratchDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_detects_a_file_containing_byte_range(): void
    {
        $path = $this->write('signed.pdf', '%PDF-1.4 ... /Type /Sig /ByteRange [0 100 200 300] ... not a real pdf but that is fine for this test');

        $this->assertTrue(app(PdfSignatureDetector::class)->hasExistingSignature($path));
    }

    public function test_does_not_flag_a_file_without_byte_range(): void
    {
        $path = $this->write('unsigned.pdf', '%PDF-1.4 not a real pdf but that is fine for this test');

        $this->assertFalse(app(PdfSignatureDetector::class)->hasExistingSignature($path));
    }

    public function test_returns_false_for_a_missing_file(): void
    {
        $this->assertFalse(app(PdfSignatureDetector::class)->hasExistingSignature($this->scratchDir.'/does-not-exist.pdf'));
    }
}
