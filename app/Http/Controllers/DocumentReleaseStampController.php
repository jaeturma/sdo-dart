<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Http\Requests\StoreReleaseStampRequest;
use App\Models\Document;
use App\Models\DocumentReleaseStamp;
use App\Models\DocumentReview;
use App\Services\AuditLogger;
use App\Services\DocumentReleaseStamper;
use App\Services\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentReleaseStampController extends Controller
{
    /**
     * Show the interactive release-stamp placement page.
     * Records Section places their own PNPKI-backed release stamp on the
     * already SDS-signed PDF.
     */
    public function create(Request $request, Document $document): Response|RedirectResponse
    {
        $this->authorize('release', $document);

        $credential = $request->user()->signingCredential;

        if (! $credential) {
            return redirect()->route('documents.show', $document)
                ->with('error', 'You need to upload your own signing credential before you can release a document. Go to "My Signature" to add one.');
        }

        return Inertia::render('Documents/ReleaseStamp', [
            'document' => $document->load(['uploader:id,name', 'division:id,name']),
            'documentUrl' => route('documents.signed-pdf', $document),
            'esigUrl' => $credential->esig_path ? route('my-signature.esig') : null,
            'credential' => [
                'certificate_subject' => $credential->certificate_subject,
                'certificate_serial' => $credential->certificate_serial,
                'certificate_valid_until' => $credential->certificate_valid_until?->toIso8601String(),
                'has_esig' => $credential->esig_path !== null,
                // Matches the fallback DocumentReleaseStamper::stamp() actually signs with,
                // so the "Digitally signed by" preview text below never disagrees with the PDF.
                'signer_name' => $credential->user?->name ?? 'Records Section',
            ],
        ]);
    }

    /**
     * Process the release: verify P12 password, stamp the SDS-signed PDF,
     * record the stamp, transition the document to Released.
     */
    public function store(StoreReleaseStampRequest $request, Document $document): RedirectResponse
    {
        $user = $request->user();
        $credential = $user->signingCredential;

        if (! $credential) {
            return back()->withErrors(['p12_password' => 'No signing credential found for your account.']);
        }

        $stamper = app(DocumentReleaseStamper::class);

        try {
            $stampedPath = $stamper->stamp(
                $document,
                $credential,
                $request->p12_password,
                (int) $request->stamp_page,
                (float) $request->stamp_x_percent,
                (float) $request->stamp_y_percent,
                (float) $request->stamp_width_percent,
            );
        } catch (\RuntimeException $e) {
            $field = str_contains($e->getMessage(), 'password') ? 'p12_password' : 'stamp_page';

            return back()->withErrors([$field => $e->getMessage()]);
        }

        $stampedHash = hash_file('sha256', Storage::disk('documents')->path($stampedPath));

        DB::transaction(function () use ($user, $document, $credential, $stampedPath, $stampedHash, $request) {
            DocumentReleaseStamp::create([
                'document_id' => $document->id,
                'stamped_by' => $user->id,
                'stamped_file_path' => $stampedPath,
                'stamped_file_hash' => $stampedHash,
                'certificate_subject' => $credential->certificate_subject,
                'certificate_serial' => $credential->certificate_serial,
                'stamp_page' => (int) $request->stamp_page,
                'stamp_x_percent' => (float) $request->stamp_x_percent,
                'stamp_y_percent' => (float) $request->stamp_y_percent,
                'stamp_width_percent' => (float) $request->stamp_width_percent,
                'stamped_at' => now(),
            ]);

            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => 'records_section',
                'action' => ReviewAction::Released,
                'remarks' => $request->remarks,
            ]);

            $document->forceFill([
                'status' => DocumentStatus::Released,
                'released_at' => now(),
                'released_by' => $user->id,
            ])->save();

            AuditLogger::log($user, $document, 'document.released', $request, [
                'certificate_subject' => $credential->certificate_subject,
                'certificate_serial' => $credential->certificate_serial,
                'stamp_page' => $request->stamp_page,
                'stamped_file_hash' => $stampedHash,
            ]);

            NotificationDispatcher::documentReleased($document);
        });

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document has been released and stamped.');
    }

    /**
     * Confirm that Records Section downloaded the released PDF and sent it
     * out by email — a lightweight follow-up step, not a full re-processing
     * of the file. Recorded as a review-history entry so the originating
     * office can see it, and notifies the uploader and their supervisor
     * (division chief, or unit head for an OSDS sub-unit) that it's been sent.
     */
    public function markEmailed(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('markEmailed', $document);

        $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        $user = $request->user();

        DB::transaction(function () use ($user, $document, $request) {
            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => 'records_section',
                'action' => ReviewAction::Emailed,
                'remarks' => $request->remarks,
            ]);

            $document->forceFill([
                'emailed_at' => now(),
                'emailed_by' => $user->id,
            ])->save();

            AuditLogger::log($user, $document, 'document.emailed', $request);

            NotificationDispatcher::documentEmailed($document);
        });

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document marked as emailed.');
    }

    /**
     * Stream the released/stamped PDF to an authorized viewer (inline).
     */
    public function download(Request $request, Document $document): BinaryFileResponse
    {
        $this->authorize('view', $document);

        $stamp = $document->releaseStamp;

        abort_if(! $stamp, 404, 'This document has not been released yet.');

        $disk = Storage::disk('documents');

        abort_if(! $disk->exists($stamp->stamped_file_path), 404, 'Released file not found.');

        $filename = self::sanitizeFilename($document->title).'.pdf';

        // BinaryFileResponse rather than the disk's StreamedResponse — see
        // DocumentSignatureController::download()'s identical fix for why
        // (HTTP Range support for pdf.js's progressive fetching).
        $response = response()->file($disk->path($stamp->stamped_file_path), [
            'Content-Type' => 'application/pdf',
        ]);
        $response->setContentDisposition(
            'inline',
            $filename,
            str_replace('%', '', Str::ascii($filename)),
        );

        return $this->revalidateOnEveryView($response, $request, $stamp->stamped_file_hash);
    }

    /**
     * Turn a document title into a filesystem-safe filename — see
     * DocumentSignatureController::sanitizeFilename() for the same helper.
     */
    private static function sanitizeFilename(string $title): string
    {
        $clean = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/', '', $title);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        $clean = rtrim($clean, '. ');

        return $clean !== '' ? mb_substr($clean, 0, 150) : 'document';
    }
}
