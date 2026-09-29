<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SignedDocumentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        Role::firstOrCreate(['name' => 'originator', 'guard_name' => 'web']);

        $this->uploader = User::factory()->create();
        $this->uploader->assignRole('originator');
    }

    private function signedDocument(string $title): Document
    {
        Storage::fake('documents');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => $title, 'uploaded_by' => $this->uploader->id,
            'file_path' => 'outgoing/original.pdf', 'file_hash' => hash('sha256', $title),
            'status' => DocumentStatus::Signed,
        ]);

        Storage::disk('documents')->put('outgoing/signed.pdf', '%PDF-1.4 fake signed content');

        DocumentSignature::create([
            'document_id'         => $document->id,
            'signed_by'           => $this->uploader->id,
            'signed_file_path'    => 'outgoing/signed.pdf',
            'signed_file_hash'    => hash('sha256', 'signed'),
            'certificate_subject' => 'CN=Test',
            'certificate_serial'  => '123',
            'signature_page'      => 1,
            'sig_x_percent'       => 10,
            'sig_y_percent'       => 10,
            'sig_width_percent'   => 20,
            'signed_at'           => now(),
        ]);

        return $document;
    }

    public function test_download_filename_uses_the_document_title(): void
    {
        $document = $this->signedDocument('Authority to Travel');

        $response = $this->actingAs($this->uploader)->get(route('documents.signed-pdf', $document));

        $response->assertOk();
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('filename="Authority to Travel.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_download_filename_strips_illegal_filesystem_characters(): void
    {
        $document = $this->signedDocument('Re: "Travel Order" <2026/07> *urgent*');

        $response = $this->actingAs($this->uploader)->get(route('documents.signed-pdf', $document));

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');

        // Extract just the filename value, not the surrounding
        // filename="..." wrapper quotes, which are syntax, not content.
        preg_match('/filename\*?="?([^";]+)"?/', $disposition, $matches);
        $filename = $matches[1] ?? '';
        $this->assertNotSame('', $filename);

        foreach (['/', '\\', ':', '*', '?', '"', '<', '>', '|'] as $char) {
            $this->assertStringNotContainsString($char, $filename);
        }
    }

    public function test_download_filename_falls_back_when_title_sanitizes_to_empty(): void
    {
        $document = $this->signedDocument('///???');

        $response = $this->actingAs($this->uploader)->get(route('documents.signed-pdf', $document));

        $response->assertOk();
        $this->assertStringContainsString('document.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_download_serves_the_most_recent_signature_after_a_re_sign(): void
    {
        Storage::fake('documents');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Re-signed memo', 'uploaded_by' => $this->uploader->id,
            'file_path' => 'outgoing/original.pdf', 'file_hash' => hash('sha256', 'x'),
            'status' => DocumentStatus::Signed,
        ]);

        Storage::disk('documents')->put('outgoing/signed-wrong.pdf', 'wrong signature position');
        Storage::disk('documents')->put('outgoing/signed-correct.pdf', 'correct signature position');

        // Simulates: secretary signs (wrong position) -> admin manually
        // reverts status to Approved -> secretary signs again (correct
        // position). Both rows persist (append-only), so the relation must
        // pick the later one rather than whichever the DB returns first.
        DocumentSignature::create([
            'document_id' => $document->id, 'signed_by' => $this->uploader->id,
            'signed_file_path' => 'outgoing/signed-wrong.pdf', 'signed_file_hash' => hash('sha256', 'wrong'),
            'certificate_subject' => 'CN=Test', 'certificate_serial' => '123',
            'signature_page' => 1, 'sig_x_percent' => 10, 'sig_y_percent' => 10, 'sig_width_percent' => 20,
            'signed_at' => now()->subHour(),
        ]);

        DocumentSignature::create([
            'document_id' => $document->id, 'signed_by' => $this->uploader->id,
            'signed_file_path' => 'outgoing/signed-correct.pdf', 'signed_file_hash' => hash('sha256', 'correct'),
            'certificate_subject' => 'CN=Test', 'certificate_serial' => '123',
            'signature_page' => 1, 'sig_x_percent' => 50, 'sig_y_percent' => 50, 'sig_width_percent' => 20,
            'signed_at' => now(),
        ]);

        $this->assertSame('outgoing/signed-correct.pdf', $document->fresh()->signature->signed_file_path);

        $response = $this->actingAs($this->uploader)->get(route('documents.signed-pdf', $document));
        $response->assertOk();
        $this->assertSame('correct signature position', $response->streamedContent());
    }

    public function test_returns_404_when_document_is_not_signed(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Not signed', 'uploaded_by' => $this->uploader->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Approved,
        ]);

        $this->actingAs($this->uploader)
            ->get(route('documents.signed-pdf', $document))
            ->assertNotFound();
    }
}
