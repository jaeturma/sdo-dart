<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DocumentPolicy::createOutgoing() has always allowed originator/unit_head/
 * division_chief, but the "+ New Outgoing" button on Documents/Index.vue was
 * gated by a hardcoded client-side hasRole('originator', 'division_chief')
 * check that never included unit_head — so ORD sub-unit heads (ICTU, LU,
 * PAU) never saw the button even though they were always allowed to use it.
 * The button is now driven by a server-computed canCreateOutgoing prop
 * instead, so it can't drift from the policy again.
 */
class CreateOutgoingPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Division $ord;
    private Division $pau;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'unit_head', 'division_chief', 'rd', 'kmt_focal'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ord = Division::create(['name' => 'Office of the Regional Director', 'code' => 'ORD']);
        $this->pau = Division::create(['name' => 'Public Affairs Unit', 'code' => 'PAU', 'parent_id' => $this->ord->id]);
    }

    public function test_pau_unit_head_can_create_outgoing_document(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->pau->id]);
        $unitHead->assignRole('unit_head');

        $this->assertTrue($unitHead->can('createOutgoing', Document::class));

        $this->actingAs($unitHead)
            ->get(route('documents.outgoing.create'))
            ->assertOk();
    }

    public function test_documents_index_shows_new_outgoing_button_for_pau_unit_head(): void
    {
        $unitHead = User::factory()->create(['division_id' => $this->pau->id]);
        $unitHead->assignRole('unit_head');

        $this->actingAs($unitHead)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', true));
    }

    public function test_user_without_a_division_cannot_create_outgoing(): void
    {
        $noDivision = User::factory()->create(['division_id' => null]);
        $noDivision->assignRole('originator');

        $this->assertFalse($noDivision->can('createOutgoing', Document::class));

        $this->actingAs($noDivision)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', false));
    }

    public function test_records_section_originator_can_now_view_my_signature_nav(): void
    {
        $records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'parent_id' => $this->ord->id]);
        $clerk = User::factory()->create(['division_id' => $records->id]);
        $clerk->assignRole('originator');

        $this->actingAs($clerk)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('auth.canUploadSignature', true));
    }

    /**
     * A plain 'originator' (Staff) with a division can no longer create
     * outgoing documents by default — they need the additional 'kmt_focal'
     * role. Covers both the policy directly and the two server-side
     * enforcement points (the create() page load and the "New Outgoing"
     * button visibility).
     */
    public function test_plain_originator_cannot_create_outgoing_without_kmt_focal(): void
    {
        $originator = User::factory()->create(['division_id' => $this->pau->id]);
        $originator->assignRole('originator');

        $this->assertFalse($originator->can('createOutgoing', Document::class));

        $this->actingAs($originator)
            ->get(route('documents.outgoing.create'))
            ->assertForbidden();

        $this->actingAs($originator)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', false));
    }

    /**
     * An originator additionally tagged 'kmt_focal' regains the ability.
     */
    public function test_originator_with_kmt_focal_can_create_outgoing(): void
    {
        $originator = User::factory()->create(['division_id' => $this->pau->id]);
        $originator->assignRole(['originator', 'kmt_focal']);

        $this->assertTrue($originator->can('createOutgoing', Document::class));

        $this->actingAs($originator)
            ->get(route('documents.outgoing.create'))
            ->assertOk();

        $this->actingAs($originator)
            ->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->where('canCreateOutgoing', true));
    }

    /**
     * 'kmt_focal' alone, without the 'originator' role, grants nothing — it
     * only has an effect layered on top of 'originator'.
     */
    public function test_kmt_focal_alone_without_originator_role_grants_nothing(): void
    {
        $user = User::factory()->create(['division_id' => $this->pau->id]);
        $user->assignRole('kmt_focal');

        $this->assertFalse($user->can('createOutgoing', Document::class));
    }
}
