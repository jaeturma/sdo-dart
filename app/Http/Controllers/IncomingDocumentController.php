<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Requests\StoreIncomingDocumentRequest;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\ControlNumberGenerator;
use App\Services\IncomingAutoForwarder;
use App\Services\PdfNormalizer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IncomingDocumentController extends Controller
{
    /**
     * The fixed remarks string autoForward() writes on the routing it
     * creates — the only code path that ever writes it. Shared so anything
     * that needs to distinguish "this routing round was an automatic
     * system-triggered forward" from "this user personally issued a routing
     * round" (DocumentPolicy::route()'s once-each check,
     * DocumentScopesTrait's forActionScope()/recordsSectionAutoForwardedScope())
     * can identify it consistently instead of repeating the literal string.
     */
    public const AUTO_FORWARD_REMARKS = 'Auto-forwarded upon intake.';

    public function create(): Response
    {
        $this->authorize('createIncoming', Document::class);

        return Inertia::render('Documents/CreateIncoming');
    }

    public function store(StoreIncomingDocumentRequest $request): RedirectResponse
    {
        [$path, $hash] = app(PdfNormalizer::class)->storeAndHash($request->file('file'), 'incoming/' . now()->format('Y/m'));

        $document = Document::create([
            'type'             => DocumentType::Incoming,
            'title'            => $request->title,
            'description'      => $request->description,
            'uploaded_by'      => $request->user()->id,
            // Stamped at creation time (mirroring outgoing uploads, which
            // already do this) so eligibility checks tied to "who uploaded
            // this" — e.g. DocumentPolicy::archive() — stay correct even if
            // the uploader is later reassigned to a different division,
            // rather than re-deriving from their current division_id.
            'division_id'      => $request->user()->division_id,
            'source_origin'    => $request->source_origin,
            'reference_number' => $request->reference_number,
            'control_number'   => ControlNumberGenerator::forIncoming(),
            'file_path'        => $path,
            'file_hash'        => $hash,
            'status'           => DocumentStatus::Received,
        ]);

        AuditLogger::log($request->user(), $document, 'document.received', $request);

        $autoForwardFailed = false;

        if (IncomingAutoForwarder::appliesTo($request->user())) {
            $autoForwardFailed = ! IncomingAutoForwarder::forward($document, $request->user(), $request);
        }

        $response = redirect()->route('documents.show', $document);

        if ($autoForwardFailed) {
            // Surface this immediately instead of letting it fail silently —
            // with no Records Section designated, the document never gets
            // routed and Records Section never sees it.
            return $response->with('warning', 'Document uploaded, but could not be forwarded to Records Section — no office is designated as the Records Section yet. Please notify an administrator; the document is still visible to the SDS.');
        }

        return $response->with('success', 'Incoming document uploaded.');
    }
}
