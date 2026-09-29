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
 * The RD/ARD/secretary's routing candidate pool is intentionally broad (ARD
 * + every division chief + ORD unit head), but a crafted request must still
 * be rejected if it targets someone outside that pool entirely, or a
 * deactivated account within it — previously only the narrower
 * division-chief/unit-head branch validated recipients server-side at all;
 * `user_ids.*` alone only checked `exists:users,id`.
 */
class RouteDocumentRequestEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $rd;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');
    }

    private function receivedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Inbound memo',
            'uploaded_by' => $this->rd->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::Received,
        ]);
    }

    public function test_rd_cannot_route_to_a_deactivated_division_chief(): void
    {
        $document = $this->receivedDocument();

        $inactiveChief = User::factory()->create(['division_id' => $this->division->id, 'is_active' => false]);
        $inactiveChief->assignRole('division_chief');

        $this->actingAs($this->rd)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$inactiveChief->id],
            ])
            ->assertSessionHasErrors('user_ids');

        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);
    }

    public function test_rd_cannot_route_to_a_deactivated_ard(): void
    {
        $document = $this->receivedDocument();

        $inactiveArd = User::factory()->create(['is_active' => false]);
        $inactiveArd->assignRole('ard');

        $this->actingAs($this->rd)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$inactiveArd->id],
            ])
            ->assertSessionHasErrors('user_ids');

        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);
    }

    public function test_rd_cannot_route_to_a_plain_staff_member_outside_any_own_staff_pool(): void
    {
        $document = $this->receivedDocument();

        $staff = User::factory()->create(['division_id' => $this->division->id, 'is_active' => true]);
        $staff->assignRole('originator');

        $this->actingAs($this->rd)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$staff->id],
            ])
            ->assertSessionHasErrors('user_ids');

        $this->assertSame(DocumentStatus::Received, $document->fresh()->status);
    }

    public function test_rd_can_still_route_to_an_active_division_chief(): void
    {
        $document = $this->receivedDocument();

        $chief = User::factory()->create(['division_id' => $this->division->id, 'is_active' => true]);
        $chief->assignRole('division_chief');

        $this->actingAs($this->rd)
            ->post(route('documents.routings.store', $document), [
                'action' => 'appropriate_action',
                'user_ids' => [$chief->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DocumentStatus::Routed, $document->fresh()->status);
    }
}
