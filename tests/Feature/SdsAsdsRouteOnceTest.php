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
 * Once RD/ARD has personally issued a routing round for a document, the
 * "Route Document" action should disappear for them — matches the same
 * one-time rule the division chief branch of DocumentPolicy::route() already
 * enforced, and the "For Routing" queue's own exclusion of self-routed docs.
 */
class RdArdRouteOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $rd;
    private User $ard;
    private User $chief;
    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'division_chief', 'ard', 'rd'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->rd = User::factory()->create();
        $this->rd->assignRole('rd');

        $this->ard = User::factory()->create();
        $this->ard->assignRole('ard');

        $this->division = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->chief = User::factory()->create(['division_id' => $this->division->id]);
        $this->chief->assignRole('division_chief');
    }

    private function receivedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Inbound memo',
            'uploaded_by' => $this->rd->id, 'file_path' => 'incoming/a.pdf',
            'file_hash' => hash('sha256', 'a'), 'status' => DocumentStatus::Received,
        ]);
    }

    public function test_rd_can_route_a_freshly_received_document(): void
    {
        $document = $this->receivedDocument();

        $this->assertTrue($this->rd->can('route', $document));
    }

    public function test_route_button_disappears_for_rd_after_rd_routes_it(): void
    {
        $document = $this->receivedDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::Routed, $document->status);
        $this->assertFalse($this->rd->can('route', $document));

        // Attempting to hit the routing form/store routes directly is also forbidden now.
        $this->actingAs($this->rd)
            ->get(route('documents.routings.create', $document))
            ->assertForbidden();
    }

    public function test_route_button_still_available_for_ard_after_only_rd_has_routed_it(): void
    {
        $document = $this->receivedDocument();

        $this->actingAs($this->rd)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->chief->id],
            ])
            ->assertRedirect();

        // A different RD/ARD-role user hasn't personally routed this document
        // yet, so they can still issue their own routing round for it.
        $this->assertTrue($this->ard->can('route', $document->fresh()));
    }
}
