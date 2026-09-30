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

class OutgoingDocumentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_division_chief_can_create_an_outgoing_document(): void
    {
        $division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');

        $this->actingAs($chief)
            ->get(route('documents.outgoing.create'))
            ->assertOk();

        $this->actingAs($chief)
            ->post('/documents/outgoing', [
                'title' => 'Chief memo',
                'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
                'requires_release' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('documents', ['title' => 'Chief memo', 'uploaded_by' => $chief->id]);

        $document = Document::firstWhere('title', 'Chief memo');
        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
    }

    public function test_osds_unit_head_upload_is_auto_forwarded_to_asds(): void
    {
        $osds  = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $ictu = Division::create(['name' => 'ICT Unit', 'code' => 'ICTU', 'parent_id' => $osds->id]);
        $unitHead = User::factory()->create(['division_id' => $ictu->id]);
        $unitHead->assignRole('unit_head');

        $this->actingAs($unitHead)->post('/documents/outgoing', [
            'title' => 'Unit head memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Unit head memo');
        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
    }

    public function test_non_osds_unit_head_upload_goes_to_their_division_chief_first(): void
    {
        // Administrative Division has both a unit head and a division chief —
        // unlike OSDS's internal sub-units, this unit head's own upload should
        // NOT skip straight to ASDS; it needs their chief's review first.
        $division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $unitHead = User::factory()->create(['division_id' => $division->id]);
        $unitHead->assignRole('unit_head');
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');

        $this->actingAs($unitHead)->post('/documents/outgoing', [
            'title' => 'AD unit head memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'AD unit head memo');
        $this->assertSame(DocumentStatus::PendingDivisionChiefReview, $document->status);
        $this->assertTrue($chief->can('review', $document));
        $this->assertFalse($unitHead->can('review', $document));
    }

    /**
     * Reproduces the real production structure that test_non_osds_unit_head
     * _upload_goes_to_their_division_chief_first above did NOT: Administrative
     * Division has its own chief, but the unit head isn't in that same
     * division at all — they head a *sub-unit* (e.g. Procurement Unit) whose
     * parent_id points at Administrative Division. The chief who actually
     * reviews sits one level up. This was reported as a real bug: these
     * documents were skipping straight to ASDS/SDS instead of reaching the
     * chief, because the chief lookup only checked the unit head's own
     * division, never the parent.
     */
    public function test_sub_unit_head_upload_reaches_their_parent_divisions_chief(): void
    {
        $ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $procurement = Division::create(['name' => 'Procurement Unit', 'parent_id' => $ad->id]);

        $chief = User::factory()->create(['division_id' => $ad->id]);
        $chief->assignRole('division_chief');
        $unitHead = User::factory()->create(['division_id' => $procurement->id]);
        $unitHead->assignRole('unit_head');

        $this->actingAs($unitHead)->post('/documents/outgoing', [
            'title' => 'Procurement unit head memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Procurement unit head memo');
        $this->assertSame(DocumentStatus::PendingDivisionChiefReview, $document->status);
        $this->assertTrue($chief->can('review', $document));
        $this->assertFalse($unitHead->can('review', $document));

        // Not just authorized to review — must actually appear in the
        // chief's own Outgoing and For Action lists, or she'd never know it
        // exists to act on it.
        $this->actingAs($chief)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));

        $this->actingAs($chief)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    /**
     * OSDS has no division chief, so a staff member in an OSDS sub-office
     * with no unit head of its own would otherwise sit at `Submitted`
     * forever with nobody able to review it.
     */
    public function test_originator_with_no_unit_head_or_chief_above_them_goes_straight_to_asds(): void
    {
        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $legal = Division::create(['name' => 'Legal Unit', 'parent_id' => $osds->id]);
        $originator = User::factory()->create(['division_id' => $legal->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title' => 'Legal opinion',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Legal opinion');
        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
    }

    public function test_originator_with_no_reviewer_above_them_goes_to_sds_when_asds_unavailable(): void
    {
        \App\Models\AsdsAvailabilitySetting::toggle();

        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $originator = User::factory()->create(['division_id' => $osds->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title' => 'OSDS staff memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $this->assertSame(DocumentStatus::PendingSdsReview, Document::firstWhere('title', 'OSDS staff memo')->status);
    }

    /**
     * A unit head and a chief can share one top-level division (e.g. both
     * assigned directly to CID) — the unit head's approval must still reach
     * that chief rather than skipping them because the division has no parent.
     */
    public function test_unit_head_approval_reaches_a_chief_in_the_same_top_level_division(): void
    {
        $cid = Division::create(['name' => 'Curriculum Implementation Division', 'code' => 'CID']);
        $originator = User::factory()->create(['division_id' => $cid->id]);
        $originator->assignRole(['originator', 'kmt_focal']);
        $unitHead = User::factory()->create(['division_id' => $cid->id]);
        $unitHead->assignRole('unit_head');
        $chief = User::factory()->create(['division_id' => $cid->id]);
        $chief->assignRole('division_chief');

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title' => 'CID memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'CID memo');
        $this->assertSame(DocumentStatus::PendingUnitHeadReview, $document->status);

        $this->actingAs($unitHead)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::PendingDivisionChiefReview, $document->status);
        $this->assertTrue($chief->can('review', $document));
    }

    public function test_non_osds_unit_head_upload_skips_to_asds_when_their_division_has_no_chief(): void
    {
        $division = Division::create(['name' => 'Small Standalone Unit', 'code' => 'SSU']);
        $unitHead = User::factory()->create(['division_id' => $division->id]);
        $unitHead->assignRole('unit_head');

        $this->actingAs($unitHead)->post('/documents/outgoing', [
            'title' => 'Standalone unit head memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Standalone unit head memo');
        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
    }

    public function test_originator_upload_still_goes_through_unit_head_review_when_one_exists(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole(['originator', 'kmt_focal']);
        $unitHead = User::factory()->create(['division_id' => $division->id]);
        $unitHead->assignRole('unit_head');

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title' => 'Staff memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Staff memo');
        $this->assertSame(DocumentStatus::PendingUnitHeadReview, $document->status);
    }

    public function test_originator_upload_with_no_unit_head_shows_in_chiefs_for_action(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $originator = User::factory()->create(['division_id' => $division->id]);
        $originator->assignRole(['originator', 'kmt_focal']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');

        $this->actingAs($originator)->post('/documents/outgoing', [
            'title' => 'No unit head memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'No unit head memo');
        $this->assertSame(DocumentStatus::Submitted, $document->status);
        $this->assertTrue($chief->can('review', $document));

        $this->actingAs($chief)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_chief_never_sees_or_reviews_their_own_upload_even_if_stuck_at_submitted(): void
    {
        // Defends against a real data anomaly: a document can end up sitting
        // at `submitted`/`pending_division_chief_review` for the uploader's
        // own division (e.g. their role changed to division_chief after they
        // submitted it) — initialStatus() only prevents this going forward,
        // so the reviewer-scoping itself must also refuse self-review.
        $division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type'        => \App\Enums\DocumentType::Outgoing,
            'title'       => 'Chiefs own stuck memo',
            'uploaded_by' => $chief->id,
            'division_id' => $division->id,
            'file_path'   => 'outgoing/2026/07/stuck.pdf',
            'file_hash'   => hash('sha256', 'stuck'),
            'status'      => DocumentStatus::Submitted,
        ]);

        $this->assertFalse($chief->can('review', $document));

        $this->actingAs($chief)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));

        $this->actingAs($chief)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'approved_forwarded',
            ])
            ->assertForbidden();
    }
}
