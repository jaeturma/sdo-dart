<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ReviewDocumentRequest::rules() requires remarks for 'returned_for_revision'
 * and 'rejected' — 'for_comment' was missing from that list, which let a
 * reviewer (including the SDS) submit "for comment" with empty remarks,
 * leaving the originator nothing to act on. A real production row showed
 * exactly this: an SDS review row with action=for_comment and remarks=''.
 */
class ReviewRemarksRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $originator;

    private User $sds;

    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'division_chief', 'sds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->originator = User::factory()->create(['division_id' => $this->division->id]);
        $this->originator->assignRole('originator');

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->chief = User::factory()->create(['division_id' => $this->division->id]);
        $this->chief->assignRole('division_chief');
    }

    private function documentAt(DocumentStatus $status): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Test memo',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/test.pdf', 'file_hash' => hash('sha256', uniqid()),
            'status' => $status,
        ]);
    }

    public function test_sds_for_comment_with_empty_remarks_fails_validation(): void
    {
        $document = $this->documentAt(DocumentStatus::PendingSdsReview);

        $this->actingAs($this->sds)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'for_comment',
                'remarks' => '',
            ])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(DocumentStatus::PendingSdsReview, $document->fresh()->status);
    }

    public function test_sds_for_comment_with_whitespace_only_remarks_fails_validation(): void
    {
        $document = $this->documentAt(DocumentStatus::PendingSdsReview);

        $this->actingAs($this->sds)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'for_comment',
                'remarks' => "   \n  ",
            ])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(DocumentStatus::PendingSdsReview, $document->fresh()->status);
    }

    public function test_division_chief_for_comment_with_empty_remarks_fails_validation(): void
    {
        $document = $this->documentAt(DocumentStatus::PendingDivisionChiefReview);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'for_comment',
                'remarks' => '',
            ])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(DocumentStatus::PendingDivisionChiefReview, $document->fresh()->status);
    }

    public function test_for_comment_with_real_remarks_succeeds(): void
    {
        $document = $this->documentAt(DocumentStatus::PendingSdsReview);

        $this->actingAs($this->sds)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'for_comment',
                'remarks' => 'Please clarify the budget allocation.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::ForComment, $document->fresh()->status);
    }

    public function test_returned_for_revision_with_empty_remarks_still_fails_validation(): void
    {
        $document = $this->documentAt(DocumentStatus::PendingDivisionChiefReview);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'returned_for_revision',
                'remarks' => '',
            ])
            ->assertSessionHasErrors('remarks');
    }

    public function test_rejected_with_empty_remarks_still_fails_validation(): void
    {
        $document = $this->documentAt(DocumentStatus::PendingDivisionChiefReview);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'rejected',
                'remarks' => '',
            ])
            ->assertSessionHasErrors('remarks');
    }

    public function test_approve_with_empty_remarks_is_still_allowed(): void
    {
        $document = $this->documentAt(DocumentStatus::PendingDivisionChiefReview);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), [
                'action' => 'approved_forwarded',
                'remarks' => '',
            ])
            ->assertSessionHasNoErrors();
    }
}
