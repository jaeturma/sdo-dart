<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
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

/**
 * Staff under the Office of the Assistant Schools Division Superintendent (OASDS) report
 * directly to the ASDS — unlike OSDS's other sub-units (ICTU/LU/PAU), OASDS has
 * no unit head of its own, and there's no division chief anywhere in the OSDS
 * tree either. Without OutgoingDocumentController::initialStatus()'s OASDS
 * check (keyed off the division's 'OASDS' code — see the
 * 2026_08_03_123432_add_oasds_code_to_divisions_table migration), their
 * uploads would fall through to `Submitted`/division-chief review, which
 * nobody could ever act on.
 */
class OasdsStaffOutgoingDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $oasds;
    private User $asds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'kmt_focal', 'asds', 'sds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->oasds = Division::create([
            'name' => 'Office of the Assistant Schools Division Superintendent',
            'code' => 'OASDS',
            'parent_id' => $this->osds->id,
        ]);

        $this->asds = User::factory()->create(['division_id' => $this->oasds->id]);
        $this->asds->assignRole('asds');
    }

    private function uploadAsOasdsStaff(User $staff, string $title): Document
    {
        $this->actingAs($staff)->post('/documents/outgoing', [
            'title' => $title,
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        return Document::firstWhere('title', $title);
    }

    public function test_oasds_staff_upload_goes_directly_to_asds_review(): void
    {
        $staff = User::factory()->create(['division_id' => $this->oasds->id]);
        $staff->assignRole(['originator', 'kmt_focal']);

        $document = $this->uploadAsOasdsStaff($staff, 'OASDS staff memo');

        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
        $this->assertTrue($this->asds->can('review', $document));

        $this->actingAs($this->asds)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    /**
     * Unlike the OSDS unit-head/secretary branches (which do honor the
     * toggle, skipping straight to SDS when ASDS is marked unavailable), OASDS
     * staff's uploads always target ASDS review regardless of it — the
     * toggle exists to bypass her as an *optional* layer for the rest of
     * the org's escalations, but she's still the mandatory, direct reviewer
     * for her own OASDS staff even while that's toggled off, same as any
     * division chief has no "unavailable" bypass for their own staff either.
     */
    public function test_oasds_staff_upload_still_goes_to_asds_review_even_when_marked_unavailable(): void
    {
        AsdsAvailabilitySetting::toggle();
        $this->assertFalse(AsdsAvailabilitySetting::isAvailable());

        $staff = User::factory()->create(['division_id' => $this->oasds->id]);
        $staff->assignRole(['originator', 'kmt_focal']);

        $document = $this->uploadAsOasdsStaff($staff, 'OASDS staff memo, ASDS unavailable');

        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
        $this->assertTrue($this->asds->can('review', $document));
    }

    /**
     * A resubmission after being returned for revision must also skip
     * straight back to ASDS review, not fall through to the unit-head/chief
     * fallback — updateResubmit() reuses initialStatus(), so this is really
     * confirming that reuse holds for OASDS too.
     */
    public function test_oasds_staff_resubmission_also_goes_directly_to_asds_review(): void
    {
        $staff = User::factory()->create(['division_id' => $this->oasds->id]);
        $staff->assignRole(['originator', 'kmt_focal']);

        $document = $this->uploadAsOasdsStaff($staff, 'OASDS staff memo needing revision');
        $document->update(['status' => DocumentStatus::ReturnedForRevision]);

        $this->actingAs($staff)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'OASDS staff memo needing revision',
                'description' => 'Fixed as requested.',
                'file' => UploadedFile::fake()->createWithContent('memo2.pdf', '%PDF-1.4 revised fake pdf content'),
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->fresh()->status);
    }
}
