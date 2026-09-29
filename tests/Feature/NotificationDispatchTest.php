<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecipientStatus;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRouting;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use App\Notifications\DocumentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_submission_notifies_the_unit_head_when_one_exists_in_division(): void
    {
        Notification::fake();

        $division = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);
        $unitHead = User::factory()->create(['division_id' => $division->id]);
        $unitHead->assignRole('unit_head');
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title' => 'Memo', 'file' => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        Notification::assertSentTo($unitHead, DocumentEvent::class);
    }

    public function test_submission_notifies_division_chief_when_no_unit_head_exists(): void
    {
        Notification::fake();

        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title' => 'Memo', 'file' => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        Notification::assertSentTo($chief, DocumentEvent::class);
    }

    public function test_chief_or_unit_head_own_submission_notifies_ard(): void
    {
        Notification::fake();

        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');
        $ard = User::factory()->create();
        $ard->assignRole('ard');

        $this->actingAs($chief)->post('/documents/outgoing', [
            'title' => 'Memo', 'file' => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        Notification::assertSentTo($ard, DocumentEvent::class);
    }

    public function test_approved_forwarded_notifies_the_next_reviewer(): void
    {
        Notification::fake();

        // Top-level division (no parent) so nextStatusAfterUnitHead() skips
        // straight to ARD once the unit head approves.
        $division = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);
        $unitHead = User::factory()->create(['division_id' => $division->id]);
        $unitHead->assignRole('unit_head');
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole('originator');
        $ard = User::factory()->create();
        $ard->assignRole('ard');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $originator->id,
            'division_id' => $division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingUnitHeadReview,
        ]);

        $this->actingAs($unitHead)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::PendingArdReview, $document->status);
        Notification::assertSentTo($ard, DocumentEvent::class);
    }

    public function test_final_rd_approval_notifies_secretary_and_uploader(): void
    {
        Notification::fake();

        $originator = User::factory()->create();
        $originator->assignRole('originator');
        $rd = User::factory()->create();
        $rd->assignRole('rd');
        $secretary = User::factory()->create();
        $secretary->assignRole('secretary');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $originator->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::PendingRdReview,
        ]);

        $this->actingAs($rd)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);
        Notification::assertSentTo($secretary, DocumentEvent::class);
        Notification::assertSentTo($originator, DocumentEvent::class);
    }

    public function test_for_comment_returned_and_rejected_all_notify_the_uploader(): void
    {
        Notification::fake();

        $originator = User::factory()->create();
        $originator->assignRole('originator');
        $rd = User::factory()->create();
        $rd->assignRole('rd');

        foreach (['for_comment', 'returned_for_revision', 'rejected'] as $action) {
            $document = Document::create([
                'type' => DocumentType::Outgoing, 'title' => "Memo {$action}", 'uploaded_by' => $originator->id,
                'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', $action),
                'status' => DocumentStatus::PendingRdReview,
            ]);

            $this->actingAs($rd)
                ->post(route('documents.reviews.store', $document), ['action' => $action, 'remarks' => 'Please fix.'])
                ->assertRedirect();
        }

        Notification::assertSentToTimes($originator, DocumentEvent::class, 3);
    }

    public function test_signing_notifies_the_uploader_and_their_division_chief(): void
    {
        // Exercises NotificationDispatcher::documentSigned() directly rather than
        // the full HTTP signing flow, which needs a real PKCS#12 file and PDF —
        // that mechanics is already covered by DocumentSigner's own concerns;
        // what matters here is the dispatcher wiring.
        Notification::fake();

        $division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole('originator');
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $originator->id,
            'division_id' => $division->id,
            'file_path' => 'outgoing/2026/07/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Signed,
        ]);

        \App\Services\NotificationDispatcher::documentSigned($document);

        Notification::assertSentTo($originator, DocumentEvent::class);
        Notification::assertSentTo($chief, DocumentEvent::class);
    }

    public function test_signing_does_not_double_notify_when_the_uploader_is_their_own_chief(): void
    {
        Notification::fake();

        $division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $chief->id,
            'division_id' => $division->id,
            'file_path' => 'outgoing/2026/07/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Signed,
        ]);

        \App\Services\NotificationDispatcher::documentSigned($document);

        Notification::assertSentToTimes($chief, DocumentEvent::class, 1);
    }

    public function test_routing_notifies_the_targeted_user_and_every_member_of_a_targeted_division(): void
    {
        Notification::fake();

        $division = Division::create(['name' => 'Records Section', 'code' => 'RS']);
        $clerkA = User::factory()->create(['division_id' => $division->id]);
        $clerkA->assignRole('originator');
        $clerkB = User::factory()->create(['division_id' => $division->id]);
        $clerkB->assignRole('originator');

        $rd = User::factory()->create();
        $rd->assignRole('rd');
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $ordp = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);
        $secretary = User::factory()->create(['division_id' => $ordp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)->post('/documents/incoming', [
            'title' => 'Test memo', 'source_origin' => 'Central Office',
            'file' => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ])->assertRedirect();

        // Auto-forward targets the Records Section division as a whole — every
        // member should be notified, not just one hand-picked clerk.
        Notification::assertSentTo($clerkA, DocumentEvent::class);
        Notification::assertSentTo($clerkB, DocumentEvent::class);

        // The RD is deliberately not a routing recipient for these
        // auto-forwarded uploads (she acts on them directly rather than
        // acknowledging an assignment), so she needs her own notification —
        // documentRouted() alone would never reach her.
        Notification::assertSentTo($rd, DocumentEvent::class);
    }

    public function test_document_completed_notifies_every_distinct_router_across_multiple_rounds(): void
    {
        Notification::fake();

        $division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $rd = User::factory()->create();
        $rd->assignRole('rd');
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');
        $staff = User::factory()->create(['division_id' => $division->id]);
        $staff->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Memo', 'uploaded_by' => $rd->id,
            'file_path' => 'incoming/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Routed,
        ]);

        $round1 = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $rd->id, 'is_rush' => false, 'routed_at' => now(),
        ]);
        DocumentRoutingRecipient::create([
            'routing_id' => $round1->id, 'user_id' => $chief->id, 'action' => 'appropriate_action',
            'status' => RecipientStatus::Completed, 'completed_at' => now(), 'completed_by' => $chief->id,
        ]);

        $round2 = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $chief->id, 'is_rush' => false, 'routed_at' => now(),
        ]);
        $round2Recipient = DocumentRoutingRecipient::create([
            'routing_id' => $round2->id, 'user_id' => $staff->id, 'action' => 'appropriate_action',
            'status' => RecipientStatus::Pending,
        ]);

        $this->actingAs($staff)
            ->patch(route('routing-recipients.acknowledge', $round2Recipient))
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);

        Notification::assertSentTo($rd, DocumentEvent::class);
        Notification::assertSentTo($chief, DocumentEvent::class);
    }

    public function test_no_eligible_reviewer_does_not_throw(): void
    {
        Notification::fake();

        // No one holds the ard role at all.
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $originator->id,
            'division_id' => $division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::Submitted,
        ]);

        $this->actingAs($chief)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::PendingArdReview, $document->status);
        // No assertion needed beyond "the request succeeded" — dispatch to an
        // empty recipient set must be a silent no-op.
    }
}
