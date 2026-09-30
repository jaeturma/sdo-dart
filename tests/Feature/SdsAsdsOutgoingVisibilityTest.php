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
 * SDS/ASDS should only see outgoing documents in general oversight views
 * (the "Outgoing" tab, and the Dashboard's stats/recent list) once a
 * document has cleared unit head / division chief review — documents still
 * sitting with those earlier reviewers aren't yet ASDS's/SDS's concern.
 */
class SdsAsdsOutgoingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $sds;
    private User $asds;
    private User $admin;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'sds', 'asds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->asds = User::factory()->create();
        $this->asds->assignRole('asds');

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
    public function test_sds_does_not_see_early_stage_documents_in_outgoing_tab(DocumentStatus $status): void
    {
        $document = $this->outgoingAt($status, 'Early stage');

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    #[DataProvider('earlyStageProvider')]
    public function test_asds_does_not_see_early_stage_documents_in_outgoing_tab(DocumentStatus $status): void
    {
        $document = $this->outgoingAt($status, 'Early stage');

        $this->actingAs($this->asds)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_sds_sees_documents_once_they_reach_asds_or_sds_review(): void
    {
        $pendingAsds = $this->outgoingAt(DocumentStatus::PendingAsdsReview, 'Cleared to ASDS');
        $pendingSds  = $this->outgoingAt(DocumentStatus::PendingSdsReview, 'Cleared to SDS');

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($pendingAsds->id)
                    && collect($docs)->pluck('id')->contains($pendingSds->id),
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

    public function test_sds_dashboard_stats_and_recent_documents_exclude_early_stage_documents(): void
    {
        $early = $this->outgoingAt(DocumentStatus::PendingUnitHeadReview, 'Early stage');
        $ready = $this->outgoingAt(DocumentStatus::PendingSdsReview, 'Ready for SDS');

        $this->actingAs($this->sds)
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
