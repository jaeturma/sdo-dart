<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use App\Services\ControlNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * documents:reset-test-data is run once between a trial period and go-live.
 */
class ResetDocumentTestDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_go_live_reset_clears_documents_and_restarts_control_numbers(): void
    {
        Storage::fake('documents');

        $cid = Division::create(['name' => 'Curriculum Implementation Division', 'code' => 'CID']);
        $user = User::factory()->create(['division_id' => $cid->id]);

        Document::create([
            'type' => 'outgoing',
            'title' => 'Trial memo',
            'uploaded_by' => $user->id,
            'division_id' => $cid->id,
            'control_number' => ControlNumberGenerator::forOutgoing($cid),
            'file_path' => 'outgoing/trial.pdf',
            'file_hash' => hash('sha256', 'trial'),
            'status' => 'submitted',
        ]);

        $this->artisan('documents:reset-test-data', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, Document::withTrashed()->count());
        $this->assertDatabaseCount('document_control_number_counters', 0);
        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(Division::find($cid->id));

        $this->assertStringEndsWith('-001', ControlNumberGenerator::forOutgoing($cid));
    }
}
