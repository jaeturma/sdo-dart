<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ReviewAction;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A general QC gate for the secretary: any Approved document can be sent
 * back to its owner for a physical/formatting problem (missing pages, wrong
 * format) found while preparing to sign it — unlike returnToOwner() (see
 * SecretaryReturnToOwnerTest), this has no dependency on the SDS's review
 * history at all, and any secretary (not just the SDS's own OSDS secretary)
 * may use it, matching sign()'s scope. Deliberately kept as a separate
 * action from returnToOwner() rather than merged into it.
 */
class SecretaryReturnForCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;
    private User $sds;
    private User $secretary;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->secretary = User::factory()->create();
        $this->secretary->assignRole('secretary');

        $this->originator = User::factory()->create(['division_id' => $this->division->id]);
        $this->originator->assignRole('originator');
    }

    private function approvedDocument(): Document
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Cleanly approved memo',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Approved,
        ]);

        // A normal, clean approval — no SDS remarks at all — confirming this
        // action doesn't depend on review history the way returnToOwner() does.
        DocumentReview::create([
            'document_id' => $document->id,
            'reviewer_id' => $this->sds->id,
            'role_at_review' => 'sds',
            'action' => ReviewAction::ApprovedForwarded,
            'remarks' => null,
        ]);

        return $document;
    }

    public function test_secretary_can_return_a_cleanly_approved_document_for_correction(): void
    {
        Notification::fake();

        $document = $this->approvedDocument();

        $this->assertTrue($this->secretary->can('returnForCorrection', $document));

        $this->actingAs($this->secretary)
            ->post(route('documents.return-for-correction', $document), ['remarks' => 'Missing page 3.'])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::ReturnedForRevision, $document->status);

        $latestReview = DocumentReview::where('document_id', $document->id)->latest('id')->first();
        $this->assertSame('secretary', $latestReview->role_at_review);
        $this->assertSame(ReviewAction::ReturnedForRevision, $latestReview->action);
        $this->assertSame('Missing page 3.', $latestReview->remarks);
        $this->assertSame($this->secretary->id, $latestReview->reviewer_id);

        Notification::assertSentTo($this->originator, \App\Notifications\DocumentEvent::class);
    }

    public function test_remarks_are_required(): void
    {
        $document = $this->approvedDocument();

        $this->actingAs($this->secretary)
            ->post(route('documents.return-for-correction', $document), [])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_any_secretary_may_use_this_not_just_the_rds_own(): void
    {
        // Unlike returnToOwner(), this isn't scoped to isOsdsSecretary().
        $outsideSecretary = User::factory()->create(['division_id' => $this->division->id]);
        $outsideSecretary->assignRole('secretary');

        $document = $this->approvedDocument();

        $this->assertTrue($outsideSecretary->can('returnForCorrection', $document));
    }

    public function test_only_secretary_role_can_return_for_correction(): void
    {
        $document = $this->approvedDocument();

        foreach (['sds', 'asds'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertFalse($user->can('returnForCorrection', $document));

            $this->actingAs($user)
                ->post(route('documents.return-for-correction', $document), ['remarks' => 'Missing page 3.'])
                ->assertForbidden();
        }
    }

    public function test_secretary_cannot_return_for_correction_once_signed(): void
    {
        $document = $this->approvedDocument();
        $document->update(['status' => DocumentStatus::Signed]);

        $this->assertFalse($this->secretary->can('returnForCorrection', $document));

        $this->actingAs($this->secretary)
            ->post(route('documents.return-for-correction', $document), ['remarks' => 'Missing page 3.'])
            ->assertForbidden();
    }

    public function test_both_return_actions_can_be_available_on_the_same_document(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Legacy approved memo with remarks',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/b.pdf', 'file_hash' => hash('sha256', 'b'),
            'status' => DocumentStatus::Approved,
        ]);
        DocumentReview::create([
            'document_id' => $document->id,
            'reviewer_id' => $this->sds->id,
            'role_at_review' => 'sds',
            'action' => ReviewAction::ApprovedForwarded,
            'remarks' => 'Please double-check the budget figures.',
        ]);

        $document->load('reviews');
        $this->assertTrue($this->secretary->can('returnToOwner', $document));
        $this->assertTrue($this->secretary->can('returnForCorrection', $document));
    }
}
