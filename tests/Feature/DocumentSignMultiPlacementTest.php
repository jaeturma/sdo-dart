<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Models\UserSigningCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentSignMultiPlacementTest extends TestCase
{
    use RefreshDatabase;

    private User $secretary;
    private User $sds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'division_chief', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->secretary = User::factory()->create();
        $this->secretary->assignRole('secretary');
    }

    // ── Validation layer (no P12 needed — request validation runs before signing) ──

    public function test_at_least_one_placement_is_required(): void
    {
        Storage::fake('documents');
        $this->giveDummyCredential();
        $document = $this->approvedDocument();

        $this->actingAs($this->secretary)
            ->post(route('documents.sign.store', $document), [
                'p12_password' => 'whatever',
                'placements'   => [],
            ])
            ->assertSessionHasErrors('placements');

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_each_placement_requires_page_and_position(): void
    {
        Storage::fake('documents');
        $this->giveDummyCredential();
        $document = $this->approvedDocument();

        $this->actingAs($this->secretary)
            ->post(route('documents.sign.store', $document), [
                'p12_password' => 'whatever',
                'placements'   => [
                    ['page' => 1, 'x_percent' => 10], // missing y_percent / width_percent
                ],
            ])
            ->assertSessionHasErrors(['placements.0.y_percent', 'placements.0.width_percent']);
    }

    // ── SDS hasn't uploaded a signing credential yet ──

    /**
     * Without the SDS's credential there's no Sign button, so the secretary
     * is told why (and to ask the SDS to upload one) instead of the button
     * silently not appearing.
     */
    public function test_secretary_is_told_when_the_sds_has_not_uploaded_a_signature(): void
    {
        Storage::fake('documents');
        $document = $this->approvedDocument();

        $this->actingAs($this->secretary)
            ->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page
                ->where('canSign', false)
                ->where('sdsSignatureMissing', true));
    }

    public function test_the_notice_gives_way_to_the_sign_button_once_the_sds_uploads_a_signature(): void
    {
        Storage::fake('documents');
        $this->giveDummyCredential();
        $document = $this->approvedDocument();

        $this->actingAs($this->secretary)
            ->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page
                ->where('canSign', true)
                ->where('sdsSignatureMissing', false));
    }

    public function test_only_the_secretary_sees_the_missing_signature_notice(): void
    {
        Storage::fake('documents');
        $document = $this->approvedDocument();

        $this->actingAs($this->sds)
            ->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page->where('sdsSignatureMissing', false));
    }

    public function test_no_missing_signature_notice_before_the_document_is_approved(): void
    {
        Storage::fake('documents');
        $document = $this->approvedDocument();
        $document->update(['status' => DocumentStatus::PendingSdsReview]);

        $this->actingAs($this->secretary)
            ->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page->where('sdsSignatureMissing', false));
    }

    // ── Full end-to-end signing with a real (self-signed, test-only) P12 ──

    public function test_multiple_placements_across_pages_produce_one_signature_with_all_placements_recorded(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('openssl extension not available.');
        }

        Storage::fake('documents');
        Storage::fake('credentials');

        [$p12, $password] = $this->makeSelfSignedP12();
        $p12Path = "p12/{$this->sds->id}/test.p12";
        Storage::disk('credentials')->put($p12Path, $p12);

        UserSigningCredential::create([
            'user_id'  => $this->sds->id,
            'p12_path' => $p12Path,
            'p12_hash' => hash('sha256', $p12),
        ]);

        $document = $this->approvedDocument(pages: 3);

        $response = $this->actingAs($this->secretary)->post(route('documents.sign.store', $document), [
            'p12_password' => $password,
            'placements'   => [
                ['page' => 1, 'x_percent' => 10, 'y_percent' => 80, 'width_percent' => 20],
                ['page' => 3, 'x_percent' => 15, 'y_percent' => 85, 'width_percent' => 25],
            ],
        ]);

        $response->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Signed, $document->status);

        $signature = DocumentSignature::where('document_id', $document->id)->first();
        $this->assertNotNull($signature);
        $this->assertCount(2, $signature->placements);

        // Legacy single-placement columns mirror the first placement.
        $this->assertSame(1, $signature->signature_page);
        $this->assertEqualsWithDelta(10.0, (float) $signature->sig_x_percent, 0.01);

        $placementPages = $signature->placements->pluck('page')->sort()->values()->all();
        $this->assertSame([1, 3], $placementPages);

        $absolutePath = Storage::disk('documents')->path($signature->signed_file_path);
        $this->assertFileExists($absolutePath);

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $this->assertSame(3, $pdf->setSourceFile($absolutePath));
    }

    public function test_include_signature_details_option_is_recorded_and_still_produces_a_valid_signed_pdf(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('openssl extension not available.');
        }

        Storage::fake('documents');
        Storage::fake('credentials');

        [$p12, $password] = $this->makeSelfSignedP12();
        $p12Path = "p12/{$this->sds->id}/test.p12";
        Storage::disk('credentials')->put($p12Path, $p12);

        UserSigningCredential::create([
            'user_id'  => $this->sds->id,
            'p12_path' => $p12Path,
            'p12_hash' => hash('sha256', $p12),
        ]);

        $document = $this->approvedDocument();

        $response = $this->actingAs($this->secretary)->post(route('documents.sign.store', $document), [
            'p12_password' => $password,
            'include_signature_details' => true,
            'placements'   => [
                ['page' => 1, 'x_percent' => 10, 'y_percent' => 80, 'width_percent' => 30],
            ],
        ]);

        $response->assertRedirect(route('documents.show', $document));

        $signature = DocumentSignature::where('document_id', $document->id)->first();
        $this->assertNotNull($signature);
        $this->assertTrue($signature->include_signature_details);

        $absolutePath = Storage::disk('documents')->path($signature->signed_file_path);
        $this->assertFileExists($absolutePath);

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $this->assertSame(1, $pdf->setSourceFile($absolutePath));
    }

    public function test_signature_details_option_defaults_to_false_when_omitted(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('openssl extension not available.');
        }

        Storage::fake('documents');
        Storage::fake('credentials');

        [$p12, $password] = $this->makeSelfSignedP12();
        $p12Path = "p12/{$this->sds->id}/test.p12";
        Storage::disk('credentials')->put($p12Path, $p12);

        UserSigningCredential::create([
            'user_id'  => $this->sds->id,
            'p12_path' => $p12Path,
            'p12_hash' => hash('sha256', $p12),
        ]);

        $document = $this->approvedDocument();

        $this->actingAs($this->secretary)->post(route('documents.sign.store', $document), [
            'p12_password' => $password,
            'placements'   => [
                ['page' => 1, 'x_percent' => 10, 'y_percent' => 80, 'width_percent' => 30],
            ],
        ]);

        $signature = DocumentSignature::where('document_id', $document->id)->first();
        $this->assertFalse($signature->include_signature_details);
    }

    /**
     * A credential that exists (satisfies DocumentPolicy::sign()'s
     * "SDS has a credential" check so requests reach form validation) but
     * isn't a usable P12 — only for the validation-layer tests above, which
     * never actually attempt to decrypt it.
     */
    private function giveDummyCredential(): void
    {
        UserSigningCredential::create([
            'user_id'  => $this->sds->id,
            'p12_path' => "p12/{$this->sds->id}/dummy.p12",
            'p12_hash' => hash('sha256', 'dummy'),
        ]);
    }

    private function approvedDocument(int $pages = 1): Document
    {
        $path = 'outgoing/2026/07/original.pdf';
        $absolutePath = Storage::disk('documents')->path($path);
        File::ensureDirectoryExists(dirname($absolutePath));

        $pdf = new \TCPDF();
        for ($i = 0; $i < $pages; $i++) {
            $pdf->AddPage();
            $pdf->Write(0, 'Synthetic test PDF page ' . ($i + 1) . ' — no real document content.');
        }
        $pdf->Output($absolutePath, 'F');

        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Multi-placement test memo',
            'uploaded_by' => $this->secretary->id, 'file_path' => $path,
            'file_hash' => hash_file('sha256', $absolutePath), 'status' => DocumentStatus::Approved,
        ]);
    }

    /**
     * Generates a throwaway self-signed certificate entirely offline (no
     * real CA, no network) purely so the multi-placement signing path can be
     * exercised end-to-end with a genuinely valid PKCS#12 file. Windows PHP
     * builds often don't have OpenSSL's default config path wired up, so
     * this tries without an explicit config first, then falls back to a
     * config file, and finally skips the test rather than failing the suite
     * in an environment where a self-signed cert just isn't producible.
     *
     * @return array{0: string, 1: string} [p12 binary contents, password]
     */
    private function makeSelfSignedP12(): array
    {
        $password = 'test-password';
        $dn = ['commonName' => 'Test Schools Division Superintendent', 'countryName' => 'PH'];
        $keyConfig = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        foreach ([null, $this->opensslConfigPath()] as $configPath) {
            $config = $configPath ? $keyConfig + ['config' => $configPath] : $keyConfig;

            $pkey = @openssl_pkey_new($config);
            $csr  = $pkey ? @openssl_csr_new($dn, $pkey, $config) : false;
            $cert = $csr ? @openssl_csr_sign($csr, null, $pkey, 365, $config) : false;

            if ($cert && @openssl_pkcs12_export($cert, $p12, $pkey, $password)) {
                return [$p12, $password];
            }
        }

        $this->markTestSkipped('Could not generate a self-signed test certificate in this environment (OpenSSL config not found).');
    }

    private function opensslConfigPath(): ?string
    {
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            'C:/Program Files/Common Files/SSL/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            ...(glob('C:/Users/*/.config/herd/bin/php*/extras/ssl/openssl.cnf') ?: []),
            ...(glob('C:/Users/*/.config/herd/openssl.cnf') ?: []),
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
