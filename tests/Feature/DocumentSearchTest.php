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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_documents_by_title_reference_and_uploader(): void
    {
        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);
        Role::firstOrCreate(['name' => 'rd', 'guard_name' => 'web']);

        $rd = User::factory()->create();
        $rd->assignRole('rd');

        $uploaderA = User::factory()->create(['name' => 'Jane Uploader']);
        $uploaderB = User::factory()->create(['name' => 'Someone Else']);

        Document::create([
            'type'        => DocumentType::Outgoing,
            'title'       => 'Brigada Eskwela Memo',
            'uploaded_by' => $uploaderA->id,
            'file_path'   => 'outgoing/a.pdf',
            'file_hash'   => hash('sha256', 'a'),
            // RD's Outgoing tab only shows documents that have cleared unit
            // head/division chief review (see DocumentScopesTrait::outgoingScope()).
            'status'      => DocumentStatus::PendingRdReview,
        ]);
        Document::create([
            'type'             => DocumentType::Incoming,
            'title'            => 'Unrelated memo',
            'uploaded_by'      => $uploaderB->id,
            'source_origin'    => 'Central Office',
            'reference_number' => 'REF-2026-099',
            'file_path'        => 'incoming/b.pdf',
            'file_hash'        => hash('sha256', 'b'),
            'status'           => DocumentStatus::Received,
        ]);

        $byTitle = $this->actingAs($rd)->get('/documents?tab=outgoing&search=Brigada');
        $byTitle->assertOk();
        $byTitle->assertInertia(fn ($page) => $page
            ->has('documents.data', 1)
            ->where('documents.data.0.title', 'Brigada Eskwela Memo'));

        $byReference = $this->actingAs($rd)->get('/documents?tab=incoming&search=REF-2026-099');
        $byReference->assertInertia(fn ($page) => $page
            ->has('documents.data', 1)
            ->where('documents.data.0.title', 'Unrelated memo'));

        $byUploader = $this->actingAs($rd)->get('/documents?tab=outgoing&search=Jane');
        $byUploader->assertInertia(fn ($page) => $page->has('documents.data', 1));

        $noMatch = $this->actingAs($rd)->get('/documents?tab=outgoing&search=nonexistent-term');
        $noMatch->assertInertia(fn ($page) => $page->has('documents.data', 0));
    }
}
