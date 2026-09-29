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

class DivisionChiefCancelRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
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

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->chief = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chief->assignRole('division_chief');
    }

    private function routeDocumentToChief(): Document
    {
        $document = Document::create([
            'type'        => DocumentType::Incoming,
            'title'       => 'Test memo',
            'uploaded_by' => $this->rd->id,
            'file_path'   => 'incoming/2026/07/memo.pdf',
            'file_hash'   => hash('sha256', 'memo'),
            'status'      => DocumentStatus::Received,
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

    public function test_chief_can_cancel_an_unacknowledged_routing_and_reroute(): void
    {
        $document = $this->routeDocumentToChief();

        $wrongStaff = User::factory()->create(['division_id' => $this->ad->id]);
        $wrongStaff->assignRole('originator');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$wrongStaff->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->refresh()->load('routings.recipients');
        $mistakenRouting = $document->routings->last();

        $this->assertTrue($this->chief->can('cancel', $mistakenRouting));
        $this->assertFalse($this->chief->can('route', $document));

        $this->actingAs($this->chief)
            ->delete(route('documents.routings.cancel', [$document, $mistakenRouting]))
            ->assertRedirect(route('documents.show', $document));

        $mistakenRouting->refresh();
        $this->assertNotNull($mistakenRouting->cancelled_at);
        $this->assertEquals($this->chief->id, $mistakenRouting->cancelled_by);
        $this->assertTrue($mistakenRouting->recipients->every(fn ($r) => $r->status->value === 'cancelled'));

        // The chief's own assignment is restored so they can route again.
        $ownRecipient = DocumentRoutingRecipient::where('user_id', $this->chief->id)
            ->where('routing_id', $document->routings->first()->id)
            ->first();
        $ownRecipient->refresh();
        $this->assertEquals('acknowledged', $ownRecipient->status->value);
        $this->assertNull($ownRecipient->completed_at);

        $document->refresh();
        $this->assertTrue($this->chief->can('route', $document));

        $correctStaff = User::factory()->create(['division_id' => $this->ad->id]);
        $correctStaff->assignRole('originator');

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$correctStaff->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_action']));

        $document->refresh()->load('routings.recipients');
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $correctStaff->id));
    }

    public function test_chief_cannot_cancel_once_staff_has_acknowledged(): void
    {
        $document = $this->routeDocumentToChief();

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->chief)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$staff->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->last();
        $recipient = $routing->recipients->first();
        $recipient->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $staff->id]);

        $this->assertFalse($this->chief->can('cancel', $routing->fresh()));

        $this->actingAs($this->chief)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertForbidden();
    }

    public function test_chief_cannot_cancel_another_chiefs_routing(): void
    {
        $document = $this->routeDocumentToChief();

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->chief)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$staff->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->last();

        $otherChief = User::factory()->create(['division_id' => $this->ad->id]);
        $otherChief->assignRole('division_chief');

        $this->assertFalse($otherChief->can('cancel', $routing));

        $this->actingAs($otherChief)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertForbidden();
    }

    public function test_cannot_cancel_the_same_routing_twice(): void
    {
        $document = $this->routeDocumentToChief();

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->chief)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$staff->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->last();

        $this->actingAs($this->chief)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertRedirect(route('documents.show', $document));

        $this->actingAs($this->chief)
            ->delete(route('documents.routings.cancel', [$document, $routing->fresh()]))
            ->assertForbidden();
    }

    public function test_document_reappears_in_chiefs_for_action_after_cancel(): void
    {
        $document = $this->routeDocumentToChief();

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

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->last();

        $this->actingAs($this->chief)->delete(route('documents.routings.cancel', [$document, $routing]));

        $this->actingAs($this->chief)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_non_chief_cannot_cancel_a_routing(): void
    {
        $document = $this->routeDocumentToChief();

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');

        $this->actingAs($this->chief)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$staff->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->last();

        $this->assertFalse($this->rd->can('cancel', $routing));

        $this->actingAs($this->rd)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertForbidden();
    }
}
