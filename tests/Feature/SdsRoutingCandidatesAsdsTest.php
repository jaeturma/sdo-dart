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
 * The ASDS should be offered as a routing recipient alongside division chiefs
 * and OSDS unit heads (previously she wasn't a candidate at all), and listed
 * first since she outranks everyone else in that list.
 */
class SdsRoutingCandidatesAsdsTest extends TestCase
{
    use RefreshDatabase;

    private User $sds;

    private User $asds;

    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'asds', 'sds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $osdsDivision = Division::create(['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS']);

        $this->sds = User::factory()->create(['division_id' => $osdsDivision->id]);
        $this->sds->assignRole('sds');

        $this->asds = User::factory()->create(['division_id' => $osdsDivision->id]);
        $this->asds->assignRole('asds');

        $chiefDivision = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->chief = User::factory()->create(['division_id' => $chiefDivision->id]);
        $this->chief->assignRole('division_chief');
    }

    private function receivedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Inbound memo',
            'uploaded_by' => $this->sds->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::Received,
        ]);
    }

    public function test_asds_is_offered_as_a_recipient_and_listed_first(): void
    {
        $document = $this->receivedDocument();

        $this->actingAs($this->sds)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page
                ->where('users.0.id', $this->asds->id)
                ->where('users.0.role', 'asds')
                ->where('users', fn ($users) => collect($users)->pluck('id')->contains($this->chief->id)));
    }

    public function test_asds_does_not_see_herself_as_a_recipient(): void
    {
        $document = $this->receivedDocument();

        $response = $this->actingAs($this->asds)
            ->get(route('documents.routings.candidates', $document))
            ->assertOk();

        $ids = collect($response->json('users'))->pluck('id');
        $this->assertFalse($ids->contains($this->asds->id));
        $this->assertTrue($ids->contains($this->chief->id));
    }
}
