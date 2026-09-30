<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\IncomingDocumentController;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The scenario this command exists for: the SDS's secretary uploaded
 * incoming documents before any office was designated as the Records
 * Section, so IncomingDocumentController::store()'s auto-forward failed and
 * left them sitting at Received with no routing at all.
 */
class DiagnoseAutoForwardCommandTest extends TestCase
{
    use RefreshDatabase;

    private Division $osds;
    private Division $osdsProper;
    private Division $records;
    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'secretary', 'originator', 'sds', 'asds', 'division_chief'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        // Deliberately uncoded — the auto-forward is keyed on the secretary
        // being in the OSDS tree, not on any special office code.
        $this->osdsProper = Division::create([
            'name' => 'OSDS Proper', 'code' => null, 'parent_id' => $this->osds->id,
        ]);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true]);

        $this->secretary = User::factory()->create(['division_id' => $this->osdsProper->id]);
        $this->secretary->assignRole('secretary');

        $clerk = User::factory()->create(['division_id' => $this->records->id]);
        $clerk->assignRole('originator');
    }

    private function stuckDocument(string $title, ?User $uploader = null): Document
    {
        return Document::create([
            'type'        => DocumentType::Incoming,
            'title'       => $title,
            'uploaded_by' => ($uploader ?? $this->secretary)->id,
            'file_path'   => 'incoming/2026/07/'.str($title)->slug().'.pdf',
            'file_hash'   => hash('sha256', $title),
            'status'      => DocumentStatus::Received,
        ]);
    }

    public function test_dry_run_reports_stuck_documents_without_changing_anything(): void
    {
        $doc = $this->stuckDocument('Stuck memo');

        $this->artisan('documents:diagnose-auto-forward')
            ->expectsOutputToContain('auto-forward on upload: YES')
            ->expectsOutputToContain('Stuck memo')
            ->assertExitCode(1);

        $doc->refresh();
        $this->assertEquals(DocumentStatus::Received, $doc->status);
        $this->assertFalse($doc->routings()->exists());
    }

    public function test_apply_backfills_stuck_documents_to_records_section(): void
    {
        $doc1 = $this->stuckDocument('First stuck memo');
        $doc2 = $this->stuckDocument('Second stuck memo');

        $this->artisan('documents:diagnose-auto-forward', ['--apply' => true])
            ->expectsConfirmation('Retroactively auto-forward 2 stuck document(s) to Records Section now?', 'yes')
            ->assertExitCode(1); // still reports the issue it fixed, per existing convention of reporting what happened

        foreach ([$doc1, $doc2] as $doc) {
            $doc->refresh();
            $this->assertEquals(DocumentStatus::Routed, $doc->status);
            $this->assertTrue($doc->routings()
                ->where('remarks', IncomingDocumentController::AUTO_FORWARD_REMARKS)
                ->whereHas('recipients', fn ($q) => $q->where('division_id', $this->records->id))
                ->exists());
        }
    }

    public function test_apply_does_not_touch_a_document_that_was_already_manually_routed(): void
    {
        $stuck = $this->stuckDocument('Genuinely stuck memo');
        $alreadyHandled = $this->stuckDocument('Manually routed memo');

        // Simulate someone having already routed this one manually.
        $routing = \App\Models\DocumentRouting::create([
            'document_id' => $alreadyHandled->id,
            'routed_by'   => $this->secretary->id,
            'is_rush'     => false,
            'remarks'     => 'Handled manually.',
            'routed_at'   => now(),
        ]);
        \App\Models\DocumentRoutingRecipient::create([
            'routing_id'  => $routing->id,
            'division_id' => $this->records->id,
            'action'      => 'file',
            'status'      => 'pending',
        ]);
        $alreadyHandled->update(['status' => DocumentStatus::Routed]);

        $this->artisan('documents:diagnose-auto-forward', ['--apply' => true])
            ->expectsConfirmation('Retroactively auto-forward 1 stuck document(s) to Records Section now?', 'yes')
            ->assertExitCode(1);

        $stuck->refresh();
        $this->assertEquals(DocumentStatus::Routed, $stuck->status);
        $this->assertEquals(1, $stuck->routings()->count());

        // The already-handled one keeps its single manual routing — no second one added.
        $alreadyHandled->refresh();
        $this->assertEquals(1, $alreadyHandled->routings()->count());
        $this->assertEquals('Handled manually.', $alreadyHandled->routings->first()->remarks);
    }

    public function test_a_secretary_outside_the_osds_tree_is_not_eligible_and_never_backfilled(): void
    {
        $cid = Division::create(['name' => 'Curriculum Implementation Division', 'code' => 'CID']);
        $cidSecretary = User::factory()->create(['division_id' => $cid->id]);
        $cidSecretary->assignRole('secretary');
        $doc = $this->stuckDocument('CID memo', $cidSecretary);

        $this->artisan('documents:diagnose-auto-forward', ['--apply' => true])
            ->expectsOutputToContain('NO (not in OSDS or one of its sub-offices)')
            ->assertExitCode(0);

        $this->assertFalse($doc->fresh()->routings()->exists());
    }

    public function test_reports_when_no_records_section_is_designated(): void
    {
        $this->records->update(['is_records_section' => false]);

        // A user with no office at all must not be mistaken for Records Section staff.
        User::factory()->create(['name' => 'Officeless Admin', 'division_id' => null])->assignRole('admin');

        $this->artisan('documents:diagnose-auto-forward')
            ->expectsOutputToContain('No office is designated as the Records Section')
            ->doesntExpectOutputToContain('Correctly assigned')
            ->assertExitCode(1);
    }

    public function test_clean_setup_reports_success_and_exit_zero(): void
    {
        $this->artisan('documents:diagnose-auto-forward')
            ->expectsOutputToContain('No misconfiguration found')
            ->assertExitCode(0);
    }
}
