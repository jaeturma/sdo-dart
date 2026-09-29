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
 * The RD works through her "For Approval"/"For Routing" queues one document
 * at a time, so acting on a document should return her to that queue instead
 * of the document's own page — unlike other reviewers, who land on the
 * document itself after acting (e.g. to see it move to the next stage).
 */
class RdActionRedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $rd;
    private User $chief;
    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->chief = User::factory()->create(['division_id' => $this->division->id]);
        $this->chief->assignRole('division_chief');
    }

    public function test_rd_reviewing_an_outgoing_document_returns_to_the_for_approval_queue(): void
    {
        $originator = User::factory()->create(['division_id' => $this->division->id]);
        $originator->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Ready for RD',
            'uploaded_by' => $originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::PendingRdReview,
        ]);

        $this->actingAs($this->rd)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect(route('documents.index', ['tab' => 'for_approval']));

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_division_chief_reviewing_an_outgoing_document_still_lands_on_the_document(): void
    {
        $originator = User::factory()->create(['division_id' => $this->division->id]);
        $originator->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Ready for chief',
            'uploaded_by' => $originator->id, 'division_id' => $this->division->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->actingAs($this->chief)
            ->post(route('documents.reviews.store', $document), ['action' => 'approved_forwarded'])
            ->assertRedirect(route('documents.show', $document));
    }

    public function test_rd_routing_an_incoming_document_returns_to_the_for_routing_queue(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Inbound memo',
            'uploaded_by' => $this->rd->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::Received,
        ]);

        $this->actingAs($this->rd)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_routing']));
    }
}
