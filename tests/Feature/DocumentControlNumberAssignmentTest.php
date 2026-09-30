<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentControlNumberAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_outgoing_submission_is_assigned_a_control_number(): void
    {
        $division = Division::create(['name' => 'Curriculum & Learning Management Division', 'code' => 'CLMD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title'            => 'Memo needing a control number',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Memo needing a control number');
        $this->assertNotNull($document->control_number);
        $this->assertMatchesRegularExpression('/^OUTGOING-CLMD-\d{4}-\d{2}-\d{3}$/', $document->control_number);
    }

    public function test_incoming_submission_is_assigned_a_control_number(): void
    {
        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $osdsp = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $osds->id]);
        $secretary = User::factory()->create(['division_id' => $osdsp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)->post('/documents/incoming', [
            'title'         => 'Incoming memo needing a control number',
            'source_origin' => 'DepEd Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Incoming memo needing a control number');
        $this->assertNotNull($document->control_number);
        $this->assertMatchesRegularExpression('/^INCOMING-\d{4}-\d{2}-\d{3}$/', $document->control_number);
    }

    public function test_resubmitting_an_outgoing_document_does_not_change_its_control_number(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole('originator');

        $document = Document::create([
            'type'        => \App\Enums\DocumentType::Outgoing,
            'title'       => 'Returned memo',
            'uploaded_by' => $originator->id,
            'division_id' => $division->id,
            'file_path'   => 'outgoing/2026/07/original.pdf',
            'file_hash'   => hash('sha256', 'original'),
            'status'      => DocumentStatus::ReturnedForRevision,
            'control_number' => 'OUTGOING-FD-2026-07-001',
        ]);

        $this->actingAs($originator)->put(route('documents.outgoing.resubmit.update', $document), [
            'title'            => 'Returned memo (revised)',
            'file'             => UploadedFile::fake()->createWithContent('revised.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame('OUTGOING-FD-2026-07-001', $document->control_number);
    }

    public function test_search_matches_by_control_number(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole('originator');

        $document = Document::create([
            'type'           => \App\Enums\DocumentType::Outgoing,
            'title'          => 'Findable memo',
            'uploaded_by'    => $originator->id,
            'division_id'    => $division->id,
            'file_path'      => 'outgoing/2026/07/findable.pdf',
            'file_hash'      => hash('sha256', 'findable'),
            'status'         => DocumentStatus::Draft,
            'control_number' => 'OUTGOING-FD-2026-07-042',
        ]);

        $this->actingAs($originator)
            ->get(route('documents.index', ['tab' => 'outgoing', 'search' => 'FD-2026-07-042']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_search_matches_by_division_name(): void
    {
        $division = Division::create(['name' => 'Quality Assurance Division', 'code' => 'QAD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole('originator');

        $document = Document::create([
            'type'           => \App\Enums\DocumentType::Outgoing,
            'title'          => 'QAD memo',
            'uploaded_by'    => $originator->id,
            'division_id'    => $division->id,
            'file_path'      => 'outgoing/2026/07/qad.pdf',
            'file_hash'      => hash('sha256', 'qad'),
            'status'         => DocumentStatus::Draft,
            'control_number' => 'OUTGOING-QAD-2026-07-001',
        ]);

        $this->actingAs($originator)
            ->get(route('documents.index', ['tab' => 'outgoing', 'search' => 'Quality Assurance']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }
}
