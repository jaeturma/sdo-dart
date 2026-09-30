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
 * Real reported bug: the SDS's secretary routes an incoming document to a
 * division chief, cancels that routing before the chief acts (e.g. wrong
 * recipient picked), then reroutes it to the SAME chief again. The chief
 * then can't route it to their own staff at all — DocumentController::show()
 * computed `recipientAssignment` via a plain ->first() across every routing
 * ever issued for the document, with no ordering and no exclusion of
 * cancelled routings, so it kept matching the chief's recipient row on the
 * now-defunct CANCELLED routing instead of their real, active, still-pending
 * one on the new routing round. Show.vue then rendered "✓ Completed" for an
 * assignment that no longer exists, hiding the Acknowledge/Complete/Route UI
 * for the chief's actual pending assignment entirely.
 */
class RecipientAssignmentAfterCancelRerouteTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private User $sds;
    private User $chief;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator', 'division_chief', 'sds'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->chief = User::factory()->create(['division_id' => $this->ad->id]);
        $this->chief->assignRole('division_chief');

        $this->staff = User::factory()->create(['division_id' => $this->ad->id]);
        $this->staff->assignRole('originator');
    }

    public function test_chief_sees_the_new_pending_assignment_not_the_cancelled_one_after_reroute(): void
    {
        $document = Document::create([
            'type' => DocumentType::Incoming, 'title' => 'Test memo', 'uploaded_by' => $this->sds->id,
            'file_path' => 'incoming/2026/07/memo.pdf', 'file_hash' => hash('sha256', 'memo'),
            'status' => DocumentStatus::Received,
        ]);

        // First routing to the chief, cancelled before she acts on it.
        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $firstRouting = $document->routings->first();

        $this->actingAs($this->sds)
            ->delete(route('documents.routings.cancel', [$document, $firstRouting]))
            ->assertRedirect();

        // Rerouted to the same chief.
        $this->actingAs($this->sds)->post(route('documents.routings.store', $document), [
            'action'   => 'appropriate_action',
            'user_ids' => [$this->chief->id],
        ]);

        $document->refresh()->load('routings.recipients');
        $secondRouting = $document->routings->last();
        $activeRecipient = $secondRouting->recipients->firstWhere('user_id', $this->chief->id);

        $this->actingAs($this->chief)
            ->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page
                ->where('recipientAssignment.id', $activeRecipient->id)
                ->where('recipientAssignment.status', 'pending'));

        // She can acknowledge the NEW assignment...
        $this->actingAs($this->chief)
            ->patch(route('routing-recipients.acknowledge', $activeRecipient))
            ->assertRedirect();

        // ...and then route it to her own staff, exactly the ability the
        // bug report said was missing.
        $this->assertTrue($this->chief->fresh()->can('route', $document->fresh()));

        $this->actingAs($this->chief)
            ->post(route('documents.routings.store', $document), [
                'action'   => 'appropriate_action',
                'user_ids' => [$this->staff->id],
            ])
            ->assertRedirect();

        $document->refresh()->load('routings.recipients');
        $this->assertTrue($document->routings->last()->recipients->contains(fn ($r) => $r->user_id === $this->staff->id));
    }
}
