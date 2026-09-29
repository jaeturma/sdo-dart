<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ReviewAction;
use App\Http\Requests\ResubmitOutgoingDocumentRequest;
use App\Http\Requests\StoreOutgoingDocumentRequest;
use App\Models\ArdAvailabilitySetting;
use App\Models\Document;
use App\Models\DocumentReview;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlNumberGenerator;
use App\Services\NotificationDispatcher;
use App\Services\PdfNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class OutgoingDocumentController extends Controller
{
    public function create(): Response
    {
        $this->authorize('createOutgoing', Document::class);

        return Inertia::render('Documents/CreateOutgoing');
    }

    public function store(StoreOutgoingDocumentRequest $request): RedirectResponse
    {
        [$path, $hash] = app(PdfNormalizer::class)->storeAndHash($request->file('file'), 'outgoing/'.now()->format('Y/m'));

        $user = $request->user();

        $document = Document::create([
            'type' => DocumentType::Outgoing,
            'title' => $request->title,
            'description' => $request->description,
            'uploaded_by' => $user->id,
            'division_id' => $user->division_id,
            'control_number' => ControlNumberGenerator::forOutgoing($user->division),
            'file_path' => $path,
            'file_hash' => $hash,
            'status' => self::initialStatus($user),
            'requires_release' => $request->boolean('requires_release'),
        ]);

        AuditLogger::log($user, $document, 'document.submitted', $request);
        NotificationDispatcher::documentSubmitted($document);

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document submitted for review.');
    }

    public function editResubmit(Document $document): Response
    {
        $this->authorize('resubmit', $document);

        return Inertia::render('Documents/ResubmitOutgoing', compact('document'));
    }

    public function updateResubmit(ResubmitOutgoingDocumentRequest $request, Document $document): RedirectResponse
    {
        // Captured before storeAndHash()/update() touch the model — once
        // update() runs, Eloquent's "original" state is synced to the new
        // values, so getOriginal('file_path') is unusable afterward.
        $oldPath = $document->file_path;

        [$path, $hash] = app(PdfNormalizer::class)->storeAndHash($request->file('file'), 'outgoing/'.now()->format('Y/m'));

        $user = $request->user();

        DB::transaction(function () use ($document, $request, $path, $hash, $user) {
            $document->update([
                'title' => $request->title,
                'description' => $request->description,
                'file_path' => $path,
                'file_hash' => $hash,
                'status' => self::initialStatus($user),
            ]);

            DocumentReview::create([
                'document_id' => $document->id,
                'reviewer_id' => $user->id,
                'role_at_review' => 'uploader',
                'action' => ReviewAction::Resubmitted,
                'remarks' => $request->description,
            ]);

            AuditLogger::log($user, $document, 'document.resubmitted', $request);
        });

        // Deliberately after the transaction has committed, not inside it —
        // physical file deletion can't be rolled back, so deleting the old
        // file before the DB change is durable risks losing it even if the
        // transaction then fails. A delete failure here is logged, not
        // thrown: the resubmission has already fully succeeded and the DB
        // is already correct, which matters far more than reclaiming one
        // stray file.
        if ($oldPath !== $path && ! Storage::disk('documents')->delete($oldPath)) {
            Log::warning('Resubmission could not delete the superseded document file.', [
                'document_id' => $document->id,
                'old_path' => $oldPath,
            ]);
        }

        NotificationDispatcher::documentSubmitted($document);

        return redirect()->route('documents.show', $document)
            ->with('success', 'Document resubmitted for review.');
    }

    /**
     * A division chief already sits above every originator review stage in
     * their own division, so their own submissions skip straight to ARD
     * review instead of being routed back to themselves for a lower-stage
     * review.
     *
     * A unit head's own submission only gets that same skip if they're in
     * the Office of the Regional Director (ORD) or one of its sub-units
     * (ICTU, LU, PAU, ORD Proper, etc.) — that's ORD's own internal
     * structure, where the RD is effectively their next reviewer. A unit
     * head anywhere else (e.g. Administrative Division) still has an actual
     * division chief above them, so their own upload goes to that chief
     * instead, same as it would for one of their own staff's submissions
     * — unless their division genuinely has no chief, in which case it
     * skips onward the same way OutgoingDocumentController::initialStatus()
     * already does for a regular originator (see nextStatusAfterUnitHead()).
     *
     * The RD's own (ORD) secretary gets this same skip for the same reason
     * — she sits in ORD Proper (or ORD itself) with nobody above her except
     * ARD/RD, so a memo she drafts has no unit head or division chief to
     * review it either.
     *
     * Public/static so the resubmission flow can reuse the exact same rule.
     */
    public static function initialStatus(User $user): DocumentStatus
    {
        if ($user->hasRole('division_chief')) {
            return ArdAvailabilitySetting::nextAfterDivisionChief();
        }

        // Staff detailed to the Regional Office from elsewhere (e.g. a
        // School Division Office) — no unit head or division chief sits
        // above them, so their own upload skips straight to ARD review the
        // same way a division chief's own upload does. Unlike the OARD
        // check further below, this one deliberately DOES honor the
        // ARD-availability toggle (nextAfterDivisionChief() falls through to
        // RD when she's marked unavailable) rather than always forcing
        // PendingArdReview.
        if ($user->hasRole('detailed_staff')) {
            return ArdAvailabilitySetting::nextAfterDivisionChief();
        }

        if ($user->hasRole('unit_head')) {
            $division = $user->division;
            $isOrdUnitHead = $division?->code === 'ORD' || $division?->parent?->code === 'ORD';

            if ($isOrdUnitHead) {
                return ArdAvailabilitySetting::nextAfterDivisionChief();
            }

            // A unit head's chief isn't necessarily in their own division —
            // e.g. Procurement Unit/Personnel Section sit as sub-units under
            // Administrative Division, and it's Administrative Division's
            // chief who actually reviews them, not anyone in Procurement Unit
            // itself. Check both: their own division (in case a unit head and
            // chief do share one) and the parent division — matches the same
            // dual check DocumentPolicy::review() already applies when a
            // chief reviews a PendingDivisionChiefReview document.
            $divisionIds = array_filter([$user->division_id, $division?->parent_id]);
            $hasChief = ! empty($divisionIds) && User::whereHas('roles', fn ($q) => $q->where('name', 'division_chief'))
                ->whereIn('division_id', $divisionIds)
                ->exists();

            return $hasChief ? DocumentStatus::PendingDivisionChiefReview : ArdAvailabilitySetting::nextAfterDivisionChief();
        }

        if (self::isOrdSecretary($user)) {
            return ArdAvailabilitySetting::nextAfterDivisionChief();
        }

        // Staff under the Office of the Assistant Regional Director (OARD)
        // report directly to the ARD — unlike ORD's other sub-units
        // (ICTU/LU/PAU), OARD has no unit head of its own, and there's no
        // division chief anywhere in the ORD tree either. Without this
        // check, the fallback below would find no unit head in their
        // division and leave the document at `Submitted`, which routes to a
        // division chief review that doesn't exist for them — stuck with
        // nobody able to act on it.
        //
        // Deliberately always PendingArdReview, NOT
        // ArdAvailabilitySetting::nextAfterDivisionChief() — that toggle
        // exists to skip ARD as an *optional* review layer for the rest of
        // the org's division-chief escalations when she isn't handling that
        // general queue, but she's still personally, directly responsible
        // for her own OARD staff's documents regardless of it, the same way
        // a division chief always reviews their own staff with no
        // equivalent "unavailable" bypass. This is intentionally scoped to
        // OARD only — the ORD unit-head/secretary branches above are
        // unaffected and still honor the toggle.
        if ($user->division?->code === 'OARD') {
            return DocumentStatus::PendingArdReview;
        }

        $hasUnitHead = $user->division_id && User::whereHas('roles', fn ($q) => $q->where('name', 'unit_head'))
            ->where('division_id', $user->division_id)
            ->exists();

        return $hasUnitHead ? DocumentStatus::PendingUnitHeadReview : DocumentStatus::Submitted;
    }

    /**
     * The RD's own secretary — same identification as
     * DocumentPolicy::isOrdSecretary() (kept as a separate private copy here
     * rather than shared, matching how isOrdSecretary() is duplicated
     * per-class elsewhere in this codebase rather than shared).
     */
    private static function isOrdSecretary(User $user): bool
    {
        if (! $user->hasRole('secretary') || $user->division_id === null) {
            return false;
        }

        $division = $user->division;

        return $division?->code === 'ORD' || $division?->parent?->code === 'ORD';
    }
}
