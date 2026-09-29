<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Document uploads validate PDFs by their actual magic-byte signature
 * (App\Rules\PdfFile) rather than Laravel's mimes: rule, which trusts PHP's
 * fileinfo MIME-sniffing — some mobile scanning apps and "print to PDF"
 * tools produce real, valid PDFs that fileinfo doesn't detect as
 * application/pdf, which used to make mimes:pdf wrongly reject them.
 */
class PdfUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $rd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        Role::firstOrCreate(['name' => 'rd', 'guard_name' => 'web']);

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');
    }

    public function test_a_real_pdf_is_accepted_even_when_the_mime_type_is_reported_as_something_else(): void
    {
        // Simulates the exact scenario that broke uploads: a genuine PDF
        // (correct %PDF- signature) whose browser/OS-reported MIME type
        // isn't the standard application/pdf.
        $file = UploadedFile::fake()
            ->createWithContent('scanned.pdf', "%PDF-1.4\n%fake but valid signature")
            ->mimeType('application/octet-stream');

        $this->actingAs($this->rd)->post('/documents/incoming', [
            'title'         => 'Scanned memo',
            'source_origin' => 'Central Office',
            'file'          => $file,
        ])->assertSessionDoesntHaveErrors('file');

        $this->assertDatabaseHas('documents', ['title' => 'Scanned memo']);
    }

    public function test_a_file_without_a_pdf_signature_is_rejected_even_with_a_pdf_extension(): void
    {
        $file = UploadedFile::fake()->createWithContent('not-really-a-pdf.pdf', 'this is just plain text, not a pdf');

        $this->actingAs($this->rd)->post('/documents/incoming', [
            'title'         => 'Fake memo',
            'source_origin' => 'Central Office',
            'file'          => $file,
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseMissing('documents', ['title' => 'Fake memo']);
    }

    public function test_a_non_pdf_extension_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('memo.docx', '%PDF-1.4 content but wrong extension');

        $this->actingAs($this->rd)->post('/documents/incoming', [
            'title'         => 'Wrong extension memo',
            'source_origin' => 'Central Office',
            'file'          => $file,
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseMissing('documents', ['title' => 'Wrong extension memo']);
    }
}
