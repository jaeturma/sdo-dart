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
 * Forwards an incoming document to Records Section, exactly the way ORD
 * Proper uploads auto-forward on intake (IncomingDocumentController::store()).
 * Extracted so the same logic can also be used to retroactively fix
 * documents that were uploaded before a division/user misconfiguration was
 * corrected (see documents:diagnose-auto-forward --apply) — both callers
 * must stay identical, or the retroactive fix would produce routings that
 * behave subtly differently from a normal upload.
 *
 * Returns false (and logs a warning) if the Records Section division can't
 * be found, so the caller can surface that instead of silently leaving the
 * document unrouted.
 */
class IncomingAutoForwarder
{
    public static function forward(Document $document, User $user, ?Request $request = null): bool
    {
        $recordsId = Division::recordsSectionId();

        if (! $recordsId) {
            Log::warning('Auto-forward on incoming document intake failed: no division with code "RS" (Records Section) found.', [
                'document_id' => $document->id,
                'uploaded_by' => $user->id,
            ]);

            Notification::send(User::role('admin')->get(), new DocumentEvent(
                $document,
                'Auto-forward failed',
                "\"{$document->title}\" could not be auto-forwarded — no division is coded \"RS\" (Records Section). Check Division setup.",
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
