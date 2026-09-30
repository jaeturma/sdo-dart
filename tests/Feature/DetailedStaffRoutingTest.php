<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\AsdsAvailabilitySetting;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff temporarily "detailed" (seconded) to this Schools Division Office from
 * elsewhere (e.g. a school or another division office) don't fit anywhere in this app's
 * Division hierarchy, but the SDS/her secretary still need to route incoming
 * documents directly to them, and they need to be able to originate their
 * own outgoing documents. See RoleAndDivisionSeeder's 'detailed_staff' role
 * comment and OutgoingDocumentController::initialStatus()/DocumentPolicy::
 * createOutgoing() for the full rationale.
 */
class DetailedStaffRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private User $sds;
    private User $secretary;
    private User $asds;
    private User $chief;
    private User $detailed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary', 'detailed_staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $osdsp = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $this->osds->id]);
        $ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create(['division_id' => $this->osds->id]);
        $this->sds->assignRole('sds');

        $this->secretary = User::factory()->create(['division_id' => $osdsp->id]);
        $this->secretary->assignRole('secretary');

        $this->asds = User::factory()->create();
        $this->asds->assignRole('asds');

        $this->chief = User::factory()->create(['division_id' => $ad->id]);
        $this->chief->assignRole('division_chief');

        $this->detailed = User::factory()->create(['division_id' => null]);
        $this->detailed->assignRole('detailed_staff');
    }

    private function receivedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Inbound memo',
            'uploaded_by' => $this->sds->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::Received,
        ]);
    }

    public function test_sds_sees_detailed_staff_as_a_routing_candidate(): void
    {
        $document = $this->receivedDocument();

        $this->actingAs($this->sds)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page->where(
                'users',
                fn ($users) => collect($users)->pluck('id')->contains($this->detailed->id),
            ));
    }

    public function test_osds_secretary_sees_detailed_staff_as_a_routing_candidate(): void
    {
        $document = $this->receivedDocument();

        $response = $this->actingAs($this->secretary)
            ->get(route('documents.routings.candidates', $document))
            ->assertOk();

        $ids = collect($response->json('users'))->pluck('id');
        $this->assertTrue($ids->contains($this->detailed->id));
    }

    public function test_asds_does_not_see_detailed_staff_as_a_routing_candidate(): void
    {
        $document = $this->receivedDocument();

        $response = $this->actingAs($this->asds)
            ->get(route('documents.routings.candidates', $document))
            ->assertOk();

        $ids = collect($response->json('users'))->pluck('id');
        $this->assertFalse($ids->contains($this->detailed->id));
    }

    public function test_asds_routing_to_detailed_staff_is_rejected_server_side(): void
    {
        $document = $this->receivedDocument();

        $this->actingAs($this->asds)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$this->detailed->id],
            ])
            ->assertSessionHasErrors('user_ids');

        $this->assertDatabaseMissing('document_routings', ['document_id' => $document->id]);
    }

    public function test_division_chiefs_own_staff_candidate_list_never_includes_detailed_staff(): void
    {
        $document = $this->receivedDocument();

        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action' => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $recipient = \App\Models\DocumentRoutingRecipient::whereHas(
            'routing', fn ($q) => $q->where('document_id', $document->id),
        )->first();
        $this->actingAs($this->chief)->patch(route('routing-recipients.acknowledge', $recipient), []);

        $response = $this->actingAs($this->chief)
            ->get(route('documents.routings.candidates', $document))
            ->assertOk();

        $ids = collect($response->json('users'))->pluck('id');
        $this->assertFalse($ids->contains($this->detailed->id));
    }

    public function test_sds_routes_to_detailed_staff_and_they_can_view_and_act_on_it(): void
    {
        $document = $this->receivedDocument();

        $this->actingAs($this->sds)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$this->detailed->id],
            ])
            ->assertRedirect();

        $this->assertTrue($this->detailed->can('view', $document));

        $this->actingAs($this->detailed)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));

        $recipient = \App\Models\DocumentRoutingRecipient::whereHas(
            'routing', fn ($q) => $q->where('document_id', $document->id),
        )->first();

        $this->actingAs($this->detailed)
            ->patch(route('routing-recipients.acknowledge', $recipient), ['response_remarks' => 'Noted.'])
            ->assertRedirect();

        $this->assertEquals('completed', $recipient->fresh()->status->value);
    }

    public function test_detailed_staff_cannot_issue_a_routing_themselves(): void
    {
        $document = $this->receivedDocument();

        $this->assertFalse($this->detailed->can('route', $document));

        $this->actingAs($this->detailed)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertForbidden();
    }

    public function test_detailed_staff_cannot_upload_incoming_documents(): void
    {
        $this->assertFalse($this->detailed->can('createIncoming', Document::class));
    }

    public function test_detailed_staff_can_create_outgoing_document_and_it_goes_to_asds_review(): void
    {
        $this->assertTrue($this->detailed->can('createOutgoing', Document::class));

        $this->actingAs($this->detailed)->post('/documents/outgoing', [
            'title' => 'Detailed staff memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Detailed staff memo');
        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);
        $this->assertTrue($this->asds->can('review', $document));
    }

    public function test_detailed_staff_upload_skips_to_sds_when_asds_unavailable(): void
    {
        AsdsAvailabilitySetting::toggle();
        $this->assertFalse(AsdsAvailabilitySetting::isAvailable());

        $this->actingAs($this->detailed)->post('/documents/outgoing', [
            'title' => 'Detailed staff memo, ASDS unavailable',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Detailed staff memo, ASDS unavailable');
        $this->assertSame(DocumentStatus::PendingSdsReview, $document->status);
    }

    public function test_detailed_staff_resubmission_also_goes_to_asds_review(): void
    {
        $this->actingAs($this->detailed)->post('/documents/outgoing', [
            'title' => 'Detailed staff memo needing revision',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ]);

        $document = Document::firstWhere('title', 'Detailed staff memo needing revision');
        $document->update(['status' => DocumentStatus::ReturnedForRevision]);

        $this->actingAs($this->detailed)
            ->put(route('documents.outgoing.resubmit.update', $document), [
                'title' => 'Detailed staff memo needing revision',
                'description' => 'Fixed as requested.',
                'file' => UploadedFile::fake()->createWithContent('memo2.pdf', '%PDF-1.4 revised fake pdf content'),
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->fresh()->status);
    }

    public function test_admin_can_create_a_detailed_staff_user_with_no_division(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@example.test',
            'password' => 'Password123!@#',
            'password_confirmation' => 'Password123!@#',
            'role' => 'detailed_staff',
            'division_id' => '',
        ])->assertRedirect();

        $created = User::firstWhere('email', 'juan.delacruz@example.test');
        $this->assertNotNull($created);
        $this->assertNull($created->division_id);
        $this->assertTrue($created->hasRole('detailed_staff'));
    }

    public function test_other_roles_still_require_a_division(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'No Division Chief',
            'email' => 'no.division@example.test',
            'password' => 'Password123!@#',
            'password_confirmation' => 'Password123!@#',
            'role' => 'division_chief',
            'division_id' => '',
        ])->assertSessionHasErrors('division_id');
    }
}
