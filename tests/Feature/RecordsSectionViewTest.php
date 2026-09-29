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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecordsSectionViewTest extends TestCase
{
    use RefreshDatabase;

    private Division $ordp;
    private Division $records;
    private Division $ad;
    private User $secretary;
    private User $rd;
    private User $admin;
    private User $recordsClerk;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->ordp = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $ord->id]);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'parent_id' => $this->ad->id]);

        $this->secretary = User::factory()->create(['division_id' => $this->ordp->id]);
        $this->secretary->assignRole('secretary');

        $this->rd = User::factory()->create(['division_id' => $ord->id]);
        $this->rd->assignRole('rd');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');

        $this->outsider = User::factory()->create(['division_id' => $this->ad->id]);
        $this->outsider->assignRole('originator');
    }

    public function test_records_section_sees_only_secretary_auto_forwarded_documents(): void
    {
        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Auto-forwarded memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => $file,
        ]);
        $autoForwarded = Document::firstWhere('title', 'Auto-forwarded memo');

        // A document manually routed to Records Section by the RD — NOT
        // auto-forwarded, must not appear in this dedicated view.
        $manuallyRouted = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Manually routed memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/manual.pdf', 'file_hash' => hash('sha256', 'manual'),
            'status' => DocumentStatus::Routed,
        ]);
        $routing = DocumentRouting::create([
            'document_id' => $manuallyRouted->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(), 'remarks' => 'Please file.',
        ]);
        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'division_id' => $this->records->id,
            'action' => 'file', 'status' => RecipientStatus::Pending,
        ]);

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.index', ['tab' => 'records_incoming']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($autoForwarded->id)
                    && ! collect($docs)->pluck('id')->contains($manuallyRouted->id),
            ));
    }

    public function test_non_records_section_user_sees_nothing_on_records_incoming_tab(): void
    {
        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Auto-forwarded memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => $file,
        ]);

        $this->actingAs($this->outsider)
            ->get(route('documents.index', ['tab' => 'records_incoming']))
            ->assertInertia(fn ($page) => $page->where('documents.data', fn ($docs) => count($docs) === 0));
    }

    public function test_ready_to_release_shows_signed_and_excludes_released(): void
    {
        $signedDoc = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Signed memo', 'uploaded_by' => $this->outsider->id,
            'division_id' => $this->ad->id, 'file_path' => 'outgoing/signed.pdf',
            'file_hash' => hash('sha256', 'signed'), 'status' => DocumentStatus::Signed,
        ]);
        $releasedDoc = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Released memo', 'uploaded_by' => $this->outsider->id,
            'division_id' => $this->ad->id, 'file_path' => 'outgoing/released.pdf',
            'file_hash' => hash('sha256', 'released'), 'status' => DocumentStatus::Released,
            'released_at' => now(), 'released_by' => $this->recordsClerk->id,
        ]);

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.index', ['tab' => 'ready_to_release']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($signedDoc->id)
                    && ! collect($docs)->pluck('id')->contains($releasedDoc->id),
            ));
    }

    public function test_records_handled_shows_only_completed_secretary_forwarded_documents(): void
    {
        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Still pending memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => $file,
        ]);
        $pending = Document::firstWhere('title', 'Still pending memo');

        $file2 = UploadedFile::fake()->createWithContent('inbound2.pdf', '%PDF-1.4 fake pdf content for tests');
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Handled memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => $file2,
        ]);
        $handled = Document::firstWhere('title', 'Handled memo');
        $handled->load('routings.recipients');
        $recipient = $handled->routings->first()->recipients->first();

        $this->actingAs($this->recordsClerk)->patch(
            route('routing-recipients.acknowledge', $recipient),
            ['response_remarks' => 'Filed.'],
        );

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.index', ['tab' => 'records_handled']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($handled->id)
                    && ! collect($docs)->pluck('id')->contains($pending->id),
            ));
    }

    public function test_released_tab_shows_only_released_documents(): void
    {
        $signedDoc = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Signed memo', 'uploaded_by' => $this->outsider->id,
            'division_id' => $this->ad->id, 'file_path' => 'outgoing/signed.pdf',
            'file_hash' => hash('sha256', 'signed'), 'status' => DocumentStatus::Signed,
        ]);
        $releasedDoc = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Released memo', 'uploaded_by' => $this->outsider->id,
            'division_id' => $this->ad->id, 'file_path' => 'outgoing/released.pdf',
            'file_hash' => hash('sha256', 'released'), 'status' => DocumentStatus::Released,
            'released_at' => now(), 'released_by' => $this->recordsClerk->id,
        ]);

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.index', ['tab' => 'released']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($releasedDoc->id)
                    && ! collect($docs)->pluck('id')->contains($signedDoc->id),
            ));
    }

    public function test_admin_can_also_see_both_records_section_tabs(): void
    {
        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Auto-forwarded memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => $file,
        ]);
        $autoForwarded = Document::firstWhere('title', 'Auto-forwarded memo');

        $signedDoc = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Signed memo', 'uploaded_by' => $this->outsider->id,
            'division_id' => $this->ad->id, 'file_path' => 'outgoing/signed.pdf',
            'file_hash' => hash('sha256', 'signed'), 'status' => DocumentStatus::Signed,
        ]);

        $this->actingAs($this->admin)
            ->get(route('documents.index', ['tab' => 'records_incoming']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($autoForwarded->id),
            ));

        $this->actingAs($this->admin)
            ->get(route('documents.index', ['tab' => 'ready_to_release']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($signedDoc->id),
            ));
    }
}
