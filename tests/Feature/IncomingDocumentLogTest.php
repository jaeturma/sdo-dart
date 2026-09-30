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

/**
 * A printable daily log of every incoming document uploaded on a given
 * date, regardless of who uploaded it — Records Section's normal document
 * visibility (DocumentScopesTrait::incomingScope()) is much narrower (only
 * their own uploads, routed-to-them documents, or same-division uploads),
 * so this is a deliberately broader, read-only reporting grant scoped to
 * DocumentPolicy::viewIncomingLog() alone.
 */
class IncomingDocumentLogTest extends TestCase
{
    use RefreshDatabase;

    private Division $records;
    private Division $ad;
    private Division $osds;
    private User $recordsClerk;
    private User $admin;
    private User $sds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'sds', 'asds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->osds = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);
        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->sds = User::factory()->create(['division_id' => $this->osds->id]);
        $this->sds->assignRole('sds');
    }

    private function incomingDocument(string $title, User $uploader, string $createdAt): Document
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => $title,
            'uploaded_by' => $uploader->id, 'division_id' => $uploader->division_id,
            'file_path' => 'incoming/'.$title.'.pdf', 'file_hash' => hash('sha256', $title),
            'status' => DocumentStatus::Received,
        ]);

        $document->forceFill(['created_at' => $createdAt])->save();

        return $document;
    }

    public function test_records_section_sees_the_log_for_the_selected_date(): void
    {
        $this->incomingDocument('Todays memo', $this->recordsClerk, '2026-08-11 09:00:00');
        $this->incomingDocument('Yesterdays memo', $this->recordsClerk, '2026-08-10 09:00:00');

        $response = $this->actingAs($this->recordsClerk)
            ->get(route('documents.incoming-log', ['date' => '2026-08-11']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('date', '2026-08-11')
            ->where('documents', fn ($docs) => collect($docs)->pluck('title')->all() === ['Todays memo']));
    }

    public function test_log_includes_documents_uploaded_by_anyone_not_just_records_section(): void
    {
        // Uploaded by the SDS, not Records Section — normal incomingScope()
        // visibility for a records clerk wouldn't include this at all.
        $this->incomingDocument('SDS uploaded memo', $this->sds, '2026-08-11 08:00:00');

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.incoming-log', ['date' => '2026-08-11']))
            ->assertInertia(fn ($page) => $page->where(
                'documents',
                fn ($docs) => collect($docs)->pluck('title')->contains('SDS uploaded memo'),
            ));
    }

    public function test_defaults_to_todays_date_when_no_date_given(): void
    {
        $this->actingAs($this->recordsClerk)
            ->get(route('documents.incoming-log'))
            ->assertInertia(fn ($page) => $page->where('date', now()->toDateString()));
    }

    public function test_admin_can_view_the_log(): void
    {
        $this->actingAs($this->admin)
            ->get(route('documents.incoming-log'))
            ->assertOk();
    }

    public function test_non_records_non_admin_user_is_forbidden(): void
    {
        foreach (['sds', 'asds', 'secretary'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user)
                ->get(route('documents.incoming-log'))
                ->assertForbidden();
        }
    }

    public function test_outgoing_documents_are_excluded(): void
    {
        Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Outgoing memo',
            'uploaded_by' => $this->sds->id, 'division_id' => $this->osds->id,
            'file_path' => 'outgoing/a.pdf', 'file_hash' => hash('sha256', 'out'),
            'status' => DocumentStatus::Draft,
        ]);
        $this->incomingDocument('Incoming memo', $this->recordsClerk, now()->toDateString().' 10:00:00');

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.incoming-log'))
            ->assertInertia(fn ($page) => $page->where(
                'documents',
                fn ($docs) => collect($docs)->pluck('title')->all() === ['Incoming memo'],
            ));
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        $this->actingAs($this->recordsClerk)
            ->get(route('documents.incoming-log', ['date' => 'not-a-date']))
            ->assertSessionHasErrors('date');
    }
}
