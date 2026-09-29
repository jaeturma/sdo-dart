<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentReleaseStamp;
use App\Models\DocumentSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditOrphanedFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);
        $this->uploader = User::factory()->create(['division_id' => $this->division->id]);
        $this->uploader->assignRole('originator');
    }

    private function makeDocument(string $filePath): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing,
            'title' => 'Test document',
            'uploaded_by' => $this->uploader->id,
            'division_id' => $this->division->id,
            'file_path' => $filePath,
            'file_hash' => hash('sha256', $filePath),
            'status' => DocumentStatus::Draft,
        ]);
    }

    public function test_dry_run_lists_orphaned_files_without_deleting(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('outgoing/2026/07/orphan.pdf', 'leaked resubmission original');

        $this->artisan('documents:audit-orphaned-files')
            ->expectsOutputToContain('outgoing/2026/07/orphan.pdf')
            ->assertExitCode(0);

        Storage::disk('documents')->assertExists('outgoing/2026/07/orphan.pdf');
    }

    public function test_apply_deletes_orphaned_file_but_spares_referenced_file_path(): void
    {
        Storage::fake('documents');

        $document = $this->makeDocument('outgoing/2026/07/referenced.pdf');
        Storage::disk('documents')->put($document->file_path, 'still referenced by a document row');
        Storage::disk('documents')->put('outgoing/2026/07/orphan.pdf', 'leaked resubmission original');

        $this->artisan('documents:audit-orphaned-files', ['--apply' => true])
            ->expectsConfirmation('Delete the 1 orphaned file(s) listed above now?', 'yes')
            ->assertExitCode(0);

        Storage::disk('documents')->assertMissing('outgoing/2026/07/orphan.pdf');
        Storage::disk('documents')->assertExists($document->file_path);
    }

    public function test_spares_signed_file_path_and_stamped_file_path(): void
    {
        Storage::fake('documents');

        $document = $this->makeDocument('outgoing/2026/07/original.pdf');
        Storage::disk('documents')->put($document->file_path, 'original');

        $signedPath = 'signed/'.$document->id.'_1.pdf';
        $releasedPath = 'released/'.$document->id.'_1.pdf';
        Storage::disk('documents')->put($signedPath, 'signed copy');
        Storage::disk('documents')->put($releasedPath, 'released copy');

        DocumentSignature::create([
            'document_id' => $document->id, 'signed_by' => $this->uploader->id,
            'signed_file_path' => $signedPath, 'signed_file_hash' => hash('sha256', $signedPath),
            'sig_x_percent' => 10, 'sig_y_percent' => 10, 'sig_width_percent' => 20,
            'signed_at' => now(),
        ]);
        DocumentReleaseStamp::create([
            'document_id' => $document->id, 'stamped_by' => $this->uploader->id,
            'stamped_file_path' => $releasedPath, 'stamped_file_hash' => hash('sha256', $releasedPath),
            'stamp_x_percent' => 10, 'stamp_y_percent' => 10, 'stamp_width_percent' => 20,
            'stamped_at' => now(),
        ]);

        $this->artisan('documents:audit-orphaned-files')
            ->expectsOutputToContain('No orphaned files found')
            ->assertExitCode(0);
    }

    public function test_spares_file_path_of_a_trashed_but_not_yet_purged_document(): void
    {
        Storage::fake('documents');

        $document = $this->makeDocument('outgoing/2026/07/trashed.pdf');
        Storage::disk('documents')->put($document->file_path, 'trashed but not purged yet');
        $document->delete();

        $this->artisan('documents:audit-orphaned-files')
            ->expectsOutputToContain('No orphaned files found')
            ->assertExitCode(0);
    }

    public function test_finds_orphans_in_nested_date_bucketed_directories(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('incoming/2026/08/deeply/nested/orphan.pdf', 'still found');

        $this->artisan('documents:audit-orphaned-files')
            ->expectsOutputToContain('incoming/2026/08/deeply/nested/orphan.pdf')
            ->assertExitCode(0);
    }
}
