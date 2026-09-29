<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\ArdAvailabilitySetting;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The RD's own (ORD) secretary drafts memoranda on the RD's behalf from
 * time to time, not just handling incoming mail/signing — so she now gets
 * DocumentPolicy::createOutgoing() too. Her own submission skips unit-head/
 * division-chief review entirely (mirroring an ORD unit head's own upload,
 * see OutgoingDocumentController::initialStatus()) since nobody sits above
 * her in ORD Proper except ARD/RD. A secretary outside the ORD tree does
 * NOT get this ability.
 */
class SecretaryOutgoingDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;
    private Division $ordp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->ordp = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $this->ord->id]);
    }

    public function test_ord_secretary_can_create_an_outgoing_document(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->ordp->id]);
        $secretary->assignRole('secretary');

        $this->assertTrue($secretary->can('createOutgoing', Document::class));

        $this->actingAs($secretary)
            ->get(route('documents.outgoing.create'))
            ->assertOk();

        $this->actingAs($secretary)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', true));
    }

    public function test_ord_secretarys_memo_skips_straight_to_ard_review(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->ordp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)->post('/documents/outgoing', [
            'title'            => 'Secretary memo',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Secretary memo');
        $this->assertSame(DocumentStatus::PendingArdReview, $document->status);

        $ard = User::factory()->create();
        $ard->assignRole('ard');
        $this->assertTrue($ard->can('review', $document));
        $this->assertFalse($secretary->can('review', $document));
    }

    public function test_ord_secretarys_memo_skips_to_rd_review_when_ard_unavailable(): void
    {
        ArdAvailabilitySetting::toggle();
        $this->assertFalse(ArdAvailabilitySetting::isAvailable());

        $secretary = User::factory()->create(['division_id' => $this->ordp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)->post('/documents/outgoing', [
            'title'            => 'Secretary memo while ARD away',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Secretary memo while ARD away');
        $this->assertSame(DocumentStatus::PendingRdReview, $document->status);
    }

    public function test_secretary_outside_ord_cannot_create_outgoing_document(): void
    {
        $ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $secretary = User::factory()->create(['division_id' => $ad->id]);
        $secretary->assignRole('secretary');

        $this->assertFalse($secretary->can('createOutgoing', Document::class));

        $this->actingAs($secretary)
            ->get(route('documents.outgoing.create'))
            ->assertForbidden();

        $this->actingAs($secretary)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', false));
    }

    public function test_ord_secretarys_memo_appears_in_her_own_outgoing_list_throughout(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->ordp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)->post('/documents/outgoing', [
            'title'            => 'Trackable memo',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ]);

        $document = Document::firstWhere('title', 'Trackable memo');

        $this->actingAs($secretary)
            ->get(route('documents.index', ['tab' => 'outgoing']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }
}
