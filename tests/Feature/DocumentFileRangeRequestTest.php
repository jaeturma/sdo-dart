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
 * pdf.js relies on the server supporting HTTP Range requests to
 * progressively fetch a PDF's pages instead of downloading the whole file
 * before it can render anything — without it, every "preview" surface
 * (Show.vue, Index.vue's mobile quick-preview, Sign.vue, ReleaseStamp.vue)
 * is slow to open. These endpoints were switched from the disk's
 * StreamedResponse (no Range support) to response()->file() (BinaryFileResponse,
 * native Range support for local files).
 */
class DocumentFileRangeRequestTest extends TestCase
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

    public function test_document_file_endpoint_supports_range_requests(): void
    {
        Storage::disk('documents')->put('outgoing/a.pdf', str_repeat('%PDF-1.4 fake content ', 20));

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Range test', 'uploaded_by' => $this->uploader->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Draft,
        ]);

        $response = $this->actingAs($this->uploader)
            ->get(route('documents.file', $document), ['Range' => 'bytes=0-9']);

        $response->assertStatus(206);
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringStartsWith('bytes 0-9/', $response->headers->get('Content-Range'));
    }

    public function test_signed_pdf_endpoint_supports_range_requests(): void
    {
        Storage::disk('documents')->put('outgoing/original.pdf', 'original');
        Storage::disk('documents')->put('outgoing/signed.pdf', str_repeat('%PDF-1.4 signed content ', 20));

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Signed range test', 'uploaded_by' => $this->uploader->id,
            'file_path' => 'outgoing/original.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Signed,
        ]);

        DocumentSignature::create([
            'document_id' => $document->id,
            'signed_by' => $this->uploader->id,
            'signed_file_path' => 'outgoing/signed.pdf',
            'signed_file_hash' => hash('sha256', 'signed'),
            'certificate_subject' => 'CN=Test',
            'certificate_serial' => '123',
            'signature_page' => 1,
            'sig_x_percent' => 10,
            'sig_y_percent' => 10,
            'sig_width_percent' => 20,
            'signed_at' => now(),
        ]);

        $response = $this->actingAs($this->uploader)
            ->get(route('documents.signed-pdf', $document), ['Range' => 'bytes=0-9']);

        $response->assertStatus(206);
        $this->assertStringStartsWith('bytes 0-9/', $response->headers->get('Content-Range'));
    }

    public function test_released_pdf_endpoint_supports_range_requests(): void
    {
        Storage::disk('documents')->put('outgoing/original.pdf', 'original');
        Storage::disk('documents')->put('outgoing/released.pdf', str_repeat('%PDF-1.4 released content ', 20));

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Released range test', 'uploaded_by' => $this->uploader->id,
            'file_path' => 'outgoing/original.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Released,
        ]);

        DocumentReleaseStamp::create([
            'document_id' => $document->id,
            'stamped_by' => $this->uploader->id,
            'stamped_file_path' => 'outgoing/released.pdf',
            'stamped_file_hash' => hash('sha256', 'released'),
            'certificate_subject' => 'CN=Test',
            'certificate_serial' => '123',
            'stamp_page' => 1,
            'stamp_x_percent' => 10,
            'stamp_y_percent' => 10,
            'stamp_width_percent' => 20,
            'stamped_at' => now(),
        ]);

        $response = $this->actingAs($this->uploader)
            ->get(route('documents.released-pdf', $document), ['Range' => 'bytes=0-9']);

        $response->assertStatus(206);
        $this->assertStringStartsWith('bytes 0-9/', $response->headers->get('Content-Range'));
    }
}
