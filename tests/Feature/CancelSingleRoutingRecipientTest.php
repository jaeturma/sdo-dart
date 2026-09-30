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
 * A single routing round can carry more than one recipient (SDS selects
 * several chiefs' checkboxes at once in Documents/Route.vue). If she meant
 * to route to only one of them, DocumentRoutingController::cancel() withdraws
 * the WHOLE routing round — every recipient under it, including the
 * correctly-routed one — which was the reported bug. DocumentRoutingRecipientPolicy::cancel()
 * / DocumentRoutingRecipientController::cancel() instead withdraw exactly one
 * recipient row, leaving any sibling recipient on the same action slip alone.
 */
class CancelSingleRoutingRecipientTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $ad;
    private User $sds;
    private User $chiefOne;
    private User $chiefTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create(['division_id' => $this->osds->id]);
        $this->sds->assignRole('sds');

        $this->chiefOne = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chiefOne->assignRole('division_chief');

        $this->chiefTwo = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chiefTwo->assignRole('division_chief');
    }

    private function uploadedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Test memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/07/memo.pdf', 'file_hash' => hash('sha256', 'memo'),
            'status' => DocumentStatus::Received,
        ]);
    }

    public function test_cancelling_one_recipient_leaves_the_other_recipient_on_the_same_routing_untouched(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chiefOne->id, $this->chiefTwo->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $recipientOne = $routing->recipients->firstWhere('user_id', $this->chiefOne->id);
        $recipientTwo = $routing->recipients->firstWhere('user_id', $this->chiefTwo->id);

        $this->assertTrue($this->sds->can('cancel', $recipientTwo));

        $this->actingAs($this->sds)
            ->delete(route('routing-recipients.cancel', $recipientTwo))
            ->assertRedirect();

        $this->assertEquals('cancelled', $recipientTwo->fresh()->status->value);
        // The mistakenly-added recipient is cancelled, but chief one's own
        // assignment — created in the exact same routing round — must stay
        // untouched, which is the whole point of this fix.
        $this->assertEquals('pending', $recipientOne->fresh()->status->value);

        // Since chief one's recipient row is still active, the routing round
        // as a whole isn't withdrawn either.
        $this->assertNull($routing->fresh()->cancelled_at);

        $document->refresh();
        $this->assertEquals(DocumentStatus::Routed, $document->status);
    }

    public function test_cancelling_the_only_remaining_recipient_marks_the_whole_routing_cancelled(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chiefOne->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $recipient = $routing->recipients->first();

        $this->actingAs($this->sds)->delete(route('routing-recipients.cancel', $recipient));

        $this->assertEquals('cancelled', $recipient->fresh()->status->value);
        $routing->refresh();
        $this->assertNotNull($routing->cancelled_at);
        $this->assertEquals($this->sds->id, $routing->cancelled_by);

        $document->refresh();
        $this->assertTrue($this->sds->can('route', $document));
    }

    public function test_chief_can_only_cancel_their_own_pending_recipient(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chiefOne->id, $this->chiefTwo->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $recipientTwo = $routing->recipients->firstWhere('user_id', $this->chiefTwo->id);

        // A division chief has no cancel ability over an SDS-issued routing at
        // all — that pool is shared only between the SDS and her secretary.
        $this->assertFalse($this->chiefOne->can('cancel', $recipientTwo));

        $this->actingAs($this->chiefOne)
            ->delete(route('routing-recipients.cancel', $recipientTwo))
            ->assertForbidden();
    }

    public function test_cannot_cancel_an_already_cancelled_recipient(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chiefOne->id, $this->chiefTwo->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $recipientTwo = $routing->recipients->firstWhere('user_id', $this->chiefTwo->id);

        $this->actingAs($this->sds)->delete(route('routing-recipients.cancel', $recipientTwo));

        $this->actingAs($this->sds)
            ->delete(route('routing-recipients.cancel', $recipientTwo->fresh()))
            ->assertForbidden();
    }

    /**
     * Regression guard for the exact class of bug DocumentController::show()'s
     * $recipientAssignment picker was already patched for once (see its
     * comment): a recipient row that no longer represents this user's real
     * assignment must never be picked over — or in place of — their actual
     * active one. Previously that only happened via a fully-cancelled
     * routing; now it can also happen via a single individually-cancelled
     * recipient inside an otherwise-active routing (this feature). Without
     * excluding individually-cancelled rows too, the mistakenly-routed
     * chief here would see either a false "✓ Completed" card for a
     * withdrawn assignment, or — worse — have that stale row shadow a real,
     * still-active assignment they hold on a different routing round for
     * the same document, making them unable to act on it at all.
     */
    public function test_cancelling_ones_recipient_row_does_not_hide_their_real_active_assignment_on_another_routing(): void
    {
        Role::firstOrCreate(['name' => 'asds', 'guard_name' => 'web']);
        $asds = User::factory()->create();
        $asds->assignRole('asds');

        $document = $this->uploadedDocument();

        // ASDS independently routes to chief two first — her own routing
        // round, unrelated to the SDS/secretary pool.
        $this->actingAs($asds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chiefTwo->id],
        ]);

        // The SDS then separately (mistakenly) routes to both chiefs.
        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chiefOne->id, $this->chiefTwo->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $asdsRouting = $document->routings->firstWhere('routed_by', $asds->id);
        $sdsRouting = $document->routings->firstWhere('routed_by', $this->sds->id);
        $mistakenRecipient = $sdsRouting->recipients->firstWhere('user_id', $this->chiefTwo->id);

        $this->actingAs($this->sds)->delete(route('routing-recipients.cancel', $mistakenRecipient));

        $response = $this->actingAs($this->chiefTwo)->get(route('documents.show', $document));

        $response->assertInertia(fn ($page) => $page
            ->where('recipientAssignment.status', 'pending')
            ->where('recipientAssignment.routing_id', $asdsRouting->id));
    }
}
