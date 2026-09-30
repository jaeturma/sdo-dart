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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * At submission time, the originator chooses whether an outgoing document
 * should be released by Records Section once signed, or is "for own
 * consumption" — in which case it never enters Records Section's release
 * queue and the uploader is just notified when it's signed.
 */
class RequiresReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private Division $records;
    private User $originator;
    private User $recordsClerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);

        $this->originator = User::factory()->create(['division_id' => $this->ad->id]);
        $this->originator->assignRole(['originator', 'kmt_focal']);

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');
    }

    public function test_requires_release_is_required_when_submitting_an_outgoing_document(): void
    {
        $this->actingAs($this->originator)->post('/documents/outgoing', [
            'title' => 'Missing choice memo',
            'file'  => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
        ])->assertSessionHasErrors('requires_release');

        $this->assertDatabaseMissing('documents', ['title' => 'Missing choice memo']);
    }

    public function test_choosing_yes_persists_requires_release_true(): void
    {
        $this->actingAs($this->originator)->post('/documents/outgoing', [
            'title'            => 'Release via records memo',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => true,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Release via records memo');
        $this->assertTrue($document->requires_release);
    }

    public function test_choosing_no_persists_requires_release_false(): void
    {
        $this->actingAs($this->originator)->post('/documents/outgoing', [
            'title'            => 'Own use memo',
            'file'             => UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-1.4 fake pdf content for tests'),
            'requires_release' => false,
        ])->assertRedirect();

        $document = Document::firstWhere('title', 'Own use memo');
        $this->assertFalse($document->requires_release);
    }

    private function signedDocument(bool $requiresRelease): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Signed memo',
            'uploaded_by' => $this->originator->id, 'division_id' => $this->ad->id,
            'file_path' => 'outgoing/signed.pdf', 'file_hash' => hash('sha256', 'signed'),
            'status' => DocumentStatus::Signed, 'requires_release' => $requiresRelease,
        ]);
    }

    public function test_document_marked_for_release_appears_in_records_sections_queue(): void
    {
        $document = $this->signedDocument(requiresRelease: true);

        $this->assertTrue($this->recordsClerk->can('release', $document));

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.index', ['tab' => 'ready_to_release']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => collect($docs)->pluck('id')->contains($document->id),
            ));
    }

    public function test_document_marked_for_own_use_never_reaches_records_sections_queue(): void
    {
        $document = $this->signedDocument(requiresRelease: false);

        $this->assertFalse($this->recordsClerk->can('release', $document));

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.index', ['tab' => 'ready_to_release']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.release.create', $document))
            ->assertForbidden();
    }
}
