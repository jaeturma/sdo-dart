<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSigningCredentialRequest;
use App\Models\Division;
use App\Models\User;
use App\Models\UserSigningCredential;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SigningCredentialController extends Controller
{
    /**
     * Admin overview of all signatories and their credential status.
     * Non-admin users are redirected to their own credential page.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasRole('admin')) {
            return redirect()->route('my-signature.show');
        }

        $recordsSectionId = Division::recordsSectionId();

        $users = User::with(['signingCredential', 'roles', 'division'])
            ->where(function ($q) use ($recordsSectionId) {
                $q->whereHas('roles', fn ($r) => $r->whereIn('name', ['sds', 'asds', 'division_chief']))
                    ->when($recordsSectionId, fn ($q2) => $q2->orWhere('division_id', $recordsSectionId));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'roles'      => $u->getRoleNames()->toArray(),
                'division'   => $u->division?->name,
                'credential' => $u->signingCredential
                    ? $this->shapeCredential($u->signingCredential)
                    : null,
            ]);

        return Inertia::render('SigningCredentials/Index', compact('users'));
    }

    /**
     * Shows the authenticated user's own signing credential (or the upload form if none exists).
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('upload', UserSigningCredential::class), 403);

        $credential = $user->signingCredential;

        return Inertia::render('SigningCredentials/Show', [
            'credential' => $credential ? $this->shapeCredential($credential) : null,
        ]);
    }

    /**
     * Stores (or replaces) the authenticated user's signing credential.
     */
    public function store(StoreSigningCredentialRequest $request): RedirectResponse
    {
        $user = $request->user();
        $p12File = $request->file('p12_file');

        // Re-read the P12 to extract certificate metadata (already validated by FormRequest)
        $certs = [];
        openssl_pkcs12_read(
            file_get_contents($p12File->getRealPath()),
            $certs,
            $request->p12_password,
        );
        $certData = openssl_x509_parse($certs['cert']);

        $p12Hash  = hash_file('sha256', $p12File->getRealPath());
        $p12Path  = $p12File->storeAs(
            "p12/{$user->id}",
            $user->id . '_' . time() . '.p12',
            'credentials',
        );

        $esigPath = null;
        $esigHash = null;
        if ($request->hasFile('esig_image')) {
            $esigFile = $request->file('esig_image');
            $esigHash = hash_file('sha256', $esigFile->getRealPath());
            $esigPath = $esigFile->storeAs(
                "esig/{$user->id}",
                $user->id . '_' . time() . '.' . $esigFile->getClientOriginalExtension(),
                'credentials',
            );
        }

        // Remove old credential and files if replacing
        if ($existing = $user->signingCredential) {
            Storage::disk('credentials')->delete(
                array_filter([$existing->p12_path, $existing->esig_path]),
            );
            $existing->delete();
        }

        $credential = UserSigningCredential::create([
            'user_id'                  => $user->id,
            'p12_path'                 => $p12Path,
            'p12_hash'                 => $p12Hash,
            'esig_path'                => $esigPath,
            'esig_hash'                => $esigHash,
            'certificate_subject'      => $certData['subject']['CN'] ?? null,
            'certificate_serial'       => $certData['serialNumberHex'] ?? ($certData['serialNumber'] ?? null),
            'certificate_valid_from'   => isset($certData['validFrom_time_t'])
                ? Carbon::createFromTimestamp($certData['validFrom_time_t'])
                : null,
            'certificate_valid_until'  => isset($certData['validTo_time_t'])
                ? Carbon::createFromTimestamp($certData['validTo_time_t'])
                : null,
        ]);

        AuditLogger::log($user, null, 'credential.uploaded', $request, [
            'certificate_subject' => $credential->certificate_subject,
            'certificate_serial'  => $credential->certificate_serial,
            'valid_until'         => $credential->certificate_valid_until?->toDateString(),
        ]);

        return redirect()->route('my-signature.show')
            ->with('success', 'Signing credential uploaded successfully.');
    }

    /**
     * Streams the authenticated user's e-signature image.
     */
    public function esig(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $credential = $request->user()->signingCredential;

        abort_if(! $credential || ! $credential->esig_path, 404);

        $disk = Storage::disk('credentials');
        $path = $credential->esig_path;

        abort_if(! $disk->exists($path), 404);

        $extension   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeTypes   = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        $contentType = $mimeTypes[$extension] ?? ($disk->mimeType($path) ?: 'image/png');

        return response()->stream(
            fn () => fpassthru($disk->readStream($path)),
            200,
            ['Content-Type' => $contentType, 'Content-Disposition' => 'inline'],
        );
    }

    /**
     * Deletes the authenticated user's own signing credential.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $credential = $request->user()->signingCredential ?? abort(404);

        $this->authorize('delete', $credential);
        $this->deleteCredential($credential, $request);

        return redirect()->route('my-signature.show')
            ->with('success', 'Signing credential removed.');
    }

    /**
     * Admin: deletes any user's signing credential.
     */
    public function destroyFor(Request $request, User $user): RedirectResponse
    {
        $credential = $user->signingCredential ?? abort(404);

        $this->authorize('delete', $credential);
        $this->deleteCredential($credential, $request);

        return redirect()->route('signing-credentials.index')
            ->with('success', "Signing credential for {$user->name} has been revoked.");
    }

    private function deleteCredential(UserSigningCredential $credential, Request $request): void
    {
        $disk = Storage::disk('credentials');
        $disk->delete(array_filter([$credential->p12_path, $credential->esig_path]));

        AuditLogger::log($request->user(), null, 'credential.revoked', $request, [
            'for_user_id'         => $credential->user_id,
            'certificate_subject' => $credential->certificate_subject,
        ]);

        $credential->delete();
    }

    private function shapeCredential(UserSigningCredential $credential): array
    {
        $now = now();

        return [
            'certificate_subject'      => $credential->certificate_subject,
            'certificate_serial'       => $credential->certificate_serial,
            'certificate_valid_from'   => $credential->certificate_valid_from?->toISOString(),
            'certificate_valid_until'  => $credential->certificate_valid_until?->toISOString(),
            'has_esig'                 => (bool) $credential->esig_path,
            'is_expired'               => $credential->certificate_valid_until
                && $credential->certificate_valid_until->lt($now),
            'is_expiring_soon'         => $credential->certificate_valid_until
                && $credential->certificate_valid_until->gt($now)
                && $credential->certificate_valid_until->lt($now->copy()->addDays(30)),
            'uploaded_at'              => $credential->created_at->toISOString(),
        ];
    }
}
