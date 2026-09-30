<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\RecipientStatus;
use App\Enums\RoutingAction;
use App\Http\Controllers\IncomingDocumentController;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRouting;
use App\Models\DocumentRoutingRecipient;
use App\Models\User;
use App\Notifications\DocumentEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Forwards an incoming document to Records Section, exactly the way the
 * SDS's secretary's uploads auto-forward on intake
 * (IncomingDocumentController::store()). Extracted so the same logic can
 * also be used to retroactively fix documents that were uploaded before a
 * Records Section was designated (see documents:diagnose-auto-forward
 * --apply) — both callers must stay identical, or the retroactive fix would
 * produce routings that behave subtly differently from a normal upload.
 *
 * Returns false (and logs a warning) if no office is designated as the
 * Records Section, so the caller can surface that instead of silently
 * leaving the document unrouted.
 */
class IncomingAutoForwarder
{
    /**
     * Whether an incoming upload by this user auto-forwards a courtesy copy
     * to Records Section: the SDS's own secretary (secretary role, in OSDS
     * or one of its sub-offices — the same identification as
     * DocumentPolicy::isOsdsSecretary()). Keyed on her rather than on a
     * specially-coded "OSDS Proper" office, which a fresh SDO install has no
     * reason to create.
     */
    public static function appliesTo(User $uploader): bool
    {
        if (! $uploader->hasRole('secretary') || $uploader->division_id === null) {
            return false;
        }

        $division = $uploader->division;

        return $division?->code === 'OSDS' || $division?->parent?->code === 'OSDS';
    }

    public static function forward(Document $document, User $user, ?Request $request = null): bool
    {
        $recordsId = Division::recordsSectionId();

        if (! $recordsId) {
            Log::warning('Auto-forward on incoming document intake failed: no office is designated as the Records Section.', [
                'document_id' => $document->id,
                'uploaded_by' => $user->id,
            ]);

            Notification::send(User::role('admin')->get(), new DocumentEvent(
                $document,
                'Auto-forward failed',
                "\"{$document->title}\" could not be forwarded to Records Section — no office is designated as the Records Section. Designate one under Offices.",
                'document.auto_forward_failed',
            ));

            return false;
        }

        DB::transaction(function () use ($document, $user, $recordsId, $request) {
            $routing = DocumentRouting::create([
                'document_id' => $document->id,
                'routed_by'   => $user->id,
                'is_rush'     => false,
                'remarks'     => IncomingDocumentController::AUTO_FORWARD_REMARKS,
                'routed_at'   => now(),
            ]);

            DocumentRoutingRecipient::create([
                'routing_id'  => $routing->id,
                'division_id' => $recordsId,
                'action'      => RoutingAction::File,
                'status'      => RecipientStatus::Pending,
            ]);

            $document->update(['status' => DocumentStatus::Routed]);

            AuditLogger::log($user, $document, 'document.routed', $request, [
                'auto'            => true,
                'recipient_count' => 1,
            ]);

            NotificationDispatcher::documentRouted($routing);
            NotificationDispatcher::incomingDocumentUploadedBySecretary($document);
        });

        return true;
    }
}
