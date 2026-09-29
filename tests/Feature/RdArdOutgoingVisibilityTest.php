<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RD/ARD should only see outgoing documents in general oversight views
 * (the "Outgoing" tab, and the Dashboard's stats/recent list) once a
 * document has cleared unit head / division chief review — documents still
 * sitting with those earlier reviewers aren't yet ARD's/RD's concern.
 */
class RdArdOutgoingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $rd;
    private User $ard;
    private User $admin;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'rd', 'ard'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->ard = User::factory()->create();
        $this->ard->assignRole('ard');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->originator = User::factory()->create();
        $this->originator->assignRole('originator');
    }

    private function outgoingAt(DocumentStatus $status, string $title): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => $title, 'uploaded_by' => $this->originator->id,
            'file_path' => 'outgoing/' . $title . '.pdf', 'file_hash' => hash('sha256', $title),
            'status' => $status,
        ]);
    }

    public static function earlyStageProvider(): array
    {
        return [
            'draft'                         => [DocumentStatus::Draft],
            'submitted'                     => [DocumentStatus::Submitted],
            'pending unit head review'      => [DocumentStatus::PendingUnitHeadReview],
            'pending division chief review' => [DocumentStatus::PendingDivisionChiefReview],
        ];
    }

    #[DataProvider('earlyStageProvider')]
    public function test_rd_does_not_see_early_stage_documents_in_outgoing_tab(DocumentStatus $status): void
    {
        $document = $this->outgoingAt($status, 'Early stage');

        $this->actingAs($this->rd)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    #[DataProvider('earlyStageProvider')]
    public function test_ard_does_not_see_early_stage_documents_in_outgoing_tab(DocumentStatus $status): void
    {
        $document = $this->outgoingAt($status, 'Early stage');

        $this->actingAs($this->ard)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_rd_sees_documents_once_they_reach_ard_or_rd_review(): void
    {
        $pendingArd = $this->outgoingAt(DocumentStatus::PendingArdReview, 'Cleared to ARD');
        $pendingRd  = $this->outgoingAt(DocumentStatus::PendingRdReview, 'Cleared to RD');

        $this->actingAs($this->rd)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($pendingArd->id)
                    && collect($docs)->pluck('id')->contains($pendingRd->id),
            ));
    }

    public function test_admin_still_sees_early_stage_documents_in_outgoing_tab(): void
    {
        $document = $this->outgoingAt(DocumentStatus::Submitted, 'Early stage');

        $this->actingAs($this->admin)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_rd_dashboard_stats_and_recent_documents_exclude_early_stage_documents(): void
    {
        $early = $this->outgoingAt(DocumentStatus::PendingUnitHeadReview, 'Early stage');
        $ready = $this->outgoingAt(DocumentStatus::PendingRdReview, 'Ready for RD');

        $this->actingAs($this->rd)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.totalOutgoing', 1)
                ->where(
                    'recentDocs',
                    fn ($docs) => collect($docs)->pluck('id')->contains($ready->id)
                        && ! collect($docs)->pluck('id')->contains($early->id),
                ));
    }
}
