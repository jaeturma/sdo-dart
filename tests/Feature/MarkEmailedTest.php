<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A follow-up step after release: Records Section confirms they downloaded
 * the released PDF and emailed it out. Recorded in the review history
 * (transaction history) for the originating office to see, and notifies the
 * uploader plus their supervisor (division chief, or unit head for an OSDS
 * sub-unit with no chief of its own).
 */
class MarkEmailedTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private Division $records;
    private User $recordsClerk;
    private User $outsider;
    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'unit_head'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');

        $this->outsider = User::factory()->create(['division_id' => $this->ad->id]);
        $this->outsider->assignRole('originator');

        $this->uploader = User::factory()->create(['division_id' => $this->ad->id]);
        $this->uploader->assignRole('originator');
    }

    private function releasedDocument(?Division $division = null): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Released memo',
            'uploaded_by' => $this->uploader->id, 'division_id' => ($division ?? $this->ad)->id,
            'file_path' => 'outgoing/released.pdf', 'file_hash' => hash('sha256', 'released'),
            'status' => DocumentStatus::Released, 'released_at' => now(), 'released_by' => $this->recordsClerk->id,
        ]);
    }

    public function test_records_section_can_mark_a_released_document_as_emailed(): void
    {
        Notification::fake();

        $chief = User::factory()->create(['division_id' => $this->ad->id]);
        $chief->assignRole('division_chief');

        $document = $this->releasedDocument();

        $this->assertTrue($this->recordsClerk->can('markEmailed', $document));

        $this->actingAs($this->recordsClerk)
            ->post(route('documents.release.emailed', $document), ['remarks' => 'Sent to registrar.'])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Released, $document->status);
        $this->assertNotNull($document->emailed_at);
        $this->assertSame($this->recordsClerk->id, $document->emailed_by);

        $this->assertDatabaseHas('document_reviews', [
            'document_id'    => $document->id,
            'reviewer_id'    => $this->recordsClerk->id,
            'role_at_review' => 'records_section',
            'action'         => 'emailed',
            'remarks'        => 'Sent to registrar.',
        ]);

        Notification::assertSentTo($this->uploader, DocumentEvent::class);
        Notification::assertSentTo($chief, DocumentEvent::class);
    }

    public function test_notifies_the_chief_of_the_parent_division_when_uploader_is_in_a_sub_unit(): void
    {
        Notification::fake();

        $subUnit = Division::create(['name' => 'Procurement Unit', 'parent_id' => $this->ad->id]);
        $subUploader = User::factory()->create(['division_id' => $subUnit->id]);
        $subUploader->assignRole('originator');
        $chief = User::factory()->create(['division_id' => $this->ad->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Sub-unit released memo',
            'uploaded_by' => $subUploader->id, 'division_id' => $subUnit->id,
            'file_path' => 'outgoing/released2.pdf', 'file_hash' => hash('sha256', 'released2'),
            'status' => DocumentStatus::Released, 'released_at' => now(), 'released_by' => $this->recordsClerk->id,
        ]);

        $this->actingAs($this->recordsClerk)
            ->post(route('documents.release.emailed', $document))
            ->assertRedirect(route('documents.show', $document));

        Notification::assertSentTo($subUploader, DocumentEvent::class);
        Notification::assertSentTo($chief, DocumentEvent::class);
    }

    public function test_notifies_the_unit_head_when_the_division_has_no_chief(): void
    {
        Notification::fake();

        $osds  = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $ictu = Division::create(['name' => 'ICT Unit', 'parent_id' => $osds->id]);
        $ictuUploader = User::factory()->create(['division_id' => $ictu->id]);
        $ictuUploader->assignRole('originator');
        $unitHead = User::factory()->create(['division_id' => $ictu->id]);
        $unitHead->assignRole('unit_head');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'ICTU released memo',
            'uploaded_by' => $ictuUploader->id, 'division_id' => $ictu->id,
            'file_path' => 'outgoing/released3.pdf', 'file_hash' => hash('sha256', 'released3'),
            'status' => DocumentStatus::Released, 'released_at' => now(), 'released_by' => $this->recordsClerk->id,
        ]);

        $this->actingAs($this->recordsClerk)
            ->post(route('documents.release.emailed', $document))
            ->assertRedirect(route('documents.show', $document));

        Notification::assertSentTo($unitHead, DocumentEvent::class);
    }

    public function test_cannot_mark_emailed_before_release(): void
    {
        $document = $this->releasedDocument();
        $document->update(['status' => DocumentStatus::Signed]);

        $this->assertFalse($this->recordsClerk->can('markEmailed', $document));

        $this->actingAs($this->recordsClerk)
            ->post(route('documents.release.emailed', $document))
            ->assertForbidden();
    }

    public function test_cannot_mark_emailed_twice(): void
    {
        $document = $this->releasedDocument();
        $document->forceFill(['emailed_at' => now(), 'emailed_by' => $this->recordsClerk->id])->save();

        $this->assertFalse($this->recordsClerk->can('markEmailed', $document));

        $this->actingAs($this->recordsClerk)
            ->post(route('documents.release.emailed', $document))
            ->assertForbidden();
    }

    public function test_non_records_section_user_cannot_mark_emailed(): void
    {
        $document = $this->releasedDocument();

        $this->assertFalse($this->outsider->can('markEmailed', $document));

        $this->actingAs($this->outsider)
            ->post(route('documents.release.emailed', $document))
            ->assertForbidden();

        $this->assertNull($document->fresh()->emailed_at);
    }
}
