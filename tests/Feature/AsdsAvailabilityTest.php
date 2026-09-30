<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\AsdsAvailabilitySetting;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AsdsAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Division $division;
    private User $chief;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);

        $this->chief = User::factory()->create(['division_id' => $this->division->id]);
        $this->chief->assignRole('division_chief');

        $this->originator = User::factory()->create(['division_id' => $this->division->id]);
        $this->originator->assignRole('originator');
    }

    public function test_asds_is_available_by_default(): void
    {
        $this->assertTrue(AsdsAvailabilitySetting::isAvailable());
    }

    public function test_admin_can_toggle_asds_availability(): void
    {
        $this->actingAs($this->admin)
            ->post(route('asds-availability-setting.toggle'))
            ->assertRedirect();

        $this->assertFalse(AsdsAvailabilitySetting::isAvailable());

        $this->actingAs($this->admin)->post(route('asds-availability-setting.toggle'));
        $this->assertTrue(AsdsAvailabilitySetting::isAvailable());
    }

    public function test_non_admin_cannot_toggle_asds_availability(): void
    {
        $this->actingAs($this->originator)
            ->post(route('asds-availability-setting.toggle'))
            ->assertForbidden();

        $this->assertTrue(AsdsAvailabilitySetting::isAvailable());
    }

    public function test_chiefs_own_upload_skips_asds_when_unavailable(): void
    {
        AsdsAvailabilitySetting::toggle();

        $this->actingAs($this->chief)->post('/documents/outgoing', [
            'title' => 'Chief memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Chief memo');
        $this->assertSame(DocumentStatus::PendingSdsReview, $document->status);
    }

    public function test_chiefs_own_upload_goes_to_asds_when_available(): void
    {
        $this->actingAs($this->chief)->post('/documents/outgoing', [
            'title' => 'Chief memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Chief memo');
        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
    }

    public function test_chief_approval_skips_asds_when_unavailable(): void
    {
        AsdsAvailabilitySetting::toggle();

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingSdsReview, $document->fresh()->status);
    }

    public function test_chief_approval_goes_to_asds_when_available(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->fresh()->status);
    }

    public function test_unit_head_with_no_parent_chief_skips_asds_when_unavailable(): void
    {
        AsdsAvailabilitySetting::toggle();

        // Unlike Finance Division (which has its own chief — see setUp()),
        // this top-level unit has no chief of its own or above it.
        $standalone = Division::create(['name' => 'Standalone Unit', 'code' => 'SU']);

        $unitHead = User::factory()->create(['division_id' => $standalone->id]);
        $unitHead->assignRole('unit_head');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $standalone->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingUnitHeadReview,
        ]);

        $this->actingAs($unitHead)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        // No chief anywhere above, so it would normally go straight to ASDS
        // from here — now it should skip to SDS instead.
        $this->assertSame(DocumentStatus::PendingSdsReview, $document->fresh()->status);
    }

    public function test_toggling_does_not_affect_a_document_already_pending_asds_review(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingAsdsReview,
        ]);

        AsdsAvailabilitySetting::toggle();

        $asds = User::factory()->create();
        $asds->assignRole('asds');

        $this->actingAs($asds)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingSdsReview, $document->fresh()->status);
    }
}
