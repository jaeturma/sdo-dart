<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The document list payload carries per-row can_review/can_route flags so
 * the mobile quick-preview modal can offer the same Route/Review actions as
 * the full document page without a second round trip.
 */
class DocumentListRowActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $rd;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'rd'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->originator = User::factory()->create();
        $this->originator->assignRole('originator');
    }

    public function test_rd_row_flags_can_review_true_for_document_awaiting_rd_approval(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Needs RD approval',
            'uploaded_by' => $this->originator->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingRdReview,
        ]);

        $this->actingAs($this->rd)
            ->get(route('documents.index', ['tab' => 'for_approval']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->firstWhere('id', $document->id)['can_review'] === true
                    && collect($docs)->firstWhere('id', $document->id)['can_route'] === false,
            ));
    }

    public function test_rd_row_flags_can_route_true_for_document_awaiting_dispatch(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Needs routing',
            'uploaded_by' => $this->originator->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'b'), 'status' => DocumentStatus::Received,
        ]);

        $this->actingAs($this->rd)
            ->get(route('documents.index', ['tab' => 'for_routing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->firstWhere('id', $document->id)['can_route'] === true
                    && collect($docs)->firstWhere('id', $document->id)['can_review'] === false,
            ));
    }

    public function test_originator_row_flags_are_false_for_their_own_submitted_document(): void
    {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'My own memo',
            'uploaded_by' => $this->originator->id, 'file_path' => 'outgoing/c.pdf',
            'file_hash' => hash('sha256', 'c'), 'status' => DocumentStatus::Submitted,
        ]);

        $this->actingAs($this->originator)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->firstWhere('id', $document->id)['can_review'] === false
                    && collect($docs)->firstWhere('id', $document->id)['can_route'] === false,
            ));
    }
}
