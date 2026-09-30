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
 * A unit head whose division is flagged allow_rd_direct_routing is routed to
 * directly by the RD the same way a division chief is — so they need the
 * same "may forward this to my own staff" ability a division chief gets,
 * once they've acknowledged it. ICTU is used throughout this file as an
 * *example* of such a division (it happens to sit under ORD, but the flag
 * — not its parent's code — is what grants the ability; see
 * DynamicDirectRoutableUnitHeadTest for a non-ORD example). A unit head
 * whose division isn't flagged (e.g. Procurement Unit under Administrative
 * Division) is never offered as an RD routing target and doesn't get this
 * ability — for them a routing round always treats them as staff, a
 * terminal recipient.
 */
class OrdUnitHeadRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;

    private Division $ictu;

    private Division $ad;

    private Division $recordsSection;

    private User $rd;

    private User $ictuHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->ictu = Division::create(['name' => 'Information and Communications Technology Unit', 'code' => 'ICTU', 'parent_id' => $this->ord->id, 'allow_rd_direct_routing' => true]);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->recordsSection = Division::create(['name' => 'Records Section', 'code' => 'RS', 'parent_id' => $this->ad->id]);

        $this->rd = User::factory()->create(['division_id' => $this->ord->id]);
        $this->rd->assignRole('rd');

        $this->ictuHead = User::factory()->create(['division_id' => $this->ictu->id]);
        $this->ictuHead->assignRole('unit_head');
    }

    private function routeDocumentToIctuHead(): Document
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
            'routing_id' => $routing->id, 'user_id' => $this->ictuHead->id,
            'action' => 'appropriate_action', 'status' => 'pending',
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        return $document;
    }

    public function test_rd_routing_form_offers_ord_unit_heads_and_chiefs_but_not_ad_sub_unit_heads(): void
    {
        $recordsHead = User::factory()->create(['division_id' => $this->recordsSection->id]);
        $recordsHead->assignRole('unit_head');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Fresh memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/fresh.pdf', 'file_hash' => hash('sha256', 'fresh'),
            'status' => DocumentStatus::Received,
        ]);

        $this->actingAs($this->rd)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => collect($users)->pluck('id')->contains($this->ictuHead->id)
                    && ! collect($users)->pluck('id')->contains($recordsHead->id)));
    }

    public function test_ictu_head_acknowledging_does_not_auto_complete_and_route_button_appears(): void
    {
        $document = $this->routeDocumentToIctuHead();
        $recipient = $document->routings->first()->recipients->first();

        $this->actingAs($this->ictuHead)
            ->patch(route('routing-recipients.acknowledge', $recipient), [
                'response_remarks' => 'Reviewing.',
            ])
            ->assertRedirect();

        $recipient->refresh();
        $document->refresh();

        $this->assertEquals('acknowledged', $recipient->status->value);
        $this->assertNotSame(DocumentStatus::Completed, $document->status);
        $this->assertTrue($this->ictuHead->can('route', $document));

        $this->actingAs($this->ictuHead)
            ->get(route('documents.routings.create', $document))
            ->assertOk();
    }

    public function test_ictu_head_can_route_the_document_to_their_own_staff(): void
    {
        $document = $this->routeDocumentToIctuHead();
        $recipient = $document->routings->first()->recipients->first();
        $this->actingAs($this->ictuHead)->patch(route('routing-recipients.acknowledge', $recipient));

        $staff = User::factory()->create(['division_id' => $this->ictu->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->ictuHead)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$staff->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->refresh()->load('routings.recipients');
        $this->assertCount(2, $document->routings);
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $staff->id));
    }

    public function test_ictu_head_cannot_route_to_a_user_outside_their_division(): void
    {
        $document = $this->routeDocumentToIctuHead();
        $recipient = $document->routings->first()->recipients->first();
        $this->actingAs($this->ictuHead)->patch(route('routing-recipients.acknowledge', $recipient));

        $outsider = User::factory()->create(['division_id' => $this->ad->id]);
        $outsider->assignRole('originator');

        $this->actingAs($this->ictuHead)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$outsider->id],
            ])
            ->assertSessionHasErrors('user_ids');
    }

    public function test_ictu_head_cannot_route_before_acknowledging(): void
    {
        $document = $this->routeDocumentToIctuHead();

        $staff = User::factory()->create(['division_id' => $this->ictu->id]);
        $staff->assignRole('originator');

        $this->assertFalse($this->ictuHead->can('route', $document));

        $this->actingAs($this->ictuHead)
            ->get(route('documents.routings.create', $document))
            ->assertForbidden();
    }

    public function test_ictu_head_cannot_route_the_same_document_twice(): void
    {
        $document = $this->routeDocumentToIctuHead();
        $recipient = $document->routings->first()->recipients->first();
        $this->actingAs($this->ictuHead)->patch(route('routing-recipients.acknowledge', $recipient));

        $staff = User::factory()->create(['division_id' => $this->ictu->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->ictuHead)->post(route('documents.routings.store', $document), [
            'action' => 'appropriate_action',
            'user_ids' => [$staff->id],
        ]);

        $document->refresh();
        $this->assertFalse($this->ictuHead->can('route', $document));

        $this->actingAs($this->ictuHead)
            ->get(route('documents.routings.create', $document))
            ->assertForbidden();
    }

    public function test_document_reappears_in_ictu_heads_for_action_after_acknowledging(): void
    {
        $document = $this->routeDocumentToIctuHead();
        $recipient = $document->routings->first()->recipients->first();

        $this->actingAs($this->ictuHead)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));

        $this->actingAs($this->ictuHead)->patch(route('routing-recipients.acknowledge', $recipient));

        $this->actingAs($this->ictuHead)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_a_unit_head_outside_ord_does_not_get_the_deferred_routing_ability(): void
    {
        $recordsHead = User::factory()->create(['division_id' => $this->recordsSection->id]);
        $recordsHead->assignRole('unit_head');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'AD memo', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/ad.pdf', 'file_hash' => hash('sha256', 'ad'),
            'status' => DocumentStatus::Received,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        $recipient = DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $recordsHead->id,
            'action' => 'appropriate_action', 'status' => 'pending',
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        // Acknowledging immediately completes it (single-step, same as any
        // ordinary staff recipient) — no deferred "route to my own staff" option.
        $this->actingAs($recordsHead)
            ->patch(route('routing-recipients.acknowledge', $recipient), ['response_remarks' => 'Noted.'])
            ->assertRedirect();

        $recipient->refresh();
        $this->assertEquals('completed', $recipient->status->value);
        $this->assertFalse($recordsHead->can('route', $document));
    }
}
