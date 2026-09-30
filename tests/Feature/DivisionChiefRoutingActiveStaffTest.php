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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A division chief routing to their own staff should only be offered staff
 * whose account is currently active — a deactivated user can't log in to
 * act on the assignment anyway.
 */
class DivisionChiefRoutingActiveStaffTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private User $sds;
    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'sds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->chief = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chief->assignRole('division_chief');
    }

    private function routeDocumentToChief(): Document
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Test memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/07/memo.pdf', 'file_hash' => hash('sha256', 'memo'),
            'status' => DocumentStatus::Received,
        ]);

        $routing = DocumentRouting::create([
            'document_id' => $document->id, 'routed_by' => $this->sds->id,
            'is_rush' => false, 'routed_at' => now(),
        ]);

        DocumentRoutingRecipient::create([
            'routing_id' => $routing->id, 'user_id' => $this->chief->id,
            'action' => 'appropriate_action', 'status' => 'acknowledged',
            'acknowledged_at' => now(), 'acknowledged_by' => $this->chief->id,
        ]);

        $document->update(['status' => DocumentStatus::Routed]);

        return $document;
    }

    public function test_deactivated_staff_are_excluded_from_the_routing_form(): void
    {
        $document = $this->routeDocumentToChief();

        $activeStaff = User::factory()->create(['division_id' => $this->ad->id, 'is_active' => true]);
        $activeStaff->assignRole('originator');

        $inactiveStaff = User::factory()->create(['division_id' => $this->ad->id, 'is_active' => false]);
        $inactiveStaff->assignRole('originator');

        $this->actingAs($this->chief)
            ->get(route('documents.routings.create', $document))
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => collect($users)->pluck('id')->contains($activeStaff->id)
                    && ! collect($users)->pluck('id')->contains($inactiveStaff->id)));
    }

    public function test_deactivated_staff_are_excluded_from_the_candidates_json_endpoint(): void
    {
        $document = $this->routeDocumentToChief();

        $activeStaff = User::factory()->create(['division_id' => $this->ad->id, 'is_active' => true]);
        $activeStaff->assignRole('unit_head');

        $inactiveStaff = User::factory()->create(['division_id' => $this->ad->id, 'is_active' => false]);
        $inactiveStaff->assignRole('unit_head');

        $response = $this->actingAs($this->chief)
            ->get(route('documents.routings.candidates', $document))
            ->assertOk();

        $ids = collect($response->json('users'))->pluck('id');
        $this->assertTrue($ids->contains($activeStaff->id));
        $this->assertFalse($ids->contains($inactiveStaff->id));
    }
}
