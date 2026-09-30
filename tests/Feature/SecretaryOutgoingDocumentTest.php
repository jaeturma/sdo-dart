<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
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
 * The SDS's own (OSDS) secretary drafts memoranda on the SDS's behalf from
 * time to time, not just handling incoming mail/signing — so she now gets
 * DocumentPolicy::createOutgoing() too. Her own submission skips unit-head/
 * division-chief review entirely (mirroring an OSDS unit head's own upload,
 * see OutgoingDocumentController::initialStatus()) since nobody sits above
 * her in OSDS Proper except ASDS/SDS. A secretary outside the OSDS tree does
 * NOT get this ability.
 */
class SecretaryOutgoingDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $osdsp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->osdsp = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $this->osds->id]);
    }

    public function test_osds_secretary_can_create_an_outgoing_document(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $secretary->assignRole('secretary');

        $this->assertTrue($secretary->can('createOutgoing', Document::class));

        $this->actingAs($secretary)
            ->get(route('documents.outgoing.create'))
            ->assertOk();

        $this->actingAs($secretary)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', true));
    }

    public function test_osds_secretarys_memo_skips_straight_to_asds_review(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)->post('/documents/outgoing', [
            'title'            => 'Secretary memo',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Secretary memo');
        $this->assertSame(DocumentStatus::PendingAsdsReview, $document->status);

        $asds = User::factory()->create();
        $asds->assignRole('asds');
        $this->assertTrue($asds->can('review', $document));
        $this->assertFalse($secretary->can('review', $document));
    }

    public function test_osds_secretarys_memo_skips_to_sds_review_when_asds_unavailable(): void
    {
        AsdsAvailabilitySetting::toggle();
        $this->assertFalse(AsdsAvailabilitySetting::isAvailable());

        $secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $secretary->assignRole('secretary');

        $this->actingAs($secretary)->post('/documents/outgoing', [
            'title'            => 'Secretary memo while ASDS away',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Secretary memo while ASDS away');
        $this->assertSame(DocumentStatus::PendingSdsReview, $document->status);
    }

    public function test_secretary_outside_osds_cannot_create_outgoing_document(): void
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

    public function test_osds_secretarys_memo_appears_in_her_own_outgoing_list_throughout(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
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
