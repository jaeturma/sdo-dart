<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\User;
use App\Models\UserSigningCredential;
use App\Notifications\DocumentEvent;
use App\Services\DocumentReleaseStamper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The real PDF/crypto mechanics of DocumentReleaseStamper are not exercised
 * here — same testing philosophy already established for DocumentSigner in
 * this codebase (see NotificationDispatchTest's comment: the full HTTP
 * signing flow needs a real PKCS#12 file and PDF, so it's verified manually,
 * not in CI). These tests fake the stamper and verify the surrounding
 * wiring: authorization, status transition, review row, audit log,
 * notification, and redirect.
 */
class ReleaseStampTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;
    private Division $records;
    private User $recordsClerk;
    private User $outsider;
    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['admin', 'originator'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');

        $this->outsider = User::factory()->create(['division_id' => $this->ad->id]);
        $this->outsider->assignRole('originator');

        $this->uploader = User::factory()->create(['division_id' => $this->ad->id]);
        $this->uploader->assignRole('originator');
    }

    private function signedDocument(): Document
    {
        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Signed memo', 'uploaded_by' => $this->uploader->id,
            'division_id' => $this->ad->id, 'file_path' => 'outgoing/signed.pdf',
            'file_hash' => hash('sha256', 'signed'), 'status' => DocumentStatus::Signed,
        ]);
    }

    private function giveCredential(User $user): UserSigningCredential
    {
        return UserSigningCredential::create([
            'user_id'             => $user->id,
            'p12_path'            => "p12/{$user->id}/test.p12",
            'p12_hash'            => hash('sha256', 'p12'),
            'esig_path'           => "esig/{$user->id}/test.png",
            'esig_hash'           => hash('sha256', 'esig'),
            'certificate_subject' => 'CN=' . $user->name,
            'certificate_serial'  => '12345',
        ]);
    }

    public function test_records_section_user_with_credential_can_view_placement_page(): void
    {
        $this->giveCredential($this->recordsClerk);
        $document = $this->signedDocument();

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.release.create', $document))
            ->assertOk();
    }

    public function test_records_section_user_without_credential_is_redirected_with_a_hint(): void
    {
        $document = $this->signedDocument();

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.release.create', $document))
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('error');
    }

    public function test_non_records_section_user_cannot_view_placement_page(): void
    {
        $this->giveCredential($this->outsider);
        $document = $this->signedDocument();

        $this->actingAs($this->outsider)
            ->get(route('documents.release.create', $document))
            ->assertForbidden();
    }

    public function test_wrong_document_status_is_forbidden(): void
    {
        $this->giveCredential($this->recordsClerk);
        $document = $this->signedDocument();
        $document->update(['status' => DocumentStatus::Approved]);

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.release.create', $document))
            ->assertForbidden();
    }

    public function test_storing_a_release_stamp_transitions_status_and_records_everything(): void
    {
        Notification::fake();
        Storage::fake('documents');

        $this->giveCredential($this->recordsClerk);
        $document = $this->signedDocument();

        $this->mock(DocumentReleaseStamper::class, function ($mock) {
            $mock->shouldReceive('stamp')
                ->once()
                ->andReturnUsing(function () {
                    Storage::disk('documents')->put('released/fake.pdf', 'fake stamped content');

                    return 'released/fake.pdf';
                });
        });

        $this->actingAs($this->recordsClerk)
            ->post(route('documents.release.store', $document), [
                'p12_password'        => 'secret',
                'stamp_page'          => 1,
                'stamp_x_percent'     => 30,
                'stamp_y_percent'     => 70,
                'stamp_width_percent' => 20,
            ])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Released, $document->status);
        $this->assertNotNull($document->released_at);
        $this->assertSame($this->recordsClerk->id, $document->released_by);

        $this->assertDatabaseHas('document_release_stamps', [
            'document_id'       => $document->id,
            'stamped_by'        => $this->recordsClerk->id,
            'stamped_file_path' => 'released/fake.pdf',
        ]);

        $this->assertDatabaseHas('document_reviews', [
            'document_id'    => $document->id,
            'reviewer_id'    => $this->recordsClerk->id,
            'role_at_review' => 'records_section',
            'action'         => 'released',
        ]);

        Notification::assertSentTo($this->uploader, DocumentEvent::class);
    }

    public function test_cannot_store_without_a_credential(): void
    {
        $document = $this->signedDocument();

        $this->actingAs($this->recordsClerk)
            ->post(route('documents.release.store', $document), [
                'p12_password'        => 'secret',
                'stamp_page'          => 1,
                'stamp_x_percent'     => 30,
                'stamp_y_percent'     => 70,
                'stamp_width_percent' => 20,
            ])
            ->assertSessionHasErrors('p12_password');

        $this->assertSame(DocumentStatus::Signed, $document->fresh()->status);
    }

    public function test_non_records_section_user_cannot_store(): void
    {
        $this->giveCredential($this->outsider);
        $document = $this->signedDocument();

        $this->actingAs($this->outsider)
            ->post(route('documents.release.store', $document), [
                'p12_password'        => 'secret',
                'stamp_page'          => 1,
                'stamp_x_percent'     => 30,
                'stamp_y_percent'     => 70,
                'stamp_width_percent' => 20,
            ])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::Signed, $document->fresh()->status);
    }

    public function test_admin_can_release_with_their_own_credential(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->giveCredential($admin);

        Storage::fake('documents');
        $document = $this->signedDocument();

        $this->mock(DocumentReleaseStamper::class, function ($mock) {
            $mock->shouldReceive('stamp')->once()->andReturnUsing(function () {
                Storage::disk('documents')->put('released/admin.pdf', 'fake');

                return 'released/admin.pdf';
            });
        });

        $this->actingAs($admin)
            ->post(route('documents.release.store', $document), [
                'p12_password'        => 'secret',
                'stamp_page'          => 1,
                'stamp_x_percent'     => 30,
                'stamp_y_percent'     => 70,
                'stamp_width_percent' => 20,
            ])
            ->assertRedirect(route('documents.show', $document));

        $this->assertSame(DocumentStatus::Released, $document->fresh()->status);
    }

    public function test_released_document_no_longer_appears_in_ready_to_release(): void
    {
        Notification::fake();
        Storage::fake('documents');

        $this->giveCredential($this->recordsClerk);
        $document = $this->signedDocument();

        $this->mock(DocumentReleaseStamper::class, function ($mock) {
            $mock->shouldReceive('stamp')->once()->andReturnUsing(function () {
                Storage::disk('documents')->put('released/fake2.pdf', 'fake');

                return 'released/fake2.pdf';
            });
        });

        $this->actingAs($this->recordsClerk)->post(route('documents.release.store', $document), [
            'p12_password'        => 'secret',
            'stamp_page'          => 1,
            'stamp_x_percent'     => 30,
            'stamp_y_percent'     => 70,
            'stamp_width_percent' => 20,
        ]);

        $this->actingAs($this->recordsClerk)
            ->get(route('documents.index', ['tab' => 'ready_to_release']))
            ->assertInertia(fn ($page) => $page->where(
                'documents.data',
                fn ($docs) => ! collect($docs)->pluck('id')->contains($document->id),
            ));
    }
}
