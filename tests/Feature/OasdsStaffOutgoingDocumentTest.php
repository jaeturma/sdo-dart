<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
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

/**
 * Staff under the Office of the Assistant Regional Director (OARD) report
 * directly to the ARD — unlike ORD's other sub-units (ICTU/LU/PAU), OARD has
 * no unit head of its own, and there's no division chief anywhere in the ORD
 * tree either. Without OutgoingDocumentController::initialStatus()'s OARD
 * check (keyed off the division's 'OARD' code — see the
 * 2026_08_03_123432_add_oard_code_to_divisions_table migration), their
 * uploads would fall through to `Submitted`/division-chief review, which
 * nobody could ever act on.
 */
class OardStaffOutgoingDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;
    private Division $oard;
    private User $ard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'kmt_focal', 'ard', 'rd'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->oard = Division::create([
            'name' => 'Office of the Assistant Regional Director',
            'code' => 'OARD',
            'parent_id' => $this->ord->id,
        ]);

        $this->ard = User::factory()->create(['division_id' => $this->oard->id]);
        $this->ard->assignRole('ard');
    }

    private function uploadAsOardStaff(User $staff, string $title): Document
    {
        $this->actingAs($staff)->post('/documents/outgoing', [
            'title' => $title,
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        return Document::firstWhere('title', $title);
    }

    public function test_oard_staff_upload_goes_directly_to_ard_review(): void
    {
        $staff = User::factory()->create(['division_id' => $this->oard->id]);
        $staff->assignRole(['originator', 'kmt_focal']);

        $document = $this->uploadAsOardStaff($staff, 'OARD staff memo');

        $this->assertSame(DocumentStatus::PendingArdReview, $document->status);
        $this->assertTrue($this->ard->can('review', $document));

        $this->actingAs($this->ard)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    /**
     * Unlike the ORD unit-head/secretary branches (which do honor the
     * toggle, skipping straight to RD when ARD is marked unavailable), OARD
     * staff's uploads always target ARD review regardless of it — the
     * toggle exists to bypass her as an *optional* layer for the rest of
     * the org's escalations, but she's still the mandatory, direct reviewer
     * for her own OARD staff even while that's toggled off, same as any
     * division chief has no "unavailable" bypass for their own staff either.
     */
    public function test_oard_staff_upload_still_goes_to_ard_review_even_when_marked_unavailable(): void
    {
        ArdAvailabilitySetting::toggle();
        $this->assertFalse(ArdAvailabilitySetting::isAvailable());

        $staff = User::factory()->create(['division_id' => $this->oard->id]);
        $staff->assignRole(['originator', 'kmt_focal']);

        $document = $this->uploadAsOardStaff($staff, 'OARD staff memo, ARD unavailable');

        $this->assertSame(DocumentStatus::PendingArdReview, $document->status);
        $this->assertTrue($this->ard->can('review', $document));
    }

    /**
     * A resubmission after being returned for revision must also skip
     * straight back to ARD review, not fall through to the unit-head/chief
     * fallback — updateResubmit() reuses initialStatus(), so this is really
     * confirming that reuse holds for OARD too.
     */
    public function test_oard_staff_resubmission_also_goes_directly_to_ard_review(): void
    {
        $staff = User::factory()->create(['division_id' => $this->oard->id]);
        $staff->assignRole(['originator', 'kmt_focal']);

        $document = $this->uploadAsOardStaff($staff, 'OARD staff memo needing revision');
        $document->update(['status' => DocumentStatus::ReturnedForRevision]);

        $this->actingAs($staff)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'OARD staff memo needing revision',
                'description' => 'Fixed as requested.',
                'file' => UploadedFile::fake()->createWithContent('memo2.pdf', '%PDF-1.4 revised fake pdf content'),
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingArdReview, $document->fresh()->status);
    }
}
