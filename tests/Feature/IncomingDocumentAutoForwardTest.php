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

class IncomingDocumentAutoForwardTest extends TestCase
{
    use RefreshDatabase;

    private Division $osdsp;
    private Division $records;
    private User $secretary;
    private User $sds;
    private User $recordsClerkA;
    private User $recordsClerkB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->osdsp = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $osds->id]);
        $ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $ad->id]);

        // Matches production: the Users form uses syncRoles(), so a secretary
        // holds only the `secretary` role, never `originator` as well.
        $this->secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $this->secretary->assignRole('secretary');

        $this->sds = User::factory()->create(['division_id' => $osds->id]);
        $this->sds->assignRole('sds');

        $this->recordsClerkA = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerkB = User::factory()->create(['division_id' => $this->records->id]);
    }

    /**
     * The auto-forward is keyed on the uploader being the SDS's secretary,
     * not on a specially-coded "OSDS Proper" office — a secretary assigned
     * directly to OSDS (the likely setup on a fresh SDO install) gets it too.
     */
    public function test_secretary_assigned_directly_to_osds_is_also_auto_forwarded(): void
    {
        $osdsSecretary = User::factory()->create(['division_id' => $this->sds->division_id]);
        $osdsSecretary->assignRole('secretary');

        $this->actingAs($osdsSecretary)->post('/documents/incoming', [
            'title'         => 'Division memo',
            'source_origin' => 'DepEd Regional Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ])->assertSessionHas('success');

        $document = Document::firstWhere('title', 'Division memo');
        $this->assertSame(DocumentStatus::Routed, $document->status);
        $this->assertTrue($document->routings()
            ->whereHas('recipients', fn ($q) => $q->where('division_id', $this->records->id))
            ->exists());
    }

    /**
     * Uploads by anyone other than the SDS's secretary (here, the SDS
     * herself) are not auto-forwarded — they land at Received for routing.
     */
    public function test_sds_own_upload_is_not_auto_forwarded(): void
    {
        $this->actingAs($this->sds)->post('/documents/incoming', [
            'title'         => 'SDS upload',
            'source_origin' => 'DepEd Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ])->assertSessionHas('success');

        $document = Document::firstWhere('title', 'SDS upload');
        $this->assertSame(DocumentStatus::Received, $document->status);
        $this->assertFalse($document->routings()->exists());
    }

    public function test_osdsp_upload_is_auto_forwarded_to_records_section_only(): void
    {
        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');

        $response = $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Test memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => $file,
        ]);

        $document = Document::firstWhere('title', 'Test memo');

        $response->assertRedirect(route('documents.show', $document));
        $this->assertSame(DocumentStatus::Routed, $document->status);

        $document->load('routings.recipients');
        $recipients = $document->routings->flatMap->recipients;

        // The SDS is not made a recipient — she sees every incoming document
        // unconditionally and acts on it directly, without an acknowledge/
        // complete formality of her own.
        $this->assertCount(1, $recipients);
        $this->assertTrue($recipients->contains(fn ($r) => $r->division_id === $this->records->id));
        $this->assertFalse($recipients->contains(fn ($r) => $r->user_id === $this->sds->id));
        $this->assertTrue($this->sds->can('view', $document));

        // Even without a recipient row, it must still surface in the SDS's "For
        // Action" list so she knows to route it onward.
        $this->assertContains($document->id, $this->forActionScopeIds($this->sds));
    }

    public function test_auto_forward_surfaces_a_warning_and_notifies_admins_when_records_section_is_missing(): void
    {
        // Simulates a fresh install where no office has been designated as
        // the Records Section yet — which previously failed completely silently.
        $this->records->update(['is_records_section' => false]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        \Illuminate\Support\Facades\Notification::fake();

        $file = UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests');

        $response = $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Orphaned memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => $file,
        ]);

        $document = Document::firstWhere('title', 'Orphaned memo');

        $response->assertRedirect(route('documents.show', $document));
        $response->assertSessionHas('warning');

        // No routing/recipients were created — the document is unrouted.
        $document->load('routings');
        $this->assertCount(0, $document->routings);
        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);

        \Illuminate\Support\Facades\Notification::assertSentTo($admin, \App\Notifications\DocumentEvent::class);
    }

    public function test_sds_can_still_route_the_document_to_a_chief_after_auto_forward(): void
    {
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Test memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        $document = Document::firstWhere('title', 'Test memo');
        $this->assertSame(DocumentStatus::Routed, $document->status);

        $chiefDivision = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);
        $chief = User::factory()->create(['division_id' => $chiefDivision->id]);
        $chief->assignRole('division_chief');

        $this->assertTrue($this->sds->can('route', $document));

        // Not yet routed by the SDS herself (only the secretary's auto-forward
        // exists) — still needs her attention.
        $this->assertContains($document->id, $this->forActionScopeIds($this->sds));

        $this->actingAs($this->sds)
            ->get(route('documents.routings.create', $document))
            ->assertOk();

        $this->actingAs($this->sds)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$chief->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_routing']));

        $document->load('routings.recipients');
        $this->assertCount(2, $document->routings);
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $chief->id));

        // Now that the SDS has routed it herself, it drops off her own For
        // Action list — she's already acted on it.
        $this->assertNotContains($document->id, $this->forActionScopeIds($this->sds));
    }

    public function test_any_records_section_member_can_acknowledge_and_complete_in_one_step(): void
    {
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Test memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        $document = Document::firstWhere('title', 'Test memo');
        $document->load('routings.recipients');
        $recipients = $document->routings->flatMap->recipients;

        $recordsRecipient = $recipients->firstWhere('division_id', $this->records->id);

        // Records clerk A acknowledges and completes the shared section
        // assignment in a single step.
        $this->actingAs($this->recordsClerkA)
            ->patch("/routing-recipients/{$recordsRecipient->id}/acknowledge", [
                'response_remarks' => 'Filed accordingly.',
            ])
            ->assertRedirect();

        $recordsRecipient->refresh();
        $this->assertEquals($this->recordsClerkA->id, $recordsRecipient->acknowledged_by);
        $this->assertEquals($this->recordsClerkA->id, $recordsRecipient->completed_by);
        $this->assertSame('Filed accordingly.', $recordsRecipient->response_remarks);

        // Records Section's acknowledgement is just a courtesy-copy
        // formality — it must NOT flip the whole document to Completed by
        // itself, since the SDS/secretary hasn't routed it to a division
        // chief yet. See test_sds_can_still_route_after_records_section_already_acknowledged().
        $document->refresh();
        $this->assertSame(DocumentStatus::Routed, $document->status);

        // Records clerk B (a different member of the same section) can no
        // longer act on it — clerk A already closed it out.
        $this->actingAs($this->recordsClerkB)
            ->patch("/routing-recipients/{$recordsRecipient->id}/acknowledge")
            ->assertForbidden();
    }

    public function test_sds_can_still_route_after_records_section_already_acknowledged(): void
    {
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Test memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        $document = Document::firstWhere('title', 'Test memo');
        $document->load('routings.recipients');
        $recordsRecipient = $document->routings->flatMap->recipients->firstWhere('division_id', $this->records->id);

        // Records Section acknowledges their courtesy copy before the SDS
        // (or her secretary) has routed the document to any division chief.
        $this->actingAs($this->recordsClerkA)
            ->patch("/routing-recipients/{$recordsRecipient->id}/acknowledge")
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::Routed, $document->status);
        $this->assertTrue($this->sds->can('route', $document));

        $chiefDivision = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);
        $chief = User::factory()->create(['division_id' => $chiefDivision->id]);
        $chief->assignRole('division_chief');

        $this->actingAs($this->sds)
            ->get(route('documents.routings.create', $document))
            ->assertOk();

        $this->actingAs($this->sds)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$chief->id],
            ])
            ->assertRedirect(route('documents.index', ['tab' => 'for_routing']));

        $document->load('routings.recipients');
        $this->assertCount(2, $document->routings);
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $chief->id));
    }

    public function test_document_completes_once_the_real_routing_and_records_section_are_both_resolved(): void
    {
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Test memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        $document = Document::firstWhere('title', 'Test memo');
        $document->load('routings.recipients');
        $recordsRecipient = $document->routings->flatMap->recipients->firstWhere('division_id', $this->records->id);

        $this->actingAs($this->recordsClerkA)
            ->patch("/routing-recipients/{$recordsRecipient->id}/acknowledge");

        $chiefDivision = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);
        $chief = User::factory()->create(['division_id' => $chiefDivision->id]);
        $chief->assignRole('division_chief');

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$chief->id],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::Routed, $document->status);

        $document->load('routings.recipients');
        $chiefRecipient = $document->routings->last()->recipients->firstWhere('user_id', $chief->id);

        $this->actingAs($chief)
            ->patch(route('routing-recipients.acknowledge', $chiefRecipient), ['response_remarks' => 'Handled.'])
            ->assertRedirect();

        // Chief is division_chief — acknowledging alone doesn't complete;
        // they must explicitly mark it complete themselves too.
        $this->actingAs($chief)
            ->patch(route('routing-recipients.complete', $chiefRecipient), ['response_remarks' => 'Done.'])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
    }

    public function test_a_user_outside_records_section_cannot_acknowledge_the_section_assignment(): void
    {
        $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Test memo',
            'source_origin' => 'DepEd Central Office',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        $document = Document::firstWhere('title', 'Test memo');
        $document->load('routings.recipients');
        $recordsRecipient = $document->routings->flatMap->recipients->firstWhere('division_id', $this->records->id);

        $outsider = User::factory()->create(['division_id' => $this->osdsp->id]);

        $this->actingAs($outsider)
            ->patch("/routing-recipients/{$recordsRecipient->id}/acknowledge")
            ->assertForbidden();
    }

    public function test_secretary_can_never_upload_her_own_signing_credential(): void
    {
        // Signing credentials belong to the SDS only — the secretary attaches
        // the SDS's signature, so she must never be able to upload her own.
        $this->assertFalse($this->secretary->can('upload', \App\Models\UserSigningCredential::class));
    }

    public function test_secretary_can_sign_using_the_rds_credential_but_not_without_it(): void
    {
        $originator = User::factory()->create(['division_id' => $this->records->id]);
        $document = Document::create([
            'type'        => DocumentType::Outgoing,
            'title'       => 'Approved for signature',
            'uploaded_by' => $originator->id,
            'division_id' => $this->records->id,
            'file_path'   => 'outgoing/2026/07/approved.pdf',
            'file_hash'   => hash('sha256', 'approved'),
            'status'      => DocumentStatus::Approved,
        ]);

        $this->assertTrue($this->secretary->can('view', $document));
        $this->assertFalse($this->secretary->can('sign', $document), 'should not be signable before the SDS has a credential');

        // The secretary having her own credential must not matter — only the SDS's does.
        \App\Models\UserSigningCredential::create([
            'user_id'  => $this->secretary->id,
            'p12_path' => 'p12/fake-secretary.p12',
            'p12_hash' => hash('sha256', 'fake-secretary'),
        ]);
        $this->assertFalse($this->secretary->can('sign', $document), 'should not be signable using the secretary\'s own credential');

        \App\Models\UserSigningCredential::create([
            'user_id'  => $this->sds->id,
            'p12_path' => 'p12/fake-sds.p12',
            'p12_hash' => hash('sha256', 'fake-sds'),
        ]);
        $this->assertTrue($this->secretary->can('sign', $document));

        $this->actingAs($this->secretary)
            ->get(route('documents.show', $document))
            ->assertOk();

        $outgoingIds = $this->outgoingScopeIds($this->secretary);
        $this->assertContains($document->id, $outgoingIds);

        $forActionIds = $this->forActionScopeIds($this->secretary);
        $this->assertContains($document->id, $forActionIds);
    }

    /** @return array<int> */
    private function outgoingScopeIds(User $user): array
    {
        return (new class {
            use \App\Traits\DocumentScopesTrait {
                outgoingScope as public;
            }
        })->outgoingScope(Document::query(), $user)->pluck('id')->all();
    }

    /** @return array<int> */
    private function forActionScopeIds(User $user): array
    {
        return (new class {
            use \App\Traits\DocumentScopesTrait {
                forActionScope as public;
            }
        })->forActionScope(Document::query(), $user)->pluck('id')->all();
    }
}
