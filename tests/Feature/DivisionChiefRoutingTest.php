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

class DivisionChiefRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private Division $recordsSection;
    private User $rd;
    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->recordsSection = Division::create(['name' => 'Records Section', 'code' => 'RS', 'parent_id' => $this->ad->id]);

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->chief = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chief->assignRole('division_chief');
    }

    private function routeDocumentToChief(): Document
    {
        $document = Document::create([
            'type'      => DocumentType::Incoming,
            'title'     => 'Test memo',
            'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/memo.pdf',
            'file_hash' => hash('sha256', 'memo'),
            'status'    => DocumentStatus::Received,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id,
            'routed_by'   => $this->rd->id,
            'is_rush'     => false,
            'routed_at'   => now(),
        ]);

        DocumentRoutingRecipient::create([
            'routing_id'      => $routing->id,
            'user_id'         => $this->chief->id,
            'action'          => 'appropriate_action',
            'status'          => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $this->chief->id,
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        return $document;
    }

    public function test_chief_can_route_a_document_routed_to_them_to_their_own_staff(): void
    {
        $document = $this->routeDocumentToChief();

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->assertTrue($this->chief->can('route', $document));

        $this->actingAs($this->chief)
            ->get(route('documents.routings.create', $document))
            ->assertOk();

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$staff->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->load('routings.recipients');
        $this->assertCount(2, $document->routings);
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $staff->id));
    }

    public function test_chief_can_route_to_staff_in_a_child_division(): void
    {
        $document = $this->routeDocumentToChief();

        $clerk = User::factory()->create(['division_id' => $this->recordsSection->id]);
        $clerk->assignRole('originator');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$clerk->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->load('routings.recipients');
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $clerk->id));
    }

    public function test_chief_cannot_route_a_document_never_routed_to_them(): void
    {
        $document = Document::create([
            'type'        => DocumentType::Incoming,
            'title'       => 'Untouched memo',
            'uploaded_by' => $this->rd->id,
            'file_path'   => 'incoming/2026/07/untouched.pdf',
            'file_hash'   => hash('sha256', 'untouched'),
            'status'      => DocumentStatus::Received,
        ]);

        $this->assertFalse($this->chief->can('route', $document));

        $this->actingAs($this->chief)
            ->get(route('documents.routings.create', $document))
            ->assertForbidden();

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->rd->id],
            ])
            ->assertForbidden();
    }

    public function test_chief_cannot_route_to_a_user_outside_their_division_or_children(): void
    {
        $document = $this->routeDocumentToChief();

        $otherDivision = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $outsider = User::factory()->create(['division_id' => $otherDivision->id]);
        $outsider->assignRole('originator');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$outsider->id],
            ])
            ->assertSessionHasErrors('user_ids');

        $document->load('routings.recipients');
        $this->assertCount(1, $document->routings);
    }

    public function test_chief_cannot_route_to_a_peer_chief_or_the_rd(): void
    {
        $document = $this->routeDocumentToChief();

        $peerDivision = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $peerChief = User::factory()->create(['division_id' => $peerDivision->id]);
        $peerChief->assignRole('division_chief');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$peerChief->id],
            ])
            ->assertSessionHasErrors('user_ids');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->rd->id],
            ])
            ->assertSessionHasErrors('user_ids');
    }

    public function test_chief_with_no_division_cannot_route(): void
    {
        $document = $this->routeDocumentToChief();

        $chiefWithoutDivision = User::factory()->create(['division_id' => null]);
        $chiefWithoutDivision->assignRole('division_chief');

        $this->assertFalse($chiefWithoutDivision->can('route', $document));
    }

    public function test_chief_cannot_route_before_acknowledging(): void
    {
        $document = Document::create([
            'type'        => DocumentType::Incoming,
            'title'       => 'Unacknowledged memo',
            'uploaded_by' => $this->rd->id,
            'file_path'   => 'incoming/2026/07/unacked.pdf',
            'file_hash'   => hash('sha256', 'unacked'),
            'status'      => DocumentStatus::Routed,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id,
            'routed_by'   => $this->rd->id,
            'is_rush'     => false,
            'routed_at'   => now(),
        ]);

        $recipient = DocumentRoutingRecipient::create([
            'routing_id' => $routing->id,
            'user_id'    => $this->chief->id,
            'action'     => 'appropriate_action',
            'status'     => 'pending',
        ]);

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->assertFalse($this->chief->can('route', $document));

        $this->actingAs($this->chief)
            ->get(route('documents.routings.create', $document))
            ->assertForbidden();

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$staff->id],
            ])
            ->assertForbidden();

        // Acknowledging first unlocks it
        $recipient->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $this->chief->id]);
        $this->assertTrue($this->chief->fresh()->can('route', $document->fresh()));
    }

    public function test_chief_cannot_route_the_same_document_twice(): void
    {
        $document = $this->routeDocumentToChief();

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$staff->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->refresh();
        $this->assertFalse($this->chief->can('route', $document));

        $anotherStaff = User::factory()->create(['division_id' => $this->ad->id]);
        $anotherStaff->assignRole('originator');

        $this->actingAs($this->chief)
            ->get(route('documents.routings.create', $document))
            ->assertForbidden();

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$anotherStaff->id],
            ])
            ->assertForbidden();
    }

    public function test_document_drops_out_of_chiefs_for_action_once_they_route_it_onward(): void
    {
        $document = $this->routeDocumentToChief();

        $this->actingAs($this->chief)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->chief)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$staff->id],
        ]);

        $this->actingAs($this->chief)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_chief_acknowledging_does_not_auto_complete_and_route_button_still_appears(): void
    {
        $document = Document::create([
            'type'        => DocumentType::Incoming,
            'title'       => 'Fresh memo',
            'uploaded_by' => $this->rd->id,
            'file_path'   => 'incoming/2026/07/fresh.pdf',
            'file_hash'   => hash('sha256', 'fresh'),
            'status'      => DocumentStatus::Received,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id,
            'routed_by'   => $this->rd->id,
            'is_rush'     => false,
            'routed_at'   => now(),
        ]);

        $recipient = DocumentRoutingRecipient::create([
            'routing_id' => $routing->id,
            'user_id'    => $this->chief->id,
            'action'     => 'appropriate_action',
            'status'     => 'pending',
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        $this->actingAs($this->chief)
            ->patch(route('routing-recipients.acknowledge', $recipient), [
                'response_remarks' => 'Reviewing.',
            ])
            ->assertRedirect();

        $recipient->refresh();
        $document->refresh();

        // Acknowledged, not completed — the document must not have been
        // auto-promoted to Completed, or the chief would lose the ability
        // to route it to their own staff.
        $this->assertEquals('acknowledged', $recipient->status->value);
        $this->assertNotSame(DocumentStatus::Completed, $document->status);
        $this->assertTrue($this->chief->can('route', $document));

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$staff->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        // Routing onward closes out the chief's own assignment automatically.
        $recipient->refresh();
        $this->assertEquals('completed', $recipient->status->value);
        $this->assertEquals($this->chief->id, $recipient->completed_by);
    }

    public function test_chief_can_complete_an_acknowledged_assignment_without_routing(): void
    {
        $document = $this->routeDocumentToChief();
        $recipient = $document->routings->first()->recipients->first();

        $this->assertEquals('acknowledged', $recipient->status->value);

        $this->actingAs($this->chief)
            ->patch(route('routing-recipients.complete', $recipient), [
                'response_remarks' => 'Handled personally.',
            ])
            ->assertRedirect();

        $recipient->refresh();
        $document->refresh();
        $this->assertEquals('completed', $recipient->status->value);
        $this->assertSame(DocumentStatus::Completed, $document->status);
    }

    public function test_create_page_lists_only_chiefs_own_and_child_division_staff(): void
    {
        $document = $this->routeDocumentToChief();

        $ownStaff = User::factory()->create(['division_id' => $this->ad->id, 'name' => 'Own Staff']);
        $ownStaff->assignRole('originator');

        $childStaff = User::factory()->create(['division_id' => $this->recordsSection->id, 'name' => 'Child Staff']);
        $childStaff->assignRole('unit_head');

        $otherDivision = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $outsider = User::factory()->create(['division_id' => $otherDivision->id, 'name' => 'Outsider']);
        $outsider->assignRole('originator');

        $peerChief = User::factory()->create(['division_id' => $otherDivision->id, 'name' => 'Peer Chief']);
        $peerChief->assignRole('division_chief');

        $response = $this->actingAs($this->chief)->get(route('documents.routings.create', $document));

        $response->assertInertia(fn ($page) => $page
            ->component('Documents/Route')
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($ownStaff->id)
                && collect($users)->pluck('id')->contains($childStaff->id)
                && ! collect($users)->pluck('id')->contains($outsider->id)
                && ! collect($users)->pluck('id')->contains($peerChief->id)));
    }
}
