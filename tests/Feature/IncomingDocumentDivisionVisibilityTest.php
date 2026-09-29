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

class IncomingDocumentDivisionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;
    private Division $ordp;
    private Division $unrelated;
    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->ordp = Division::create(['name' => 'ORD Proper', 'code' => 'ORDP', 'parent_id' => $this->ord->id]);
        $this->unrelated = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->uploader = User::factory()->create(['division_id' => $this->ordp->id]);
        $this->uploader->assignRole('secretary');
    }

    private function makeIncomingDocument(string $title = 'Field report'): Document
    {
        return Document::create([
            'type'          => DocumentType::Incoming,
            'title'         => $title,
            'uploaded_by'   => $this->uploader->id,
            'source_origin' => 'External Office',
            'file_path'     => 'incoming/2026/07/report.pdf',
            'file_hash'     => hash('sha256', $title),
            'status'        => DocumentStatus::Received,
        ]);
    }

    public function test_a_colleague_in_the_same_division_sees_a_document_they_did_not_upload(): void
    {
        $document = $this->makeIncomingDocument();

        $colleague = User::factory()->create(['division_id' => $this->ordp->id]);
        $colleague->assignRole('originator');

        $this->assertTrue($colleague->can('view', $document));
        $this->assertContains($document->id, $this->incomingScopeIds($colleague));
        $this->assertContains($document->id, $this->visibleScopeIds($colleague));

        $this->actingAs($colleague)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_a_user_in_the_parent_division_sees_a_document_uploaded_by_a_child_division_member(): void
    {
        $document = $this->makeIncomingDocument();

        $parentDivisionUser = User::factory()->create(['division_id' => $this->ord->id]);
        $parentDivisionUser->assignRole('unit_head');

        $this->assertTrue($parentDivisionUser->can('view', $document));
        $this->assertContains($document->id, $this->incomingScopeIds($parentDivisionUser));

        $this->actingAs($parentDivisionUser)
            ->get(route('documents.show', $document))
            ->assertOk();
    }

    public function test_a_user_in_an_unrelated_division_cannot_see_the_document(): void
    {
        $document = $this->makeIncomingDocument();

        $outsider = User::factory()->create(['division_id' => $this->unrelated->id]);
        $outsider->assignRole('originator');

        $this->assertFalse($outsider->can('view', $document));
        $this->assertNotContains($document->id, $this->incomingScopeIds($outsider));
        $this->assertNotContains($document->id, $this->visibleScopeIds($outsider));

        $this->actingAs($outsider)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_a_user_with_no_division_only_sees_their_own_uploads_or_assignments(): void
    {
        $document = $this->makeIncomingDocument();

        $divisionless = User::factory()->create(['division_id' => null]);
        $divisionless->assignRole('originator');

        $this->assertFalse($divisionless->can('view', $document));
        $this->assertNotContains($document->id, $this->incomingScopeIds($divisionless));
    }

    /** @return array<int> */
    private function incomingScopeIds(User $user): array
    {
        return (new class {
            use \App\Traits\DocumentScopesTrait {
                incomingScope as public;
            }
        })->incomingScope(Document::query(), $user)->pluck('id')->all();
    }

    /** @return array<int> */
    private function visibleScopeIds(User $user): array
    {
        return (new class {
            use \App\Traits\DocumentScopesTrait {
                visibleScope as public;
            }
        })->visibleScope(Document::query(), $user)->pluck('id')->all();
    }
}
