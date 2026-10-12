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
 * The released-documents counterpart of IncomingDocumentLogTest: a
 * printable daily log of every outgoing document released on a given date,
 * from any division, plus release statistics — the same read-only
 * reporting audience (DocumentPolicy::viewReleasedLog()).
 */
class ReleasedDocumentLogTest extends TestCase
{
    use RefreshDatabase;

    private Division $records;

    private Division $ad;

    private Division $clmd;

    private User $recordsClerk;

    private User $originator;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'sds', 'asds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);
        $this->clmd = Division::create(['name' => 'Curriculum and Learning Management Division', 'code' => 'CLMD']);

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');

        $this->originator = User::factory()->create(['division_id' => $this->clmd->id]);
        $this->originator->assignRole('originator');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function releasedDocument(
        string $title,
        ?string $releasedAt,
        ?Division $division = null,
        ?string $emailedAt = null,
        DocumentStatus $status = DocumentStatus::Released,
    ): Document {
        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => $title,
            'uploaded_by' => $this->originator->id, 'division_id' => ($division ?? $this->clmd)->id,
            'file_path' => 'outgoing/'.$title.'.pdf', 'file_hash' => hash('sha256', $title),
            'status' => $status, 'requires_release' => true,
        ]);

        $document->forceFill([
            'released_at' => $releasedAt,
            'released_by' => $releasedAt ? $this->recordsClerk->id : null,
            'emailed_at' => $emailedAt,
            'emailed_by' => $emailedAt ? $this->recordsClerk->id : null,
        ])->save();

        return $document;
    }

    public function test_records_section_sees_documents_released_on_the_selected_date(): void
    {
        $this->releasedDocument('Todays memo', '2026-08-11 09:00:00');
        $this->releasedDocument('Yesterdays memo', '2026-08-10 09:00:00');

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.released-log', ['date' => '2026-08-11']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Documents/ReleasedLog')
                ->where('date', '2026-08-11')
                ->where('documents', fn ($docs) => collect($docs)->pluck('title')->all() === ['Todays memo'])
                ->where('documents.0.released_by.name', $this->recordsClerk->name));
    }

    public function test_statistics_count_emailed_month_year_and_division(): void
    {
        $this->releasedDocument('A', '2026-08-11 08:00:00', $this->clmd, '2026-08-11 10:00:00');
        $this->releasedDocument('B', '2026-08-11 09:00:00', $this->clmd);
        $this->releasedDocument('C', '2026-08-11 11:00:00', $this->ad, '2026-08-12 08:00:00');
        $this->releasedDocument('Same month', '2026-08-02 09:00:00');
        $this->releasedDocument('Same year', '2026-03-15 09:00:00');
        $this->releasedDocument('Last year', '2025-08-11 09:00:00');

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.released-log', ['date' => '2026-08-11']))
            ->assertInertia(fn ($page) => $page
                ->where('stats.released', 3)
                ->where('stats.emailed', 2)
                ->where('stats.awaiting_email', 1)
                ->where('stats.month', 4)
                ->where('stats.year', 5)
                ->where('stats.by_division', fn ($rows) => collect($rows)->all() === [
                    ['division' => $this->clmd->name, 'count' => 2],
                    ['division' => $this->ad->name, 'count' => 1],
                ]));
    }

    public function test_unreleased_reverted_and_incoming_documents_are_excluded(): void
    {
        $this->releasedDocument('Released', '2026-08-11 09:00:00');
        $this->releasedDocument('Signed only', null, status: DocumentStatus::Signed);
        // released_at left behind on a document whose status was later reverted
        $this->releasedDocument('Reverted', '2026-08-11 10:00:00', status: DocumentStatus::Signed);

        $incoming = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Incoming memo',
            'uploaded_by' => $this->recordsClerk->id, 'division_id' => $this->records->id,
            'file_path' => 'incoming/a.pdf', 'file_hash' => hash('sha256', 'in'),
            'status' => DocumentStatus::Released,
        ]);
        $incoming->forceFill(['released_at' => '2026-08-11 11:00:00'])->save();

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.released-log', ['date' => '2026-08-11']))
            ->assertInertia(fn ($page) => $page
                ->where('documents', fn ($docs) => collect($docs)->pluck('title')->all() === ['Released'])
                ->where('stats.month', 1));
    }

    public function test_defaults_to_todays_date_when_no_date_given(): void
    {
        $this->actingAs($this->recordsClerk)
            ->get(route('documents.released-log'))
            ->assertInertia(fn ($page) => $page->where('date', now()->toDateString()));
    }

    public function test_admin_can_view_the_log(): void
    {
        $this->actingAs($this->admin)
            ->get(route('documents.released-log'))
            ->assertOk();
    }

    public function test_non_records_non_admin_user_is_forbidden(): void
    {
        foreach (['sds', 'asds', 'secretary', 'division_chief'] as $role) {
            $user = User::factory()->create(['division_id' => $this->clmd->id]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->get(route('documents.released-log'))
                ->assertForbidden();
        }

        $this->actingAs($this->originator)
            ->get(route('documents.released-log'))
            ->assertForbidden();
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        $this->actingAs($this->recordsClerk)
            ->get(route('documents.released-log', ['date' => 'not-a-date']))
            ->assertSessionHasErrors('date');
    }
}
