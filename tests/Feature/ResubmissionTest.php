<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ReviewAction;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);

        $this->originator = User::factory()->create(['division_id' => $this->division->id]);
        $this->originator->assignRole('originator');
    }

    private function createDocumentAt(DocumentStatus $status): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing,
            'title' => 'Original Title',
            'description' => 'Original description',
            'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id,
            'file_path' => 'outgoing/2026/07/original.pdf',
            'file_hash' => hash('sha256', 'original'),
            'status' => $status,
        ]);
    }

    public function test_uploader_can_resubmit_a_returned_document(): void
    {
        Notification::fake();

        $document = $this->createDocumentAt(DocumentStatus::ReturnedForRevision);

        $this->assertTrue($this->originator->can('resubmit', $document));

        $this->actingAs($this->originator)
            ->get(route('documents.outgoing.resubmit.edit', $document))
            ->assertOk();

        $response = $this->actingAs($this->originator)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'Corrected Title',
                'description' => 'Corrected description',
                'file' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 fake pdf content for tests'),
            ]);

        $response->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame('Corrected Title', $document->title);
        // No unit_head exists in this division, so it restarts at Submitted
        // (division-chief stage), matching a fresh upload's initialStatus() rule.
        $this->assertSame(DocumentStatus::Submitted, $document->status);

        $document->load('reviews');
        $this->assertTrue($document->reviews->contains(fn ($r) => $r->action === ReviewAction::Resubmitted));
    }

    public function test_uploader_can_resubmit_a_for_comment_document(): void
    {
        Notification::fake();

        $document = $this->createDocumentAt(DocumentStatus::ForComment);

        $this->assertTrue($this->originator->can('resubmit', $document));

        $this->actingAs($this->originator)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'Corrected Title',
                'description' => 'Corrected description',
                'file' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 fake pdf content for tests'),
            ])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Submitted, $document->status);
    }

    public function test_uploader_cannot_resubmit_a_rejected_document(): void
    {
        $document = $this->createDocumentAt(DocumentStatus::Rejected);

        $this->assertFalse($this->originator->can('resubmit', $document));

        $this->actingAs($this->originator)
            ->get(route('documents.outgoing.resubmit.edit', $document))
            ->assertForbidden();

        $this->actingAs($this->originator)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'Corrected Title',
                'file' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 fake pdf content for tests'),
            ])
            ->assertForbidden();
    }

    public function test_a_different_user_cannot_resubmit_even_if_status_qualifies(): void
    {
        $document = $this->createDocumentAt(DocumentStatus::ReturnedForRevision);

        $otherUser = User::factory()->create(['division_id' => $this->division->id]);
        $otherUser->assignRole('originator');

        $this->assertFalse($otherUser->can('resubmit', $document));

        $this->actingAs($otherUser)
            ->get(route('documents.outgoing.resubmit.edit', $document))
            ->assertForbidden();
    }

    public function test_resubmit_deletes_the_superseded_file(): void
    {
        Notification::fake();
        Storage::fake('documents');

        $document = $this->createDocumentAt(DocumentStatus::ReturnedForRevision);
        Storage::disk('documents')->put($document->file_path, '%PDF-1.4 original bytes');

        $this->actingAs($this->originator)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'Corrected Title',
                'description' => 'Corrected description',
                'file' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 fake pdf content for tests'),
            ])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertNotSame('outgoing/2026/07/original.pdf', $document->file_path);
        Storage::disk('documents')->assertMissing('outgoing/2026/07/original.pdf');
        Storage::disk('documents')->assertExists($document->file_path);
    }

    public function test_resubmit_still_succeeds_and_logs_a_warning_if_the_old_file_cannot_be_deleted(): void
    {
        Notification::fake();
        Log::spy();
        Storage::fake('documents');

        $document = $this->createDocumentAt(DocumentStatus::ReturnedForRevision);
        Storage::disk('documents')->put($document->file_path, '%PDF-1.4 original bytes');

        // Swap in a partial mock so every other disk operation (storing the
        // new file, etc.) still behaves exactly like the real fake disk —
        // only delete() is forced to fail, simulating e.g. a permissions or
        // filesystem-level issue.
        $spyDisk = \Mockery::mock(Storage::disk('documents'))->makePartial();
        $spyDisk->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('documents', $spyDisk);

        $this->actingAs($this->originator)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'Corrected Title',
                'description' => 'Corrected description',
                'file' => UploadedFile::fake()->createWithContent('corrected.pdf', '%PDF-1.4 fake pdf content for tests'),
            ])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame('Corrected Title', $document->title);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'could not delete the superseded document file'));
    }
}
