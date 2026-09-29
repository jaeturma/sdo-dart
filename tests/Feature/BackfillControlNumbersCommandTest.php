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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackfillControlNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    private Division $clmd;
    private User $originator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->clmd = Division::create(['name' => 'Curriculum & Learning Management Division', 'code' => 'CLMD']);
        $this->originator = User::factory()->create(['division_id' => $this->clmd->id]);
        $this->originator->assignRole(['originator', 'kmt_focal']);
    }

    private function makeDocument(string $title, string $createdAt, ?Division $division = null): Document
    {
        $document = Document::create([
            'type'        => DocumentType::Outgoing,
            'title'       => $title,
            'uploaded_by' => $this->originator->id,
            'division_id' => ($division ?? $this->clmd)->id,
            'file_path'   => 'outgoing/legacy/'.str($title)->slug().'.pdf',
            'file_hash'   => hash('sha256', $title),
            'status'      => DocumentStatus::Draft,
        ]);

        // created_at isn't mass-assignable — set it directly, bypassing the model.
        DB::table('documents')->where('id', $document->id)->update(['created_at' => $createdAt]);

        return $document->fresh();
    }

    public function test_dry_run_reports_expected_numbers_without_writing_anything(): void
    {
        $doc = $this->makeDocument('Legacy memo', '2026-03-10 09:00:00');

        $this->artisan('documents:backfill-control-numbers')
            ->expectsOutputToContain('OUTGOING-CLMD-2026-03-001')
            ->assertExitCode(0);

        $doc->refresh();
        $this->assertNull($doc->control_number);
    }

    public function test_apply_assigns_numbers_in_created_at_order_not_id_order(): void
    {
        // Create out of chronological order: doc B has an earlier id but a LATER created_at.
        $docB = $this->makeDocument('Second in time', '2026-03-15 09:00:00');
        $docA = $this->makeDocument('First in time', '2026-03-01 09:00:00');

        $this->artisan('documents:backfill-control-numbers', ['--apply' => true])
            ->expectsConfirmation('Assign control numbers to 2 document(s) now?', 'yes')
            ->assertExitCode(0);

        $docA->refresh();
        $docB->refresh();

        $this->assertSame('OUTGOING-CLMD-2026-03-001', $docA->control_number);
        $this->assertSame('OUTGOING-CLMD-2026-03-002', $docB->control_number);
    }

    public function test_apply_groups_by_division_and_year_separately(): void
    {
        $essd = Division::create(['name' => 'Education Support Services Division', 'code' => 'ESSD']);

        $clmd2026 = $this->makeDocument('CLMD 2026', '2026-03-01 09:00:00');
        $essd2026 = $this->makeDocument('ESSD 2026', '2026-03-01 09:00:00', $essd);
        $clmd2025 = $this->makeDocument('CLMD 2025', '2025-11-01 09:00:00');

        $this->artisan('documents:backfill-control-numbers', ['--apply' => true])
            ->expectsConfirmation('Assign control numbers to 3 document(s) now?', 'yes')
            ->assertExitCode(0);

        $this->assertSame('OUTGOING-CLMD-2026-03-001', $clmd2026->fresh()->control_number);
        $this->assertSame('OUTGOING-ESSD-2026-03-001', $essd2026->fresh()->control_number);
        $this->assertSame('OUTGOING-CLMD-2025-11-001', $clmd2025->fresh()->control_number);
    }

    public function test_apply_resolves_parent_division_code_for_units_without_their_own_code(): void
    {
        $ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $legalUnit = Division::create(['name' => 'Legal Unit', 'code' => null, 'parent_id' => $ord->id]);

        $doc = $this->makeDocument('Legal unit legacy memo', '2026-03-01 09:00:00', $legalUnit);

        $this->artisan('documents:backfill-control-numbers', ['--apply' => true])
            ->expectsConfirmation('Assign control numbers to 1 document(s) now?', 'yes')
            ->assertExitCode(0);

        $this->assertSame('OUTGOING-ORD-2026-03-001', $doc->fresh()->control_number);
    }

    public function test_apply_does_not_touch_documents_that_already_have_a_control_number(): void
    {
        $alreadyNumbered = $this->makeDocument('Already numbered', '2026-03-01 09:00:00');
        $alreadyNumbered->update(['control_number' => 'OUTGOING-CLMD-2026-03-999']);

        $needsNumber = $this->makeDocument('Needs a number', '2026-03-05 09:00:00');

        $this->artisan('documents:backfill-control-numbers')
            ->expectsOutputToContain('Needs a number')
            ->assertExitCode(0);

        $this->artisan('documents:backfill-control-numbers', ['--apply' => true])
            ->expectsConfirmation('Assign control numbers to 1 document(s) now?', 'yes')
            ->assertExitCode(0);

        $this->assertSame('OUTGOING-CLMD-2026-03-999', $alreadyNumbered->fresh()->control_number);
        $this->assertSame('OUTGOING-CLMD-2026-03-001', $needsNumber->fresh()->control_number);
    }

    public function test_new_document_created_after_backfill_continues_the_sequence(): void
    {
        $this->makeDocument('Legacy one', '2026-03-01 09:00:00');
        $this->makeDocument('Legacy two', '2026-03-10 09:00:00');

        $this->artisan('documents:backfill-control-numbers', ['--apply' => true])
            ->expectsConfirmation('Assign control numbers to 2 document(s) now?', 'yes')
            ->assertExitCode(0);

        $this->actingAs($this->originator)->post('/documents/outgoing', [
            'title'            => 'Brand new memo',
            'file'             => UploadedFile::fake()->createWithContent('new.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $newDoc = Document::firstWhere('title', 'Brand new memo');
        $this->assertStringEndsWith('-003', $newDoc->control_number);
        $this->assertStringStartsWith('OUTGOING-CLMD-', $newDoc->control_number);
    }

    public function test_dry_run_prediction_matches_what_apply_actually_assigns(): void
    {
        $doc1 = $this->makeDocument('Preview one', '2026-03-01 09:00:00');
        $doc2 = $this->makeDocument('Preview two', '2026-03-10 09:00:00');

        $this->artisan('documents:backfill-control-numbers')
            ->expectsOutputToContain('OUTGOING-CLMD-2026-03-001')
            ->expectsOutputToContain('OUTGOING-CLMD-2026-03-002')
            ->assertExitCode(0);

        $this->artisan('documents:backfill-control-numbers', ['--apply' => true])
            ->expectsConfirmation('Assign control numbers to 2 document(s) now?', 'yes')
            ->assertExitCode(0);

        $this->assertSame('OUTGOING-CLMD-2026-03-001', $doc1->fresh()->control_number);
        $this->assertSame('OUTGOING-CLMD-2026-03-002', $doc2->fresh()->control_number);
    }
}
