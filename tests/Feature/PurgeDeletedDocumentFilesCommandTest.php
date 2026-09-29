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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurgeDeletedDocumentFilesCommandTest extends TestCase
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

    private function makeDeletedDocument(string $deletedAt, ?string $filePath = null): Document
    {
        $filePath ??= 'outgoing/2026/07/'.str()->random(12).'.pdf';

        $document = Document::create([
            'type' => DocumentType::Outgoing,
            'title' => 'Test document',
            'uploaded_by' => $this->uploader->id,
            'division_id' => $this->division->id,
            'file_path' => $filePath,
            'file_hash' => hash('sha256', $filePath),
            'status' => DocumentStatus::Draft,
        ]);

        Storage::disk('documents')->put($filePath, 'pdf bytes for '.$filePath);

        $document->delete();

        // deleted_at isn't mass-assignable — set it directly, bypassing the model.
        DB::table('documents')->where('id', $document->id)->update(['deleted_at' => $deletedAt]);

        return $document->fresh();
    }

    public function test_dry_run_reports_without_deleting_anything_or_setting_files_purged_at(): void
    {
        Storage::fake('documents');

        $document = $this->makeDeletedDocument(now()->subDays(100)->toDateTimeString());

        $this->artisan('documents:purge-deleted-files')
            ->expectsOutputToContain($document->title)
            ->assertExitCode(0);

        Storage::disk('documents')->assertExists($document->file_path);
        $this->assertNull($document->fresh()->files_purged_at);
    }

    public function test_apply_purges_files_and_marks_files_purged_at_for_documents_past_retention(): void
    {
        Storage::fake('documents');

        $document = $this->makeDeletedDocument(now()->subDays(100)->toDateTimeString());

        $this->artisan('documents:purge-deleted-files', ['--apply' => true])
            ->assertExitCode(0);

        Storage::disk('documents')->assertMissing($document->file_path);
        $this->assertNotNull($document->fresh()->files_purged_at);

        // The DB row itself is untouched — only the file was reclaimed.
        $this->assertNotNull(Document::onlyTrashed()->find($document->id));
    }

    public function test_apply_respects_retention_window_and_skips_recently_deleted_documents(): void
    {
        Storage::fake('documents');

        $recentlyDeleted = $this->makeDeletedDocument(now()->subDays(10)->toDateTimeString());

        $this->artisan('documents:purge-deleted-files', ['--apply' => true])
            ->assertExitCode(0);

        Storage::disk('documents')->assertExists($recentlyDeleted->file_path);
        $this->assertNull($recentlyDeleted->fresh()->files_purged_at);
    }

    public function test_apply_purges_every_related_signature_and_release_stamp_file_not_just_latest(): void
    {
        Storage::fake('documents');

        $document = $this->makeDeletedDocument(now()->subDays(100)->toDateTimeString());

        $signedPath1 = 'signed/'.$document->id.'_1.pdf';
        $signedPath2 = 'signed/'.$document->id.'_2.pdf';
        $releasedPath = 'released/'.$document->id.'_1.pdf';

        Storage::disk('documents')->put($signedPath1, 'first signature');
        Storage::disk('documents')->put($signedPath2, 'resigned after undo');
        Storage::disk('documents')->put($releasedPath, 'released copy');

        DocumentSignature::create([
            'document_id' => $document->id, 'signed_by' => $this->uploader->id,
            'signed_file_path' => $signedPath1, 'signed_file_hash' => hash('sha256', $signedPath1),
            'sig_x_percent' => 10, 'sig_y_percent' => 10, 'sig_width_percent' => 20,
            'signed_at' => now()->subDays(150),
        ]);
        DocumentSignature::create([
            'document_id' => $document->id, 'signed_by' => $this->uploader->id,
            'signed_file_path' => $signedPath2, 'signed_file_hash' => hash('sha256', $signedPath2),
            'sig_x_percent' => 10, 'sig_y_percent' => 10, 'sig_width_percent' => 20,
            'signed_at' => now()->subDays(120),
        ]);
        DocumentReleaseStamp::create([
            'document_id' => $document->id, 'stamped_by' => $this->uploader->id,
            'stamped_file_path' => $releasedPath, 'stamped_file_hash' => hash('sha256', $releasedPath),
            'stamp_x_percent' => 10, 'stamp_y_percent' => 10, 'stamp_width_percent' => 20,
            'stamped_at' => now()->subDays(110),
        ]);

        $this->artisan('documents:purge-deleted-files', ['--apply' => true])
            ->assertExitCode(0);

        Storage::disk('documents')->assertMissing($document->file_path);
        Storage::disk('documents')->assertMissing($signedPath1);
        Storage::disk('documents')->assertMissing($signedPath2);
        Storage::disk('documents')->assertMissing($releasedPath);
    }

    public function test_days_option_overrides_config_default(): void
    {
        Storage::fake('documents');

        $document = $this->makeDeletedDocument(now()->subDays(10)->toDateTimeString());

        // Config default is 90 days — override down to 5 so a 10-day-old deletion qualifies.
        $this->artisan('documents:purge-deleted-files', ['--apply' => true, '--days' => 5])
            ->assertExitCode(0);

        Storage::disk('documents')->assertMissing($document->file_path);
    }

    public function test_rerunning_apply_is_a_noop_for_already_purged_documents(): void
    {
        Storage::fake('documents');

        $document = $this->makeDeletedDocument(now()->subDays(100)->toDateTimeString());

        $this->artisan('documents:purge-deleted-files', ['--apply' => true])->assertExitCode(0);
        $purgedAt = $document->fresh()->files_purged_at;

        $this->artisan('documents:purge-deleted-files')
            ->expectsOutputToContain('No soft-deleted documents')
            ->assertExitCode(0);

        $this->assertTrue($purgedAt->equalTo($document->fresh()->files_purged_at));
    }
}
