<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lets the secretary correct a signature mistake (e.g. wrong placement)
 * without needing an admin to manually revert the status in the database —
 * reverts Signed back to Approved so she can sign again through the normal
 * flow. The previous DocumentSignature row is left in place (append-only
 * audit trail); Document::signature() already resolves to the newest one.
 */
class UndoSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $secretary;
    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->secretary = User::factory()->create();
        $this->secretary->assignRole('secretary');

        $this->uploader = User::factory()->create();
        $this->uploader->assignRole('originator');
    }

    private function signedDocument(): Document
    {
        Storage::fake('documents');

        $document = Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Wrongly signed memo',
            'uploaded_by' => $this->uploader->id, 'file_path' => 'outgoing/original.pdf',
            'file_hash' => hash('sha256', 'x'), 'status' => DocumentStatus::Signed,
        ]);

        DocumentSignature::create([
            'document_id' => $document->id, 'signed_by' => $this->secretary->id,
            'signed_file_path' => 'outgoing/signed.pdf', 'signed_file_hash' => hash('sha256', 'signed'),
            'certificate_subject' => 'CN=Test', 'certificate_serial' => '123',
            'signature_page' => 1, 'sig_x_percent' => 5, 'sig_y_percent' => 5, 'sig_width_percent' => 20,
            'signed_at' => now(),
        ]);

        return $document;
    }

    public function test_secretary_can_undo_a_signature_to_correct_a_mistake(): void
    {
        $document = $this->signedDocument();

        $sds = User::factory()->create();
        $sds->assignRole('sds');
        \App\Models\UserSigningCredential::create([
            'user_id'  => $sds->id,
            'p12_path' => "p12/{$sds->id}/test.p12",
            'p12_hash' => hash('sha256', 'p12'),
        ]);

        $this->assertTrue($this->secretary->can('undoSignature', $document));

        $this->actingAs($this->secretary)
            ->delete(route('documents.sign.destroy', $document), ['remarks' => 'Placed on the wrong page.'])
            ->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);

        // Old signature row stays on record — never deleted.
        $this->assertDatabaseHas('document_signatures', ['document_id' => $document->id]);

        // She can now sign it again through the normal flow.
        $this->assertTrue($this->secretary->can('sign', $document));
    }

    public function test_cannot_undo_a_signature_once_the_document_is_released(): void
    {
        $document = $this->signedDocument();
        $document->update(['status' => DocumentStatus::Released]);

        $this->assertFalse($this->secretary->can('undoSignature', $document));

        $this->actingAs($this->secretary)
            ->delete(route('documents.sign.destroy', $document))
            ->assertForbidden();

        $this->assertSame(DocumentStatus::Released, $document->fresh()->status);
    }

    public function test_only_a_secretary_can_undo_a_signature(): void
    {
        $document = $this->signedDocument();

        $this->assertFalse($this->uploader->can('undoSignature', $document));

        $this->actingAs($this->uploader)
            ->delete(route('documents.sign.destroy', $document))
            ->assertForbidden();
    }

    public function test_undoing_a_signature_does_not_require_a_reason(): void
    {
        $document = $this->signedDocument();

        $this->actingAs($this->secretary)
            ->delete(route('documents.sign.destroy', $document))
            ->assertRedirect(route('documents.show', $document));

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }
}
