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
 * Proves the "RD routes directly to a unit head" ability (see
 * OrdUnitHeadRoutingTest, which covers it via the ICTU example) is actually
 * driven by Division::allow_rd_direct_routing — an admin-configurable flag,
 * not a hardcoded ORD-parent check — using the RD's own real-world example:
 * NEAP, a unit under HRDD (a division with no relation to ORD at all).
 */
class DynamicDirectRoutableUnitHeadTest extends TestCase
{
    use RefreshDatabase;

    private Division $hrdd;

    private Division $neap;

    private User $rd;

    private User $hrddChief;

    private User $neapHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->hrdd = Division::create(['name' => 'Human Resource Development Division', 'code' => 'HRDD']);
        $this->neap = Division::create([
            'name' => 'NEAP',
            'parent_id' => $this->hrdd->id,
            'allow_rd_direct_routing' => true,
        ]);

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->hrddChief = User::factory()->create(['division_id' => $this->hrdd->id]);
        $this->hrddChief->assignRole('division_chief');

        $this->neapHead = User::factory()->create(['division_id' => $this->neap->id]);
        $this->neapHead->assignRole('unit_head');
    }

    private function routeDocumentToNeapHead(): Document
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Test memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/memo.pdf', 'file_hash' => hash('sha256', 'memo'),
            'status' => DocumentStatus::Received,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $this->neapHead->id,
            'action' => 'appropriate_action', 'status' => 'pending',
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        return $document;
    }

    public function test_rd_routing_form_offers_the_neap_head_when_the_flag_is_enabled(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Fresh memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/fresh.pdf', 'file_hash' => hash('sha256', 'fresh'),
            'status' => DocumentStatus::Received,
        ]);

        $this->actingAs($this->rd)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => collect($users)->pluck('id')->contains($this->neapHead->id)));
    }

    public function test_neap_head_acknowledging_does_not_auto_complete_and_route_button_appears(): void
    {
        $document = $this->routeDocumentToNeapHead();
        $recipient = $document->routings->first()->recipients->first();

        $this->actingAs($this->neapHead)
            ->patch(route('routing-recipients.acknowledge', $recipient), [
                'response_remarks' => 'Reviewing.',
            ])
            ->assertRedirect();

        $recipient->refresh();
        $document->refresh();

        $this->assertEquals('acknowledged', $recipient->status->value);
        $this->assertNotSame(DocumentStatus::Completed, $document->status);
        $this->assertTrue($this->neapHead->can('route', $document));
    }

    public function test_neap_head_can_route_the_document_to_their_own_staff(): void
    {
        $document = $this->routeDocumentToNeapHead();
        $recipient = $document->routings->first()->recipients->first();
        $this->actingAs($this->neapHead)->patch(route('routing-recipients.acknowledge', $recipient));

        $staff = User::factory()->create(['division_id' => $this->neap->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->neapHead)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$staff->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->refresh()->load('routings.recipients');
        $this->assertCount(2, $document->routings);
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $staff->id));
    }

    public function test_a_hrdd_sub_unit_without_the_flag_does_not_get_the_ability(): void
    {
        $unflagged = Division::create(['name' => 'Some Other HRDD Unit', 'parent_id' => $this->hrdd->id]);
        $unflaggedHead = User::factory()->create(['division_id' => $unflagged->id]);
        $unflaggedHead->assignRole('unit_head');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Unflagged memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/unflagged.pdf', 'file_hash' => hash('sha256', 'unflagged'),
            'status' => DocumentStatus::Received,
        ]);

        // Not offered as a direct RD routing candidate in the first place.
        $this->actingAs($this->rd)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => ! collect($users)->pluck('id')->contains($unflaggedHead->id)));

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $recipient = DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $unflaggedHead->id,
            'action' => 'appropriate_action', 'status' => 'pending',
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        // Acknowledging immediately completes it, same as any ordinary
        // staff recipient — no deferred "route to my own staff" option.
        $this->actingAs($unflaggedHead)
            ->patch(route('routing-recipients.acknowledge', $recipient), ['response_remarks' => 'Noted.'])
            ->assertRedirect();

        $recipient->refresh();
        $this->assertEquals('completed', $recipient->status->value);
        $this->assertFalse($unflaggedHead->can('route', $document));
    }

    public function test_hrdd_chief_can_also_route_to_the_neap_head(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Chief-routed memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/chief.pdf', 'file_hash' => hash('sha256', 'chief'),
            'status' => DocumentStatus::Received,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $this->hrddChief->id,
            'action' => 'appropriate_action', 'status' => 'acknowledged',
            'acknowledged_at' => now(), 'acknowledged_by' => $this->hrddChief->id,
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        // The chief's own routing candidate list includes the NEAP head
        // (Division::selfAndChildrenIds() — already generic, no changes
        // needed for this side of the feature).
        $this->actingAs($this->hrddChief)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => collect($users)->pluck('id')->contains($this->neapHead->id)));

        $this->actingAs($this->hrddChief)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$this->neapHead->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->refresh()->load('routings.recipients');
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $this->neapHead->id));
    }

    public function test_disabling_the_flag_removes_the_rds_direct_routing_ability(): void
    {
        $this->neap->update(['allow_rd_direct_routing' => false]);

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'After toggle-off memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/toggled-off.pdf', 'file_hash' => hash('sha256', 'toggled-off'),
            'status' => DocumentStatus::Received,
        ]);

        $this->actingAs($this->rd)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => ! collect($users)->pluck('id')->contains($this->neapHead->id)));
    }
}
