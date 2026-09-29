<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The specific bug this command fixes (PDF 1.5+ compressed cross-reference/
 * object streams) was verified manually during development against a real
 * compressed-xref PDF — see PdfNormalizerTest's note on why that reproduction
 * isn't committed as a fixture. These tests cover the command's scoping and
 * safety behavior with synthetic PDFs instead.
 */
class NormalizeLegacyPdfsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeSyntheticPdf(string $path): void
    {
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));

        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->Write(0, 'Synthetic test PDF — no real document content.');
        $pdf->Output($path, 'F');
    }

    public function test_dry_run_reports_no_affected_documents_for_normal_pdfs(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();
        $path = 'outgoing/2026/07/normal.pdf';
        $this->makeSyntheticPdf(Storage::disk('documents')->path($path));

        Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Normal doc',
            'uploaded_by' => $user->id, 'file_path' => $path,
            'file_hash' => hash_file('sha256', Storage::disk('documents')->path($path)),
            'status' => DocumentStatus::PendingRdReview,
        ]);

        $this->artisan('documents:normalize-legacy-pdfs')
            ->expectsOutputToContain('No affected documents found')
            ->assertExitCode(0);
    }

    public function test_signed_and_released_documents_are_excluded_from_scope(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        foreach ([DocumentStatus::Signed, DocumentStatus::Released] as $status) {
            $path = "outgoing/2026/07/{$status->value}.pdf";
            // Deliberately not even a valid PDF — proves these rows are
            // filtered out by status before the file is ever inspected.
            Storage::disk('documents')->put($path, 'not a pdf at all');

            Document::create([
                'type' => DocumentType::Outgoing, 'title' => "Doc {$status->value}",
                'uploaded_by' => $user->id, 'file_path' => $path,
                'file_hash' => hash('sha256', 'x'), 'status' => $status,
            ]);
        }

        $this->artisan('documents:normalize-legacy-pdfs')
            ->expectsOutputToContain('No affected documents found')
            ->assertExitCode(0);
    }

    public function test_missing_file_is_reported_and_skipped_without_crashing(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Ghost doc',
            'uploaded_by' => $user->id, 'file_path' => 'outgoing/2026/07/does-not-exist.pdf',
            'file_hash' => hash('sha256', 'x'), 'status' => DocumentStatus::PendingRdReview,
        ]);

        $this->artisan('documents:normalize-legacy-pdfs')
            ->expectsOutputToContain('no longer exists on disk')
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_modify_any_file(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();
        $path = 'outgoing/2026/07/normal.pdf';
        $this->makeSyntheticPdf(Storage::disk('documents')->path($path));
        $originalHash = hash_file('sha256', Storage::disk('documents')->path($path));

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Normal doc',
            'uploaded_by' => $user->id, 'file_path' => $path,
            'file_hash' => $originalHash,
            'status' => DocumentStatus::PendingRdReview,
        ]);

        $this->artisan('documents:normalize-legacy-pdfs')->assertExitCode(0);

        $this->assertSame($originalHash, hash_file('sha256', Storage::disk('documents')->path($path)));
        $this->assertSame($originalHash, $document->fresh()->file_hash);
    }
}
