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
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bug report: an already-routed document kept reappearing in the SDS's (or
 * her secretary's) For Action/For Routing queue. Root cause — the SDS's
 * secretary routes incoming documents *in lieu of* the SDS (not as an
 * independent channel), but DocumentPolicy::route()'s and
 * DocumentScopesTrait::forActionScope()'s "have I already routed this"
 * check was tracked strictly per-individual-user. So if the secretary
 * routed a document to a division chief, the SDS (who never personally
 * routed it herself) would still see "Route Document" and the document
 * would keep showing in her own queue — and vice versa. Fixed via
 * sdsActingUserIds(): the SDS and every OSDS secretary now share one combined
 * "already routed" pool. ASDS deliberately stays independent — she's a
 * genuinely separate reviewer, not a delegate.
 */
class SdsSecretaryRoutedTogetherTest extends TestCase
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

    public function test_document_drops_out_of_rds_queue_after_secretary_routes_it_on_her_behalf(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh();
        $this->assertFalse($this->sds->can('route', $document));

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'for_routing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_document_drops_out_of_secretarys_queue_after_sds_routes_it_herself(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh();
        $this->assertFalse($this->secretary->can('route', $document));

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_document_stays_out_of_rds_queue_even_after_chief_routes_to_staff(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $chiefRecipient = $document->routings->first()->recipients->first();
        $this->actingAs($this->chief)->patch(route('routing-recipients.acknowledge', $chiefRecipient));

        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');
        $this->actingAs($this->chief)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$staff->id],
        ]);

        $document->refresh();
        $this->assertFalse($this->sds->can('route', $document));

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'for_routing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_asds_stays_independent_and_can_still_route_after_secretary_already_did(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh();

        // ASDS is not part of the SDS/secretary pool — the secretary routing
        // it doesn't count as ASDS having acted, so ASDS independently still
        // sees it and can route it herself too.
        $this->assertTrue($this->asds->can('route', $document));

        $this->actingAs($this->asds)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_sds_can_still_route_once_even_though_secretary_already_did(): void
    {
        // Confirms the shared pool only suppresses re-appearing in the
        // queue/route ability — it doesn't secretly let the SDS bypass the
        // once-each rule; after the secretary routes, NEITHER of them may
        // route it again (matches the single-router "once each" intent).
        $document = $this->uploadedDocument();

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh();
        $this->assertFalse($this->sds->can('route', $document));
        $this->assertFalse($this->secretary->can('route', $document));
    }

    /**
     * Reproduces the exact follow-up bug report: a secretary routes a
     * document while she's the SDS's (OSDS) secretary, then is later
     * transferred to a completely different division (and, in the more
     * severe case, loses the secretary role entirely). Her past routing
     * decision must still count as "the SDS's queue was served" — it must
     * not depend on her *current* role/division at query time, or every
     * reassignment silently reopens old documents in the SDS's queue again.
     */
    public function test_secretarys_routing_still_counts_after_she_is_reassigned_away_from_osds(): void
    {
        $document = $this->uploadedDocument();

        $this->actingAs($this->secretary)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);
        $document->refresh();

        $this->assertFalse($this->sds->can('route', $document));

        // She gets transferred to Administrative Division — and, in the
        // more severe real-world case, her role is changed away from
        // secretary entirely (e.g. to originator).
        $this->secretary->update(['division_id' => $this->ad->id]);
        $this->secretary->syncRoles(['originator']);

        $document->refresh();
        $this->assertFalse(
            $this->sds->can('route', $document),
            'a routing already issued by the (former) OSDS secretary must still count for the SDS, regardless of her current role/division',
        );

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'for_routing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }
}
