<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reproduces the recurring real-world report: a user's upload fails with
 * Laravel's default "The file failed to upload." message — which fires
 * when PHP itself rejects the transfer (e.g. exceeding the server's
 * upload_max_filesize/post_max_size) before this app's own validation ever
 * runs. The default wording gives no hint that it's a server limit, so
 * users kept re-compressing an already-valid PDF instead of asking IT to
 * check the server config. These tests simulate that exact PHP-level
 * failure (UPLOAD_ERR_INI_SIZE) via a manually-constructed UploadedFile —
 * Laravel's UploadedFile::fake() always produces a *valid* upload, so it
 * can't reproduce this on its own.
 */
class FileUploadFailureMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'secretary', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function tooLargeUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, '%PDF-1.4 fake pdf content for tests');

        return new UploadedFile($path, 'oversized.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
    }

    public function test_outgoing_submission_shows_a_friendly_message_when_php_rejects_the_upload(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($originator)
            ->post('/documents/outgoing', [
                'title'            => 'Too big memo',
                'file'             => $this->tooLargeUpload(),
                'requires_release' => true,
            ])
            ->assertSessionHasErrors([
                'file' => 'The file could not be uploaded — it may be too large for the server to accept right now. If you\'ve already tried a smaller/compressed file and it still fails, this is a server configuration limit, not a problem with your file — please contact your system administrator.',
            ]);
    }

    public function test_incoming_submission_shows_a_friendly_message_when_php_rejects_the_upload(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordp = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);
        $secretary = User::factory()->create(['division_id' => $ordp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)
            ->post('/documents/incoming', [
                'title'         => 'Too big incoming memo',
                'source_origin' => 'DepEd Central Office',
                'file'          => $this->tooLargeUpload(),
            ])
            ->assertSessionHasErrors([
                'file' => 'The file could not be uploaded — it may be too large for the server to accept right now. If you\'ve already tried a smaller/compressed file and it still fails, this is a server configuration limit, not a problem with your file — please contact your system administrator.',
            ]);
    }

    public function test_resubmission_shows_a_friendly_message_when_php_rejects_the_upload(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole('originator');

        $document = Document::create([
            'type'        => DocumentType::Outgoing,
            'title'       => 'Returned memo',
            'uploaded_by' => $originator->id,
            'division_id' => $division->id,
            'file_path'   => 'outgoing/2026/07/original.pdf',
            'file_hash'   => hash('sha256', 'original'),
            'status'      => DocumentStatus::ReturnedForRevision,
        ]);

        $this->actingAs($originator)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'Returned memo (revised)',
                'file'  => $this->tooLargeUpload(),
            ])
            ->assertSessionHasErrors([
                'file' => 'The file could not be uploaded — it may be too large for the server to accept right now. If you\'ve already tried a smaller/compressed file and it still fails, this is a server configuration limit, not a problem with your file — please contact your system administrator.',
            ]);
    }
}
