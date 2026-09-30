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
 * The SDS and her OSDS secretary can undo a mistaken routing (e.g. the wrong
 * chief selected) before anyone's acknowledged it, same as a division chief
 * already could. Because the SDS and her secretary share "already routed"
 * ownership via `is_sds_routing` (DocumentPolicy::route()'s once-each check
 * treats them as one pool — she routes *as* the SDS, not independently),
 * cancel ownership is shared the same way: either of them can undo a
 * mistake the other made, not just their own. A division chief stays scoped
 * to their own `routed_by` id. ASDS and OSDS unit heads have no path into
 * this — ASDS is a genuinely independent reviewer (excluded from the shared
 * pool everywhere else too), and unit heads aren't part of this request.
 */
class SdsCancelRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $osdsp;
    private Division $ad;
    private User $sds;
    private User $asds;
    private User $secretary;
    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->osdsp = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $this->osds->id]);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create(['division_id' => $this->osds->id]);
        $this->sds->assignRole('sds');

        $this->asds = User::factory()->create();
        $this->asds->assignRole('asds');

        $this->secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $this->secretary->assignRole('secretary');

        $this->chief = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chief->assignRole('division_chief');
    }

    private function uploadedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Test memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/07/memo.pdf', 'file_hash' => hash('sha256', 'memo'),
            'status' => DocumentStatus::Received,
        ]);
    }

    public function test_sds_can_cancel_her_own_mistaken_routing_and_reroute(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();

        $this->assertTrue($this->sds->can('cancel', $routing));

        $this->actingAs($this->sds)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertRedirect(route('documents.show', $document));

        $routing->refresh();
        $this->assertNotNull($routing->cancelled_at);
        $this->assertEquals($this->sds->id, $routing->cancelled_by);
        $this->assertTrue($routing->recipients->every(fn ($r) => $r->status->value === 'cancelled'));

        $document->refresh();
        $this->assertTrue($this->sds->can('route', $document));

        $anotherChief = User::factory()->create(['division_id' => $this->ad->id]);
        $anotherChief->assignRole('division_chief');

        $this->actingAs($this->sds)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$anotherChief->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_routing']));

        $document->refresh()->load('routings.recipients');
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $anotherChief->id));
    }

    public function test_secretary_can_cancel_a_routing_the_sds_made_herself(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();

        $this->assertTrue($this->secretary->can('cancel', $routing));

        $this->actingAs($this->secretary)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertRedirect(route('documents.show', $document));

        $routing->refresh();
        $this->assertNotNull($routing->cancelled_at);
        $this->assertEquals($this->secretary->id, $routing->cancelled_by);
    }

    public function test_sds_can_cancel_a_routing_the_secretary_made(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();

        $this->assertTrue($this->sds->can('cancel', $routing));

        $this->actingAs($this->sds)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertTrue($this->sds->can('route', $document));
        $this->assertTrue($this->secretary->can('route', $document));
    }

    /**
     * Unlike a division chief's own cancel ability (which stays restricted
     * to before-acknowledgment only), the SDS/her secretary may cancel &
     * reroute anytime — even after the recipient has acknowledged it. This
     * mirrors the unconditional override already given to her for deleting
     * her own incoming uploads (see DocumentPolicy::delete()). Real reported
     * case: the secretary needed to reroute a document after the recipient
     * had already acknowledged it and had no way to do so under the old rule.
     */
    public function test_sds_can_still_cancel_after_chief_has_acknowledged(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $chiefRecipient = $routing->recipients->first();

        $this->actingAs($this->chief)->patch(route('routing-recipients.acknowledge', $chiefRecipient));

        $this->assertTrue($this->sds->can('cancel', $routing->fresh()));

        $this->actingAs($this->sds)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertRedirect(route('documents.show', $document));

        $routing->refresh();
        $this->assertNotNull($routing->cancelled_at);
        $this->assertEquals('cancelled', $chiefRecipient->fresh()->status->value);

        $document->refresh();
        $this->assertTrue($this->sds->can('route', $document));

        $anotherChief = User::factory()->create(['division_id' => $this->ad->id]);
        $anotherChief->assignRole('division_chief');

        $this->actingAs($this->sds)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$anotherChief->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_routing']));
    }

    /**
     * Even after the recipient has fully COMPLETED the routing (and that
     * completion promoted the whole document to Completed, since it was the
     * only outstanding recipient), the SDS/secretary can still cancel it —
     * and cancelling reopens the document back to Routed so it can be routed
     * again, rather than leaving it stuck at Completed with no active
     * routing to show for it.
     */
    public function test_sds_can_cancel_even_after_recipient_has_completed_it_and_document_reopens_to_routed(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $chiefRecipient = $routing->recipients->first();

        $this->actingAs($this->chief)->patch(route('routing-recipients.acknowledge', $chiefRecipient));
        $this->actingAs($this->chief)->patch(route('routing-recipients.complete', $chiefRecipient));

        $document->refresh();
        $this->assertEquals(DocumentStatus::Completed, $document->status);

        $this->assertTrue($this->sds->can('cancel', $routing->fresh()));

        $this->actingAs($this->sds)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertRedirect(route('documents.show', $document));

        $this->assertEquals('cancelled', $chiefRecipient->fresh()->status->value);

        $document->refresh();
        $this->assertEquals(DocumentStatus::Routed, $document->status);
        $this->assertTrue($this->sds->can('route', $document));

        $anotherChief = User::factory()->create(['division_id' => $this->ad->id]);
        $anotherChief->assignRole('division_chief');

        $this->actingAs($this->sds)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$anotherChief->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_routing']));
    }

    public function test_cannot_double_cancel_an_sds_routing(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();

        $this->actingAs($this->sds)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertRedirect(route('documents.show', $document));

        $this->actingAs($this->sds)
            ->delete(route('documents.routings.cancel', [$document, $routing->fresh()]))
            ->assertForbidden();
    }

    public function test_asds_cannot_cancel_a_routing_issued_by_sds_or_secretary(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();

        $this->assertFalse($this->asds->can('cancel', $routing));

        $this->actingAs($this->asds)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertForbidden();

        // And the reverse: ASDS routes independently herself (she's allowed
        // to route, per DocumentPolicy::route()'s own asds branch), but her
        // routing never joins the shared is_sds_routing pool, so nobody —
        // not even the SDS — can cancel it via this pathway either.
        $asdsDocument = $this->uploadedDocument();
        $this->actingAs($this->asds)->post(route('documents.routings.store', $asdsDocument), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $asdsDocument->refresh()->load('routings.recipients');
        $asdsRouting = $asdsDocument->routings->first();

        $this->assertFalse($asdsRouting->is_sds_routing);
        $this->assertFalse($this->sds->can('cancel', $asdsRouting));
        $this->assertFalse($this->asds->can('cancel', $asdsRouting));
    }

    public function test_chief_cannot_cancel_the_rds_routing(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();

        $this->assertFalse($this->chief->can('cancel', $routing));

        $this->actingAs($this->chief)
            ->delete(route('documents.routings.cancel', [$document, $routing]))
            ->assertForbidden();
    }

    public function test_document_reappears_in_rds_for_routing_tab_after_secretary_cancels(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'for_routing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $this->actingAs($this->secretary)->delete(route('documents.routings.cancel', [$document, $routing]));

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'for_routing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_document_reappears_in_secretarys_for_action_tab_after_sds_cancels(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));

        $document->refresh()->load('routings.recipients');
        $routing = $document->routings->first();
        $this->actingAs($this->sds)->delete(route('documents.routings.cancel', [$document, $routing]));

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }
}
