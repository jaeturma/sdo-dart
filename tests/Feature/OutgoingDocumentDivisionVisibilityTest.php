<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mirrors IncomingDocumentDivisionVisibilityTest — the exact same
 * "anyone in my division sees documents uploaded by anyone else in my
 * division" rule already shipped for incoming documents, extended to
 * outgoing so staff can monitor a colleague's document through the
 * approval chain and download it once signed. See
 * DocumentScopesTrait::outgoingScope()/visibleScope() and
 * DocumentPolicy::view().
 */
class OutgoingDocumentDivisionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private Division $procurement;
    private Division $unrelated;
    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->procurement = Division::create(['name' => 'Procurement Unit', 'parent_id' => $this->ad->id]);
        $this->unrelated = Division::create(['name' => 'Finance Division', 'code' => 'FD']);

        $this->uploader = User::factory()->create(['division_id' => $this->procurement->id]);
        $this->uploader->assignRole('originator');
    }

    private function makeOutgoingDocument(DocumentStatus $status = DocumentStatus::PendingDivisionChiefReview): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Test memo',
            'uploaded_by' => $this->uploader->id, 'division_id' => $this->procurement->id,
            'file_path' => 'outgoing/2026/07/test.pdf', 'file_hash' => hash('sha256', 'test'),
            'status' => $status,
        ]);
    }

    public function test_a_colleague_in_the_same_division_sees_a_document_they_did_not_upload(): void
    {
        $document = $this->makeOutgoingDocument();

        $colleague = User::factory()->create(['division_id' => $this->procurement->id]);
        $colleague->assignRole('originator');

        $this->assertTrue($colleague->can('view', $document));
        $this->assertContains($document->id, $this->outgoingScopeIds($colleague));
        $this->assertContains($document->id, $this->visibleScopeIds($colleague));

        $this->actingAs($colleague)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_a_user_in_the_parent_division_sees_a_document_uploaded_by_a_child_division_member(): void
    {
        $document = $this->makeOutgoingDocument();

        $parentDivisionUser = User::factory()->create(['division_id' => $this->ad->id]);
        $parentDivisionUser->assignRole('originator');

        $this->assertTrue($parentDivisionUser->can('view', $document));
        $this->assertContains($document->id, $this->outgoingScopeIds($parentDivisionUser));

        $this->actingAs($parentDivisionUser)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_a_user_in_an_unrelated_division_cannot_see_the_document(): void
    {
        $document = $this->makeOutgoingDocument();

        $outsider = User::factory()->create(['division_id' => $this->unrelated->id]);
        $outsider->assignRole('originator');

        $this->assertFalse($outsider->can('view', $document));
        $this->assertNotContains($document->id, $this->outgoingScopeIds($outsider));
        $this->assertNotContains($document->id, $this->visibleScopeIds($outsider));

        $this->actingAs($outsider)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_a_user_with_no_division_only_sees_their_own_uploads(): void
    {
        $document = $this->makeOutgoingDocument();

        $divisionless = User::factory()->create(['division_id' => null]);
        $divisionless->assignRole('originator');

        $this->assertFalse($divisionless->can('view', $document));
        $this->assertNotContains($document->id, $this->outgoingScopeIds($divisionless));
    }

    /**
     * The crux of "monitor the status" — a colleague can see the document
     * at any stage of the review chain, not just once it's finished.
     */
    public function test_visibility_is_not_limited_by_document_status(): void
    {
        $colleague = User::factory()->create(['division_id' => $this->procurement->id]);
        $colleague->assignRole('originator');

        foreach ([
            DocumentStatus::Submitted,
            DocumentStatus::PendingDivisionChiefReview,
            DocumentStatus::PendingArdReview,
            DocumentStatus::Approved,
            DocumentStatus::Signed,
            DocumentStatus::Released,
        ] as $status) {
            $document = $this->makeOutgoingDocument($status);
            $this->assertTrue($colleague->can('view', $document), "should be visible at status {$status->value}");
        }
    }

    /**
     * Read+download only — a colleague gains no new ability to act on the
     * document (no review, route, sign, or delete rights).
     */
    public function test_a_colleague_can_download_the_signed_pdf_but_gains_no_new_action_abilities(): void
    {
        Storage::fake('documents');

        $document = $this->makeOutgoingDocument(DocumentStatus::Signed);
        Storage::disk('documents')->put('outgoing/signed.pdf', '%PDF-1.4 fake signed content');

        DocumentSignature::create([
            'document_id' => $document->id, 'signed_by' => $this->uploader->id,
            'signed_file_path' => 'outgoing/signed.pdf', 'signed_file_hash' => hash('sha256', 'signed'),
            'certificate_subject' => 'CN=Test', 'certificate_serial' => '123',
            'signature_page' => 1, 'sig_x_percent' => 10, 'sig_y_percent' => 10, 'sig_width_percent' => 20,
            'signed_at' => now(),
        ]);

        $colleague = User::factory()->create(['division_id' => $this->procurement->id]);
        $colleague->assignRole('originator');

        $this->actingAs($colleague)
            ->get(route('documents.signed-pdf', $document))
            ->assertOk();

        $this->actingAs($colleague)
            ->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page
                ->where('canReview', false)
                ->where('canRoute', false)
                ->where('canSign', false)
                ->where('canDelete', false));
    }

    /**
     * Companion to the division-wide visibility above: since the Outgoing
     * tab now shows the whole division's documents, "mine=1" lets the user
     * narrow back down to just what they personally uploaded. Applied as an
     * extra AND on top of outgoingScope() (see DocumentController::index()),
     * so it can only ever narrow, never reveal something outgoingScope()
     * itself wouldn't already allow.
     */
    public function test_mine_filter_narrows_the_outgoing_tab_to_only_the_users_own_uploads(): void
    {
        $document = $this->makeOutgoingDocument();

        $colleague = User::factory()->create(['division_id' => $this->procurement->id]);
        $colleague->assignRole('originator');
        $colleagueDocument = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Colleague memo',
            'uploaded_by' => $colleague->id, 'division_id' => $this->procurement->id,
            'file_path' => 'outgoing/2026/07/colleague.pdf', 'file_hash' => hash('sha256', 'colleague'),
            'status' => DocumentStatus::Submitted,
        ]);

        // Without the filter, the colleague sees both (the division-wide rule).
        $this->actingAs($colleague)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id)
                    && collect($docs)->pluck('id')->contains($colleagueDocument->id),
            ));

        // With mine=1, only their own upload remains.
        $this->actingAs($colleague)
            ->get(route('documents.index', ['tab' => 'outgoing', 'mine' => '1']))
            ->assertInertia(fn ($page) => $page
                ->where('mine', true)
                ->where(
                    'documents.data',
                    fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id)
                        && collect($docs)->pluck('id')->contains($colleagueDocument->id),
                ));
    }

    /**
     * mine=1 is only meaningful on the Outgoing tab — passing it while
     * viewing Incoming must not narrow that tab's results.
     */
    public function test_mine_filter_has_no_effect_outside_the_outgoing_tab(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->procurement->id]);
        $secretary->assignRole('secretary');

        $colleagueIncoming = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Colleague incoming memo',
            'uploaded_by' => $secretary->id, 'division_id' => $this->procurement->id,
            'file_path' => 'incoming/2026/07/colleague.pdf', 'file_hash' => hash('sha256', 'colleague-in'),
            'status' => DocumentStatus::Received,
        ]);

        $viewer = User::factory()->create(['division_id' => $this->procurement->id]);
        $viewer->assignRole('originator');

        $this->actingAs($viewer)
            ->get(route('documents.index', ['tab' => 'incoming', 'mine' => '1']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($colleagueIncoming->id),
            ));
    }

    /** @return array<int> */
    private function outgoingScopeIds(User $user): array
    {
        return (new class {
            use \App\Traits\DocumentScopesTrait {
                outgoingScope as public;
            }
        })->outgoingScope(Document::query(), $user)->pluck('id')->all();
    }

    /** @return array<int> */
    private function visibleScopeIds(User $user): array
    {
        return (new class {
            use \App\Traits\DocumentScopesTrait {
                visibleScope as public;
            }
        })->visibleScope(Document::query(), $user)->pluck('id')->all();
    }
}
