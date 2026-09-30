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

class RdForActionSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $rd;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'rd'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->originator = User::factory()->create();
        $this->originator->assignRole('originator');
    }

    private function outgoingAwaitingRd(): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Needs RD approval',
            'uploaded_by' => $this->originator->id, 'file_path' => 'outgoing/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::PendingRdReview,
        ]);
    }

    private function incomingAwaitingRouting(): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Needs routing',
            'uploaded_by' => $this->originator->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'b'), 'status' => DocumentStatus::Received,
        ]);
    }

    public function test_for_approval_tab_shows_outgoing_documents_awaiting_rd_only(): void
    {
        $outgoing = $this->outgoingAwaitingRd();
        $incoming = $this->incomingAwaitingRouting();

        $this->actingAs($this->rd)
            ->get(route('documents.index', ['tab' => 'for_approval']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($outgoing->id)
                    && ! collect($docs)->pluck('id')->contains($incoming->id),
            ));
    }

    public function test_for_routing_tab_shows_incoming_documents_awaiting_dispatch_only(): void
    {
        $outgoing = $this->outgoingAwaitingRd();
        $incoming = $this->incomingAwaitingRouting();

        $this->actingAs($this->rd)
            ->get(route('documents.index', ['tab' => 'for_routing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($incoming->id)
                    && ! collect($docs)->pluck('id')->contains($outgoing->id),
            ));
    }

    public function test_for_approval_and_for_routing_counts_together_equal_for_action_count(): void
    {
        $this->outgoingAwaitingRd();
        $this->incomingAwaitingRouting();

        $this->actingAs($this->rd)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page
                ->where('isRd', true)
                ->where('forApprovalCount', 1)
                ->where('forRoutingCount', 1)
                ->where('forActionCount', 2));
    }

    public function test_non_rd_user_does_not_get_split_counts(): void
    {
        $this->actingAs($this->originator)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page
                ->where('isRd', false)
                ->where('forApprovalCount', null)
                ->where('forRoutingCount', null));
    }
}
