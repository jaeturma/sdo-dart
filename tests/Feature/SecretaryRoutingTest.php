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
 * The RD's own secretary (ORD/ORD Proper) can route incoming documents in
 * lieu of the RD — same "once each" rule, same blanket incoming visibility.
 * A secretary anywhere outside the ORD division tree does not get this.
 */
class SecretaryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;
    private Division $ordp;
    private User $secretary;
    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord  = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->ordp = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $this->ord->id]);

        $this->secretary = User::factory()->create(['division_id' => $this->ordp->id]);
        $this->secretary->assignRole('secretary');

        $chiefDivision = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->chief = User::factory()->create(['division_id' => $chiefDivision->id]);
        $this->chief->assignRole('division_chief');

        Division::create(['name' => 'Records Section', 'code' => 'RS', 'parent_id' => $chiefDivision->id]);
    }

    private function receivedDocument(User $uploader): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Inbound memo',
            'uploaded_by' => $uploader->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::Received,
        ]);
    }

    public function test_ord_secretary_can_view_and_route_a_document_she_did_not_upload(): void
    {
        // Uploaded by an unrelated user in an unrelated division — the
        // secretary isn't the uploader, isn't a recipient, and isn't in the
        // same division, yet must still be able to see and route it.
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $uploader = User::factory()->create(['division_id' => $division->id]);
        $uploader->assignRole('originator');
        $document = $this->receivedDocument($uploader);

        $this->assertTrue($this->secretary->can('view', $document));
        $this->assertTrue($this->secretary->can('route', $document));

        $this->actingAs($this->secretary)
            ->get(route('documents.show', $document))
            ->assertOk();

        $this->actingAs($this->secretary)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::Routed, $document->fresh()->status);
    }

    public function test_ord_secretary_can_only_route_a_document_once(): void
    {
        $document = $this->receivedDocument($this->secretary);

        $this->actingAs($this->secretary)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertRedirect();

        $this->assertFalse($this->secretary->can('route', $document->fresh()));
    }

    public function test_ord_secretary_sees_routable_documents_in_her_for_action_tab(): void
    {
        $division = Division::create(['name' => 'Finance Division', 'code' => 'FD']);
        $uploader = User::factory()->create(['division_id' => $division->id]);
        $uploader->assignRole('originator');
        $document = $this->receivedDocument($uploader);

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    /**
     * Reproduces a real bug report: the ORDP secretary uploads an incoming
     * document (auto-forwarding it to Records Section), and then has no
     * "Route Document" option on her own upload at all. Root cause: the
     * auto-forward routing is recorded with routed_by = her own id, which
     * collided with the new once-each "has this user already routed it"
     * check once she gained the same routing ability as RD/ARD — the
     * automatic system action was incorrectly consuming her one manual
     * routing turn. She must still be able to route it herself afterward
     * (e.g. on to a division chief), same as the RD always could.
     */
    public function test_ordp_secretary_can_still_route_her_own_upload_after_auto_forward(): void
    {
        $response = $this->actingAs($this->secretary)->post('/documents/incoming', [
            'title'         => 'Self-uploaded memo',
            'source_origin' => 'Test',
            'file'          => UploadedFile::fake()->createWithContent('inbound.pdf', '%PDF-1.4 fake pdf content for tests'),
        ]);

        $document = Document::firstWhere('title', 'Self-uploaded memo');
        $response->assertRedirect(route('documents.show', $document));
        $this->assertSame(DocumentStatus::Routed, $document->status);

        // The auto-forward exists and is attributed to her (for the audit
        // trail), but must not block her own subsequent routing turn.
        $this->assertTrue($document->routings()->where('routed_by', $this->secretary->id)->exists());
        $this->assertTrue($this->secretary->can('route', $document));

        $this->actingAs($this->secretary)
            ->get(route('documents.index', ['tab' => 'for_action']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));

        $this->actingAs($this->secretary)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertRedirect();

        // Now that she's manually routed it herself, the once-each rule
        // correctly kicks in for real.
        $this->assertFalse($this->secretary->can('route', $document->fresh()));
    }

    public function test_secretary_outside_ord_cannot_route(): void
    {
        $otherDivision = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);
        $otherSecretary = User::factory()->create(['division_id' => $otherDivision->id]);
        $otherSecretary->assignRole('secretary');

        $document = $this->receivedDocument($this->secretary);

        $this->assertFalse($otherSecretary->can('route', $document));

        $this->actingAs($otherSecretary)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertForbidden();
    }
}
