<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Http\Requests\SignDocumentRequest;
use App\Models\Document;
use App\Models\DocumentReview;
use App\Models\DocumentSignature;
use App\Models\UserSigningCredential;
use App\Services\AuditLogger;
use App\Services\DocumentSigner;
use App\Services\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentSignatureController extends Controller
{
    /**
     * Show the interactive signing page.
     * The secretary places the RD's e-sig on a PDF preview and enters the RD's P12 password.
     */
    public function create(Request $request, Document $document): Response
    {
        $this->authorize('sign', $document);

        $credential = UserSigningCredential::forRegionalDirector();

        $esigUrl = $credential->esig_path
            ? route('documents.sign.esig', $document)
            : null;

        return Inertia::render('Documents/Sign', [
            'document' => $document->load(['uploader:id,name', 'division:id,name']),
            'documentUrl' => route('documents.file', $document),
            'esigUrl' => $esigUrl,
            'credential' => [
                'signer_name' => $credential->user?->name ?? 'Schools Division Superintendent',
                'certificate_subject' => $credential->certificate_subject,
                'certificate_serial' => $credential->certificate_serial,
                'certificate_valid_until' => $credential->certificate_valid_until?->toIso8601String(),
                'has_esig' => $credential->esig_path !== null,
            ],
        ]);
    }

    /**
     * Process the signing: verify P12 password, create signed PDF, record the signature, update document status.
     */
    public function store(SignDocumentRequest $request, Document $document): RedirectResponse
    {
        $user = $request->user();
        $credential = UserSigningCredential::forRegionalDirector();

        if (! $credential) {
            return back()->withErrors(['p12_password' => 'No signing credential found for the RD.']);
        }

        $signer = new DocumentSigner;

        $placements = collect($request->placements)->map(fn ($p) => [
            'page' => (int) $p['page'],
            'x_percent' => (float) $p['x_percent'],
            'y_percent' => (float) $p['y_percent'],
            'width_percent' => (float) $p['width_percent'],
        ])->all();

        $includeSignatureDetails = $request->boolean('include_signature_details');
        // Captured once and threaded through to both the visual stamp and
        // the DB row below, so the printed timestamp and signed_at can never
        // drift apart even by a second.
        $signedAt = now();

        try {
            $signedPath = $signer->sign(
                $document,
                $credential,
                $request->p12_password,
                $placements,
                $includeSignatureDetails,
                $signedAt,
            );
        } catch (\RuntimeException $e) {
            // Distinguish wrong-password errors from integrity/format errors
            $field = str_contains($e->getMessage(), 'password') ? 'p12_password' : 'placements';

            return back()->withErrors([$field => $e->getMessage()]);
        }

        $signedHash = hash_file('sha256', Storage::disk('documents')->path($signedPath));
        $first = $placements[0];

        DB::transaction(function () use ($user, $document, $credential, $signedPath, $signedHash, $request, $placements, $first, $includeSignatureDetails, $signedAt) {
            // Record the signature metadata. The legacy single-placement
            // columns mirror the first placement for anything still reading
            // them directly; the placements() relation below is the source
            // of truth for the full set.
            $signature = DocumentSignature::create([
                'document_id' => $document->id,
                'signed_by' => $user->id,
                'signed_file_path' => $signedPath,
                'signed_file_hash' => $signedHash,
                'certificate_subject' => $credential->certificate_subject,
                'certificate_serial' => $credential->certificate_serial,
                'signature_page' => $first['page'],
                'sig_x_percent' => $first['x_percent'],
                'sig_y_percent' => $first['y_percent'],
                'sig_width_percent' => $first['width_percent'],
                'signed_at' => $signedAt,
                'include_signature_details' => $includeSignatureDetails,
            ]);

            foreach ($placements as $placement) {
                $signature->placements()->create($placement);
            }

            // Record the review action — this is always the secretary attaching
            // the RD's own signature (DocumentPolicy::sign() requires the
            // secretary role), the RD's own approval was already recorded
            // as a separate review row, so attribute this one accurately.
            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => 'secretary',
                'action' => ReviewAction::ApprovedSigned,
                'remarks' => $request->remarks,
            ]);

            // Advance document status to Signed
            $document->update(['status' => DocumentStatus::Signed]);

            AuditLogger::log($user, $document, 'document.signed', $request, [
                'certificate_subject' => $credential->certificate_subject,
                'certificate_serial' => $credential->certificate_serial,
                'placement_count' => count($placements),
                'signed_file_hash' => $signedHash,
                'include_signature_details' => $includeSignatureDetails,
            ]);

            NotificationDispatcher::documentSigned($document);
        });

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document has been approved and digitally signed.');
    }

    /**
     * Undo a signature so the secretary can correct a mistake (wrong
     * placement/position) and sign again. Reverts the document back to
     * Approved — the existing DocumentSignature row (and its placements)
     * are left in place for the audit trail rather than deleted; signing
     * again simply creates a new row, and Document::signature() already
     * always resolves to the most recent one.
     */
    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('undoSignature', $document);

        $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        $user = $request->user();

        DB::transaction(function () use ($user, $document, $request) {
            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => 'secretary',
                'action' => ReviewAction::SignatureReverted,
                'remarks' => $request->remarks,
            ]);

            $document->update(['status' => DocumentStatus::Approved]);

            AuditLogger::log($user, $document, 'document.signature_reverted', $request);
        });

        return redirect()->route('documents.show', $document)
            ->with('success', 'Signature undone — you can sign the document again.');
    }

    /**
     * Stream the RD's e-signature image for preview on the signing page.
     */
    public function esig(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('sign', $document);

        $credential = UserSigningCredential::forRegionalDirector();

        abort_if(! $credential || ! $credential->esig_path, 404);

        $disk = Storage::disk('credentials');
        $path = $credential->esig_path;

        abort_if(! $disk->exists($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        $contentType = $mimeTypes[$extension] ?? ($disk->mimeType($path) ?: 'image/png');

        return response()->stream(
            fn () => fpassthru($disk->readStream($path)),
            200,
            ['Content-Type' => $contentType, 'Content-Disposition' => 'inline'],
        );
    }

    /**
     * Stream the signed PDF to an authorized viewer (inline, so it opens in the browser).
     */
    public function download(Request $request, Document $document): BinaryFileResponse
    {
        $this->authorize('view', $document);

        $sig = $document->signature;

        abort_if(! $sig, 404, 'This document has not been signed yet.');

        $disk = Storage::disk('documents');

        abort_if(! $disk->exists($sig->signed_file_path), 404, 'Signed file not found.');

        $filename = self::sanitizeFilename($document->title).'.pdf';

        // BinaryFileResponse (unlike the disk's StreamedResponse) natively
        // supports HTTP Range requests for local files — pdf.js relies on
        // these to progressively fetch pages instead of downloading the
        // whole file before it can render anything. setContentDisposition()
        // reproduces the same UTF-8-safe inline disposition (with ASCII
        // fallback name) the disk's response() used to build automatically.
        $response = response()->file($disk->path($sig->signed_file_path), [
            'Content-Type' => 'application/pdf',
        ]);
        $response->setContentDisposition(
            'inline',
            $filename,
            str_replace('%', '', Str::ascii($filename)),
        );

        return $response;
    }

    /**
     * Turn a document title into a filesystem-safe filename: strips
     * characters illegal on Windows/macOS/Linux, collapses whitespace, and
     * drops trailing dots/spaces (invalid on Windows) so the download always
     * saves cleanly regardless of what the title contains.
     */
    private static function sanitizeFilename(string $title): string
    {
        $clean = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/', '', $title);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        $clean = rtrim($clean, '. ');

        return $clean !== '' ? mb_substr($clean, 0, 150) : 'document';
    }
}
