<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Document;
use App\Models\DocumentReleaseStamp;
use App\Models\DocumentSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Document files are served from fixed URLs whose content can change —
 * a resubmission replaces documents/{id}/file, and an undone-then-redone
 * signature replaces documents/{id}/signed-pdf. These endpoints must make
 * the browser revalidate on every view (Controller::revalidateOnEveryView())
 * rather than reuse a heuristically-cached copy of a superseded file, and
 * must never be marked `public` (cacheable by Cloudflare/shared proxies).
 */
class DocumentFileCacheHeadersTest extends TestCase
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

        Storage::fake('documents');
    }

    private function createDocument(DocumentStatus $status): Document
    {
        Storage::disk('documents')->put('outgoing/original.pdf', '%PDF-1.4 original');

        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Cache test', 'uploaded_by' => $this->uploader->id,
            'file_path' => 'outgoing/original.pdf', 'file_hash' => hash('sha256', '%PDF-1.4 original'),
            'status' => $status,
        ]);
    }

    private function sign(Document $document, string $path, string $contents): DocumentSignature
    {
        Storage::disk('documents')->put($path, $contents);

        return DocumentSignature::create([
            'document_id' => $document->id,
            'signed_by' => $this->uploader->id,
            'signed_file_path' => $path,
            'signed_file_hash' => hash('sha256', $contents),
            'certificate_subject' => 'CN=Test',
            'certificate_serial' => '123',
            'signature_page' => 1,
            'sig_x_percent' => 10,
            'sig_y_percent' => 10,
            'sig_width_percent' => 20,
            'signed_at' => now(),
        ]);
    }

    public function test_document_file_must_be_revalidated_and_is_never_publicly_cacheable(): void
    {
        $document = $this->createDocument(DocumentStatus::Draft);

        $response = $this->actingAs($this->uploader)->get(route('documents.file', $document));

        $response->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('"'.$document->file_hash.'"', $response->headers->get('ETag'));
    }

    public function test_unchanged_document_file_revalidates_to_a_304(): void
    {
        $document = $this->createDocument(DocumentStatus::Draft);

        $this->actingAs($this->uploader)
            ->get(route('documents.file', $document), ['If-None-Match' => '"'.$document->file_hash.'"'])
            ->assertStatus(304);
    }

    public function test_signed_pdf_serves_the_new_signature_after_a_re_sign(): void
    {
        $document = $this->createDocument(DocumentStatus::Signed);
        $this->sign($document, 'signed/first.pdf', '%PDF-1.4 first signing');

        $first = $this->actingAs($this->uploader)->get(route('documents.signed-pdf', $document));
        $first->assertOk();
        $this->assertTrue($first->headers->hasCacheControlDirective('no-cache'));
        $this->assertFalse($first->headers->hasCacheControlDirective('public'));

        // Undo signature + sign again leaves the old row in place (append-only)
        // and adds a new one; Document::signature() resolves to the newest.
        $this->sign($document, 'signed/second.pdf', '%PDF-1.4 second signing');

        $revalidated = $this->actingAs($this->uploader)->get(route('documents.signed-pdf', $document->fresh()), [
            'If-None-Match' => $first->headers->get('ETag'),
        ]);

        $revalidated->assertOk();
        $this->assertSame('%PDF-1.4 second signing', $revalidated->streamedContent());
    }

    public function test_released_pdf_must_be_revalidated_and_is_never_publicly_cacheable(): void
    {
        $document = $this->createDocument(DocumentStatus::Released);
        Storage::disk('documents')->put('released/stamped.pdf', '%PDF-1.4 released');

        DocumentReleaseStamp::create([
            'document_id' => $document->id,
            'stamped_by' => $this->uploader->id,
            'stamped_file_path' => 'released/stamped.pdf',
            'stamped_file_hash' => hash('sha256', '%PDF-1.4 released'),
            'certificate_subject' => 'CN=Test',
            'certificate_serial' => '123',
            'stamp_page' => 1,
            'stamp_x_percent' => 10,
            'stamp_y_percent' => 10,
            'stamp_width_percent' => 20,
            'stamped_at' => now(),
        ]);

        $response = $this->actingAs($this->uploader)->get(route('documents.released-pdf', $document));

        $response->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }
}
