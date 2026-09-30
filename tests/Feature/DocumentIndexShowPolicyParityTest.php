<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRouting;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DocumentPolicy::review()/route() were rewritten from query-builder calls
 * (Division::where(...)->value(...), $document->routings()->where(...)->exists())
 * to Collection methods on eager-loaded relations, to fix an N+1 in
 * DocumentController::index()'s ->through() closure. This guards against
 * that rewrite silently diverging from the original logic by asserting
 * documents.index's per-row can_review/can_route (the bulk, eager-loaded
 * path) always agrees with documents.show's canReview/canRoute (the
 * single-document path) for the same document+user pair.
 */
class DocumentIndexShowPolicyParityTest extends TestCase
{
    use RefreshDatabase;

    private User $sds;

    private User $asds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->asds = User::factory()->create();
        $this->asds->assignRole('asds');
    }

    private function assertParity(Document $document, User $actor, string $tab): void
    {
        $indexPage = null;
        $this->actingAs($actor)
            ->get(route('documents.index', ['tab' => $tab]))
            ->assertOk()
            ->assertInertia(function ($page) use (&$indexPage) {
                $indexPage = $page->toArray();
            });

        $row = collect($indexPage['props']['documents']['data'])->firstWhere('id', $document->id);

        $this->assertNotNull($row, "Document {$document->id} was not found on the '{$tab}' tab for this user — fixture doesn't match the scope being exercised.");

        $showPage = null;
        $this->actingAs($actor)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(function ($page) use (&$showPage) {
                $showPage = $page->toArray();
            });

        $this->assertSame(
            $row['can_review'], $showPage['props']['canReview'],
            'documents.index can_review disagrees with documents.show canReview for the same document+user.',
        );
        $this->assertSame(
            $row['can_route'], $showPage['props']['canRoute'],
            'documents.index can_route disagrees with documents.show canRoute for the same document+user.',
        );
    }

    public function test_review_parity_for_a_sub_unit_document_reviewed_by_the_parent_divisions_chief(): void
    {
        $ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $pu = Division::create(['name' => 'Procurement Unit', 'code' => null, 'parent_id' => $ad->id]);

        $chief = User::factory()->create(['division_id' => $ad->id]);
        $chief->assignRole('division_chief');

        $uploader = User::factory()->create(['division_id' => $pu->id]);
        $uploader->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Sub-unit memo', 'uploaded_by' => $uploader->id,
            'division_id' => $pu->id, 'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::PendingDivisionChiefReview,
        ]);

        $this->assertParity($document, $chief, 'outgoing');
    }

    public function test_route_parity_for_sds_when_already_routed_via_is_sds_routing(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Routed memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/a.pdf', 'file_hash' => hash('sha256', 'a'),
            'status' => DocumentStatus::Routed,
        ]);

        DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->sds->id,
            'is_rush' => false, 'routed_at' => now(), 'is_sds_routing' => true,
        ]);

        $this->assertParity($document, $this->sds, 'incoming');
    }

    public function test_route_parity_for_asds_on_a_fresh_document(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Fresh memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/b.pdf', 'file_hash' => hash('sha256', 'b'),
            'status' => DocumentStatus::Received,
        ]);

        $this->assertParity($document, $this->asds, 'incoming');
    }

    public function test_route_parity_for_a_division_chief_who_has_acknowledged_their_assignment(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $chief = User::factory()->create(['division_id' => $division->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Acknowledged memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/c.pdf', 'file_hash' => hash('sha256', 'c'),
            'status' => DocumentStatus::Routed,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->sds->id,
            'is_rush' => false, 'routed_at' => now(), 'is_sds_routing' => true,
        ]);

        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $chief->id,
            'action' => 'appropriate_action', 'status' => 'acknowledged',
            'acknowledged_at' => now(), 'acknowledged_by' => $chief->id,
        ]);

        $this->assertParity($document, $chief, 'incoming');
    }
}
