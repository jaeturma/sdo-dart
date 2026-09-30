<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRouting;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reproduces the production scenario this backfill fixes: Florence and
 * Agnes routed documents to division chiefs while they were the SDS's (OSDS)
 * secretaries. They were later transferred to Administrative Division, so
 * they no longer pass isOsdsSecretary() today — meaning their historical
 * routings, created before is_sds_routing existed, need a one-time backfill
 * to be recognized as "the SDS's queue was served" going forward.
 */
class BackfillSdsRoutingFlagCommandTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $osdsp;
    private Division $ad;
    private User $sds;
    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->osdsp = Division::create(['name' => 'OSDS Proper', 'code' => 'OSDSP', 'parent_id' => $this->osds->id]);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create(['division_id' => $this->osds->id]);
        $this->sds->assignRole('sds');

        $this->chief = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chief->assignRole('division_chief');
    }

    /** Simulates a pre-existing routing created before is_sds_routing existed. */
    private function legacyRouting(User $router, Document $document): DocumentRouting
    {
        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $router->id,
            'is_rush' => false, 'routed_at' => now()->subMonths(2),
        ]);

        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $this->chief->id,
            'action' => 'appropriate_action', 'status' => 'pending',
        ]);

        return $routing;
    }

    public function test_dry_run_reports_florences_legacy_routing_without_changing_anything(): void
    {
        // Florence WAS the OSDS secretary when she routed this — but has
        // SINCE been transferred to Administrative Division.
        $florence = User::factory()->create(['division_id' => $this->ad->id]);
        $florence->assignRole('secretary');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Florence memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/05/florence.pdf', 'file_hash' => hash('sha256', 'florence'),
            'status' => DocumentStatus::Routed,
        ]);
        $routing = $this->legacyRouting($florence, $document);

        $this->artisan('documents:backfill-sds-routing-flag')
            ->expectsOutputToContain('Florence memo')
            ->assertExitCode(0);

        $routing->refresh();
        $this->assertFalse($routing->is_sds_routing);
    }

    public function test_apply_marks_secretarys_legacy_routing_even_after_division_transfer(): void
    {
        $florence = User::factory()->create(['division_id' => $this->ad->id]);
        $florence->assignRole('secretary');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Florence memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/05/florence.pdf', 'file_hash' => hash('sha256', 'florence'),
            'status' => DocumentStatus::Routed,
        ]);
        $routing = $this->legacyRouting($florence, $document);

        $this->artisan('documents:backfill-sds-routing-flag', ['--apply' => true])
            ->expectsConfirmation('Mark 1 routing(s) as is_sds_routing = true now?', 'yes')
            ->assertExitCode(0);

        $routing->refresh();
        $this->assertTrue($routing->is_sds_routing);

        // Now the SDS correctly no longer sees it as needing her action.
        $this->assertFalse($this->sds->can('route', $document->fresh()));
    }

    public function test_apply_marks_legacy_routing_by_the_sds_herself(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'SDS memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/05/sds.pdf', 'file_hash' => hash('sha256', 'sds'),
            'status' => DocumentStatus::Routed,
        ]);
        $routing = $this->legacyRouting($this->sds, $document);

        $this->artisan('documents:backfill-sds-routing-flag', ['--apply' => true])
            ->expectsConfirmation('Mark 1 routing(s) as is_sds_routing = true now?', 'yes')
            ->assertExitCode(0);

        $routing->refresh();
        $this->assertTrue($routing->is_sds_routing);
    }

    public function test_does_not_touch_a_chiefs_own_onward_routing(): void
    {
        $secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $secretary->assignRole('secretary');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Onward memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/05/onward.pdf', 'file_hash' => hash('sha256', 'onward'),
            'status' => DocumentStatus::Routed,
        ]);

        // Secretary's legacy dispatch to the chief.
        $dispatch = $this->legacyRouting($secretary, $document);
        $dispatchRecipient = $dispatch->recipients->first();
        $dispatchRecipient->update(['status' => 'acknowledged', 'acknowledged_at' => now()->subMonth()]);

        // Chief's own onward routing to their staff — created AFTER the dispatch.
        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');
        $onward = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->chief->id,
            'is_rush' => false, 'routed_at' => now()->subWeeks(3),
        ]);
        DocumentRoutingRecipient::create([
            'routing_id' => $onward->id, 'user_id' => $staff->id,
            'action' => 'appropriate_action', 'status' => 'pending',
        ]);

        $this->artisan('documents:backfill-sds-routing-flag', ['--apply' => true])
            ->expectsConfirmation('Mark 1 routing(s) as is_sds_routing = true now?', 'yes')
            ->assertExitCode(0);

        $dispatch->refresh();
        $onward->refresh();
        $this->assertTrue($dispatch->is_sds_routing, 'the top-level dispatch should be marked');
        $this->assertFalse($onward->is_sds_routing, "the chief's own onward routing must never be marked");
    }

    public function test_reports_ambiguous_routing_separately_and_does_not_apply_it_by_default(): void
    {
        // Agnes routed while a secretary, but has SINCE been moved off the
        // secretary role entirely (not just transferred divisions) — we
        // can no longer automatically confirm she was the OSDS secretary at
        // the time, so this must be reported, not silently auto-fixed.
        $agnes = User::factory()->create(['division_id' => $this->ad->id]);
        $agnes->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Agnes memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/05/agnes.pdf', 'file_hash' => hash('sha256', 'agnes'),
            'status' => DocumentStatus::Routed,
        ]);
        $routing = $this->legacyRouting($agnes, $document);

        $this->artisan('documents:backfill-sds-routing-flag')
            ->expectsOutputToContain('needs manual confirmation')
            ->expectsOutputToContain('Agnes memo')
            ->assertExitCode(0);

        $this->artisan('documents:backfill-sds-routing-flag', ['--apply' => true])
            ->assertExitCode(0);

        $routing->refresh();
        $this->assertFalse($routing->is_sds_routing, 'ambiguous rows must not be auto-applied without --also-mark-ambiguous');
    }

    public function test_also_mark_ambiguous_applies_the_ambiguous_rows_too(): void
    {
        $agnes = User::factory()->create(['division_id' => $this->ad->id]);
        $agnes->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Agnes memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/05/agnes.pdf', 'file_hash' => hash('sha256', 'agnes'),
            'status' => DocumentStatus::Routed,
        ]);
        $routing = $this->legacyRouting($agnes, $document);

        $this->artisan('documents:backfill-sds-routing-flag', ['--apply' => true, '--also-mark-ambiguous' => true])
            ->expectsConfirmation('Mark 1 routing(s) as is_sds_routing = true now?', 'yes')
            ->assertExitCode(0);

        $routing->refresh();
        $this->assertTrue($routing->is_sds_routing);
    }

    public function test_does_not_touch_the_auto_forward_routing(): void
    {
        $records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);
        $secretary = User::factory()->create(['division_id' => $this->osdsp->id]);
        $secretary->assignRole('secretary');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Auto-forwarded memo', 'uploaded_by' => $secretary->id,
            'file_path' => 'incoming/2026/05/auto.pdf', 'file_hash' => hash('sha256', 'auto'),
            'status' => DocumentStatus::Routed,
        ]);
        $autoRouting = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $secretary->id,
            'is_rush' => false, 'routed_at' => now()->subMonths(2),
            'remarks' => \App\Http\Controllers\IncomingDocumentController::AUTO_FORWARD_REMARKS,
        ]);
        DocumentRoutingRecipient::create([
            'routing_id' => $autoRouting->id, 'division_id' => $records->id,
            'action' => 'file', 'status' => 'pending',
        ]);

        $this->artisan('documents:backfill-sds-routing-flag')
            ->expectsOutputToContain('No affected')
            ->assertExitCode(0);

        $autoRouting->refresh();
        $this->assertFalse($autoRouting->is_sds_routing);
    }
}
