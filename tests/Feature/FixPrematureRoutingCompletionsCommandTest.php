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
 * Reproduces the exact production bug this command retroactively fixes: an
 * ORD unit head's (or division chief's) acknowledgement went straight to
 * Completed in one step before the routing-deferral fix existed, which then
 * auto-promoted the whole document to Completed, permanently hiding
 * "Route Document" before they ever got a chance to forward it.
 */
class FixPrematureRoutingCompletionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;

    private Division $ictu;

    private Division $ad;

    private User $rd;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->ictu = Division::create(['name' => 'ICT Unit', 'code' => 'ICTU', 'parent_id' => $this->ord->id, 'allow_rd_direct_routing' => true]);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->rd = User::factory()->create(['division_id' => $this->ord->id]);
        $this->rd->assignRole('rd');
    }

    private function makeStuckDocument(User $recipientUser, string $title = 'Stuck memo'): Document
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => $title, 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/'.str($title)->slug().'.pdf', 'file_hash' => hash('sha256', $title),
            'status' => DocumentStatus::Completed,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now()->subDay(),
        ]);

        $sameInstant = now()->subHours(2);
        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $recipientUser->id,
            'action' => 'appropriate_action', 'status' => 'completed',
            'acknowledged_at' => $sameInstant, 'acknowledged_by' => $recipientUser->id,
            'completed_at' => $sameInstant, 'completed_by' => $recipientUser->id,
        ]);

        return $document;
    }

    public function test_dry_run_reports_stuck_ord_unit_head_document_without_changing_anything(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->ictu->id]);
        $unitHead->assignRole('unit_head');
        $document = $this->makeStuckDocument($unitHead, 'ICTU stuck memo');

        $this->artisan('documents:fix-premature-routing-completions')
            ->expectsOutputToContain('ICTU stuck memo')
            ->assertExitCode(0);

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
    }

    public function test_apply_reverts_stuck_ord_unit_head_document_to_routed(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->ictu->id]);
        $unitHead->assignRole('unit_head');
        $document = $this->makeStuckDocument($unitHead, 'ICTU stuck memo');
        $recipient = $document->routings->first()->recipients->first();

        $this->artisan('documents:fix-premature-routing-completions', ['--apply' => true])
            ->expectsConfirmation('Revert the 1 document(s) listed above to Routed now?', 'yes')
            ->assertExitCode(0);

        $document->refresh();
        $this->assertSame(DocumentStatus::Routed, $document->status);

        $recipient->refresh();
        $this->assertEquals('acknowledged', $recipient->status->value);
        $this->assertNull($recipient->completed_at);
        $this->assertNull($recipient->completed_by);
        $this->assertNotNull($recipient->acknowledged_at);

        $this->assertTrue($unitHead->can('route', $document));
    }

    public function test_apply_reverts_stuck_division_chief_document_to_routed(): void
    {
        $chief = User::factory()->create(['division_id' => $this->ad->id]);
        $chief->assignRole('division_chief');
        $document = $this->makeStuckDocument($chief, 'AD chief stuck memo');

        $this->artisan('documents:fix-premature-routing-completions', ['--apply' => true])
            ->expectsConfirmation('Revert the 1 document(s) listed above to Routed now?', 'yes')
            ->assertExitCode(0);

        $document->refresh();
        $this->assertSame(DocumentStatus::Routed, $document->status);
    }

    public function test_does_not_touch_an_ordinary_staff_recipient_completing_in_one_step(): void
    {
        $staff = User::factory()->create(['division_id' => $this->ad->id]);
        $staff->assignRole('originator');
        $document = $this->makeStuckDocument($staff, 'Legit staff completion');

        $this->artisan('documents:fix-premature-routing-completions')
            ->expectsOutputToContain('No affected documents found')
            ->assertExitCode(0);

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
    }

    public function test_does_not_touch_a_legitimately_completed_chief_document_with_different_timestamps(): void
    {
        $chief = User::factory()->create(['division_id' => $this->ad->id]);
        $chief->assignRole('division_chief');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Legit chief completion', 'uploaded_by' => $this->rd->id,
            'file_path' => 'incoming/2026/07/legit.pdf', 'file_hash' => hash('sha256', 'legit'),
            'status' => DocumentStatus::Completed,
        ]);
        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->rd->id,
            'is_rush' => false, 'routed_at' => now()->subDays(2),
        ]);
        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $chief->id,
            'action' => 'appropriate_action', 'status' => 'completed',
            'acknowledged_at' => now()->subDays(2), 'acknowledged_by' => $chief->id,
            'completed_at' => now()->subDay(), 'completed_by' => $chief->id,
        ]);

        $this->artisan('documents:fix-premature-routing-completions')
            ->expectsOutputToContain('No affected documents found')
            ->assertExitCode(0);

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
    }

    public function test_does_not_touch_documents_still_routed_or_received(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->ictu->id]);
        $unitHead->assignRole('unit_head');

        $document = $this->makeStuckDocument($unitHead, 'Not actually completed');
        $document->update(['status' => DocumentStatus::Routed]);

        $this->artisan('documents:fix-premature-routing-completions')
            ->expectsOutputToContain('No affected documents found')
            ->assertExitCode(0);
    }
}
