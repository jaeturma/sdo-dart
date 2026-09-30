<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\ArdAvailabilitySetting;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArdAvailabilityTest extends TestCase
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

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd'] as $role) {
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

    public function test_ard_is_available_by_default(): void
    {
        $this->assertTrue(ArdAvailabilitySetting::isAvailable());
    }

    public function test_admin_can_toggle_ard_availability(): void
    {
        $this->actingAs($this->admin)
            ->post(route('ard-availability-setting.toggle'))
            ->assertRedirect();

        $this->assertFalse(ArdAvailabilitySetting::isAvailable());

        $this->actingAs($this->admin)->post(route('ard-availability-setting.toggle'));
        $this->assertTrue(ArdAvailabilitySetting::isAvailable());
    }

    public function test_non_admin_cannot_toggle_ard_availability(): void
    {
        $this->actingAs($this->originator)
            ->post(route('ard-availability-setting.toggle'))
            ->assertForbidden();

        $this->assertTrue(ArdAvailabilitySetting::isAvailable());
    }

    public function test_chiefs_own_upload_skips_ard_when_unavailable(): void
    {
        ArdAvailabilitySetting::toggle();

        $this->actingAs($this->chief)->post('/documents/outgoing', [
            'title' => 'Chief memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Chief memo');
        $this->assertSame(DocumentStatus::PendingRdReview, $document->status);
    }

    public function test_chiefs_own_upload_goes_to_ard_when_available(): void
    {
        $this->actingAs($this->chief)->post('/documents/outgoing', [
            'title' => 'Chief memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Chief memo');
        $this->assertSame(DocumentStatus::PendingArdReview, $document->status);
    }

    public function test_chief_approval_skips_ard_when_unavailable(): void
    {
        ArdAvailabilitySetting::toggle();

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingRdReview, $document->fresh()->status);
    }

    public function test_chief_approval_goes_to_ard_when_available(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingArdReview, $document->fresh()->status);
    }

    public function test_unit_head_with_no_parent_chief_skips_ard_when_unavailable(): void
    {
        ArdAvailabilitySetting::toggle();

        $unitHead = User::factory()->create(['division_id' => $this->division->id]);
        $unitHead->assignRole('unit_head');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingUnitHeadReview,
        ]);

        $this->actingAs($unitHead)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        // Finance Division has no parent division, so it would normally go
        // straight to ARD from here — now it should skip to RD instead.
        $this->assertSame(DocumentStatus::PendingRdReview, $document->fresh()->status);
    }

    public function test_toggling_does_not_affect_a_document_already_pending_ard_review(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Memo', 'uploaded_by' => $this->originator->id,
            'division_id' => $this->division->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingArdReview,
        ]);

        ArdAvailabilitySetting::toggle();

        $ard = User::factory()->create();
        $ard->assignRole('ard');

        $this->actingAs($ard)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingRdReview, $document->fresh()->status);
    }
}
