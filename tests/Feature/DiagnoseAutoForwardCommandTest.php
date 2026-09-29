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
 * Reproduces the exact production bug this command was built to diagnose:
 * a division that looks like "ORD Proper" by name but was never coded
 * "ORDP", so IncomingDocumentController::store()'s auto-forward trigger
 * silently never fires for its secretary's uploads.
 */
class DiagnoseAutoForwardCommandTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;
    private Division $uncodedOrdProper;
    private Division $records;
    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'secretary', 'originator', 'rd', 'ard', 'division_chief'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->uncodedOrdProper = Division::create([
            'name' => 'Office of the Regional Director Proper', 'code' => null, 'parent_id' => $this->ord->id,
        ]);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS']);

        $this->secretary = User::factory()->create(['division_id' => $this->uncodedOrdProper->id]);
        $this->secretary->assignRole('secretary');
    }

    private function stuckDocument(string $title): Document
    {
        return Document::create([
            'type'        => DocumentType::Incoming,
            'title'       => $title,
            'uploaded_by' => $this->secretary->id,
            'file_path'   => 'incoming/2026/07/'.str($title)->slug().'.pdf',
            'file_hash'   => hash('sha256', $title),
            'status'      => DocumentStatus::Received,
        ]);
    }

    public function test_dry_run_reports_the_uncoded_division_and_stuck_documents_without_changing_anything(): void
    {
        $doc = $this->stuckDocument('Stuck memo');

        $this->artisan('documents:diagnose-auto-forward')
            ->expectsOutputToContain('No division is coded "ORDP"')
            ->expectsOutputToContain('Office of the Regional Director Proper')
            ->expectsOutputToContain('Stuck memo')
            ->assertExitCode(1);

        $this->uncodedOrdProper->refresh();
        $this->assertNull($this->uncodedOrdProper->code);

        $doc->refresh();
        $this->assertEquals(DocumentStatus::Received, $doc->status);
        $this->assertFalse($doc->routings()->exists());
    }

    public function test_apply_codes_the_unambiguous_division_and_backfills_stuck_documents(): void
    {
        $doc1 = $this->stuckDocument('First stuck memo');
        $doc2 = $this->stuckDocument('Second stuck memo');

        $this->artisan('documents:diagnose-auto-forward', ['--apply' => true])
            ->expectsConfirmation("Code division #{$this->uncodedOrdProper->id} \"Office of the Regional Director Proper\" as \"ORDP\" now?", 'yes')
            ->expectsConfirmation('Retroactively auto-forward 2 stuck document(s) to Records Section now?', 'yes')
            ->assertExitCode(1); // still reports the issue it fixed, per existing convention of reporting what happened

        $this->uncodedOrdProper->refresh();
        $this->assertEquals('ORDP', $this->uncodedOrdProper->code);

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

        // Simulate someone having already routed this one manually despite the bug.
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
            ->expectsConfirmation("Code division #{$this->uncodedOrdProper->id} \"Office of the Regional Director Proper\" as \"ORDP\" now?", 'yes')
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

    public function test_apply_refuses_to_guess_when_more_than_one_candidate_division_exists(): void
    {
        Division::create(['name' => 'ORD Proper (new)', 'code' => null, 'parent_id' => $this->ord->id]);
        $this->stuckDocument('Ambiguous memo');

        $this->artisan('documents:diagnose-auto-forward', ['--apply' => true])
            ->expectsOutputToContain('More than one candidate — refusing to guess')
            ->assertExitCode(1);

        $this->uncodedOrdProper->refresh();
        $this->assertNull($this->uncodedOrdProper->code);
    }

    public function test_clean_setup_reports_success_and_exit_zero(): void
    {
        $this->uncodedOrdProper->update(['code' => 'ORDP']);
        $clerk = User::factory()->create(['division_id' => $this->records->id]);
        $clerk->assignRole('originator');

        $this->artisan('documents:diagnose-auto-forward')
            ->expectsOutputToContain('No misconfiguration found')
            ->assertExitCode(0);
    }
}
