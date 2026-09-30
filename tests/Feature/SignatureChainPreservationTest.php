<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentReleaseStamp;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Models\UserSigningCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The headline claim of the pyhanko migration: a signature already applied
 * to a document must survive every subsequent step in the pipeline,
 * instead of being silently discarded the way the old FPDI/TCPDF pipeline
 * did — confirmed during planning that DocumentReleaseStamper's identical
 * FPDI-import mechanism meant the SDS's own signature never made it into
 * released/*.pdf, the file Records Section actually downloads and emails
 * out. A true PDF incremental update means the entire earlier file's bytes
 * are an exact prefix of the later file — the strongest, cheapest proof
 * available without a live Acrobat check (which stays a documented manual
 * step — see CLAUDE.md's "Digital signing" section).
 */
class SignatureChainPreservationTest extends TestCase
{
    use RefreshDatabase;

    private Division $ad;

    private Division $records;

    private User $sds;

    private User $secretary;

    private User $recordsClerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureTwoFactorEnabled::class, RestrictWeekendAccess::class]);

        foreach (['originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ad = Division::create(['name' => 'Administrative Division', 'code' => 'AD']);
        $this->records = Division::create(['name' => 'Records Section', 'code' => 'RS', 'is_records_section' => true, 'parent_id' => $this->ad->id]);

        $this->sds = User::factory()->create();
        $this->sds->assignRole('sds');

        $this->secretary = User::factory()->create();
        $this->secretary->assignRole('secretary');

        $this->recordsClerk = User::factory()->create(['division_id' => $this->records->id]);
        $this->recordsClerk->assignRole('originator');
    }

    public function test_the_sds_signed_bytes_are_an_exact_prefix_of_the_released_file(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('openssl extension not available.');
        }

        Storage::fake('documents');
        Storage::fake('credentials');

        [$sdsP12, $sdsPassword] = $this->makeSelfSignedP12('Test SDS');
        $sdsP12Path = "p12/{$this->sds->id}/sds.p12";
        Storage::disk('credentials')->put($sdsP12Path, $sdsP12);
        UserSigningCredential::create([
            'user_id' => $this->sds->id,
            'p12_path' => $sdsP12Path,
            'p12_hash' => hash('sha256', $sdsP12),
        ]);

        [$recordsP12, $recordsPassword] = $this->makeSelfSignedP12('Test Records Section');
        $recordsP12Path = "p12/{$this->recordsClerk->id}/records.p12";
        Storage::disk('credentials')->put($recordsP12Path, $recordsP12);
        UserSigningCredential::create([
            'user_id' => $this->recordsClerk->id,
            'p12_path' => $recordsP12Path,
            'p12_hash' => hash('sha256', $recordsP12),
        ]);

        $document = $this->approvedDocument();

        $this->actingAs($this->secretary)->post(route('documents.sign.store', $document), [
            'p12_password' => $sdsPassword,
            'placements' => [
                ['page' => 1, 'x_percent' => 10, 'y_percent' => 80, 'width_percent' => 30],
            ],
        ])->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Signed, $document->status);

        $signature = DocumentSignature::where('document_id', $document->id)->firstOrFail();
        $signedBytes = file_get_contents(Storage::disk('documents')->path($signature->signed_file_path));

        $this->actingAs($this->recordsClerk)->post(route('documents.release.store', $document), [
            'p12_password' => $recordsPassword,
            'stamp_page' => 1,
            'stamp_x_percent' => 10,
            'stamp_y_percent' => 60,
            'stamp_width_percent' => 30,
        ])->assertRedirect(route('documents.show', $document));

        $document->refresh();
        $this->assertSame(DocumentStatus::Released, $document->status);

        $stamp = DocumentReleaseStamp::where('document_id', $document->id)->firstOrFail();
        $releasedBytes = file_get_contents(Storage::disk('documents')->path($stamp->stamped_file_path));

        // The actual regression assertion: the SDS's fully-signed file's
        // bytes are an exact prefix of the released file — proof this is a
        // true incremental update (append-only), not a rewrite that
        // silently dropped the earlier signature the way the old
        // FPDI/TCPDF pipeline did.
        $this->assertStringStartsWith($signedBytes, $releasedBytes);
        $this->assertGreaterThan(strlen($signedBytes), strlen($releasedBytes));
    }

    private function approvedDocument(): Document
    {
        $path = 'outgoing/2026/07/original.pdf';
        $absolutePath = Storage::disk('documents')->path($path);
        File::ensureDirectoryExists(dirname($absolutePath));

        $pdf = new \TCPDF;
        $pdf->AddPage();
        $pdf->Write(0, 'Synthetic test PDF — no real document content.');
        $pdf->Output($absolutePath, 'F');

        return Document::create([
            'type' => DocumentType::Outgoing, 'title' => 'Signature chain test memo',
            'uploaded_by' => $this->secretary->id, 'division_id' => $this->ad->id,
            'file_path' => $path, 'file_hash' => hash_file('sha256', $absolutePath),
            'status' => DocumentStatus::Approved, 'requires_release' => true,
        ]);
    }

    /**
     * See DocumentSignMultiPlacementTest::makeSelfSignedP12() — identical
     * approach, parameterized by common name so SDS/Records Section get
     * distinct certs.
     */
    private function makeSelfSignedP12(string $commonName): array
    {
        $password = 'test-password';
        $dn = ['commonName' => $commonName, 'countryName' => 'PH'];
        $keyConfig = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        foreach ([null, $this->opensslConfigPath()] as $configPath) {
            $config = $configPath ? $keyConfig + ['config' => $configPath] : $keyConfig;

            $pkey = @openssl_pkey_new($config);
            $csr = $pkey ? @openssl_csr_new($dn, $pkey, $config) : false;
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
