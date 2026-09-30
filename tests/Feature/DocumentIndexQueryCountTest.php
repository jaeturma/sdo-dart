<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRouting;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression guard for the N+1 fix in DocumentController::index()'s
 * ->through() closure — DocumentPolicy::review()/route() used to fire a
 * fresh query per row (per document) to check can_review/can_route. Asserts
 * the query count for a full page of routed incoming documents stays low
 * and bounded, rather than growing with the number of rows on the page.
 */
class DocumentIndexQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private User $sds;

    private User $chief;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'division_chief', 'sds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->chief = User::factory()->create(['division_id' => $this->division->id]);
        $this->chief->assignRole('division_chief');
    }

    private function routedIncomingDocument(int $n): Document
    {
        $uploader = User::factory()->create(['division_id' => $this->division->id]);
        $uploader->assignRole('originator');

        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => "Memo {$n}", 'uploaded_by' => $uploader->id,
            'file_path' => "incoming/{$n}.pdf", 'file_hash' => hash('sha256', (string) $n),
            'status' => DocumentStatus::Routed,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->sds->id,
            'is_rush' => false, 'routed_at' => now(), 'is_sds_routing' => true,
        ]);

        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $this->chief->id,
            'action' => 'appropriate_action', 'status' => 'pending',
        ]);

        return $document;
    }

    public function test_query_count_for_a_full_page_of_routed_documents_stays_bounded(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->routedIncomingDocument($i);
        }

        DB::enableQueryLog();

        $this->actingAs($this->sds)
            ->get(route('documents.index', ['tab' => 'incoming']))
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Before the fix, DocumentPolicy::route() fired one exists() query
        // per row for the SDS (20 rows -> 20 extra queries on top of
        // everything else the page needs) — a regression back to that
        // would blow well past this cap.
        $this->assertLessThan(20, $queryCount, 'Query count for the Documents index page grew with the number of rows — check for a reintroduced N+1 in DocumentPolicy::review()/route().');
    }
}
