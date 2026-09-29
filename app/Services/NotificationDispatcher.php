<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Models\Document;
use App\Models\DocumentRouting;
use App\Models\User;
use App\Notifications\DocumentEvent;
use Illuminate\Support\Facades\Notification;

/**
 * Single wiring point for every document-lifecycle notification. Controllers
 * call one of these instead of building Notification::send() calls inline.
 */
class NotificationDispatcher
{
    public static function documentSubmitted(Document $document): void
    {
        Notification::send(ReviewerResolver::forStatus($document), new DocumentEvent(
            $document,
            'New document submitted',
            "\"{$document->title}\" was submitted and is pending your review.",
            'document.submitted',
        ));
    }

    public static function reviewDecision(Document $document, ReviewAction $action): void
    {
        if ($action === ReviewAction::ApprovedForwarded) {
            if ($document->status === DocumentStatus::Approved) {
                self::readyToSign($document);
                return;
            }

            Notification::send(ReviewerResolver::forStatus($document), new DocumentEvent(
                $document,
                'Document forwarded for review',
                "\"{$document->title}\" is now pending your review.",
                'document.forwarded',
            ));
            return;
        }

        if (in_array($action, [ReviewAction::ForComment, ReviewAction::ReturnedForRevision, ReviewAction::Rejected], true)) {
            Notification::send($document->uploader, new DocumentEvent(
                $document,
                ucfirst(str_replace('_', ' ', $action->value)),
                "\"{$document->title}\" was {$action->value}.",
                'review.' . $action->value,
            ));
        }
    }

    private static function readyToSign(Document $document): void
    {
        Notification::send(User::role('secretary')->get(), new DocumentEvent(
            $document,
            'Ready to sign',
            "\"{$document->title}\" was approved and is ready to sign.",
            'document.ready_to_sign',
        ));

        Notification::send($document->uploader, new DocumentEvent(
            $document,
            'Document approved',
            "\"{$document->title}\" was approved.",
            'document.approved',
        ));
    }

    public static function documentSigned(Document $document): void
    {
        // The uploader's supervisor is notified alongside the uploader —
        // they reviewed and forwarded it, so they should know it reached the
        // end of the chain too.
        $recipients = collect([$document->uploader])
            ->merge(self::divisionSupervisors($document))
            ->filter()
            ->unique('id');

        Notification::send($recipients, new DocumentEvent(
            $document,
            'Document signed',
            "\"{$document->title}\" has been signed.",
            'document.signed',
        ));
    }

    /**
     * The division chief who oversees this document's division — checked
     * against both the document's own division and its parent, since some
     * divisions (e.g. Procurement Unit/Personnel Section under Administrative
     * Division) are sub-units with no chief of their own; the actual chief
     * sits one level up (see OutgoingDocumentController::initialStatus() and
     * DocumentScopesTrait::outgoingScope() for the same reasoning applied to
     * status transitions and list visibility). Falls back to the unit head of
     * the document's own division when no chief is found anywhere above it
     * (e.g. an ORD sub-unit, which has no chief at all).
     */
    private static function divisionSupervisors(Document $document): \Illuminate\Support\Collection
    {
        if (! $document->division_id) {
            return collect();
        }

        $chiefDivisionIds = array_filter([$document->division_id, $document->division?->parent_id]);
        $chiefs = User::role('division_chief')->whereIn('division_id', $chiefDivisionIds)->get();

        return $chiefs->isNotEmpty()
            ? $chiefs
            : User::role('unit_head')->where('division_id', $document->division_id)->get();
    }

    /**
     * The secretary's auto-forward-on-intake deliberately doesn't make the RD
     * a recipient (she acts on every incoming document directly rather than
     * acknowledging/completing an assignment of her own — see
     * IncomingDocumentController::autoForward()), so documentRouted() alone
     * never reaches her. Notify her directly instead.
     */
    public static function incomingDocumentUploadedBySecretary(Document $document): void
    {
        Notification::send(User::role('rd')->get(), new DocumentEvent(
            $document,
            'New incoming document',
            "\"{$document->title}\" was uploaded and forwarded to Records Section.",
            'document.received',
        ));
    }

    public static function documentRouted(DocumentRouting $routing): void
    {
        $recipients = $routing->recipients()->with(['user', 'division.users'])->get();

        $users = $recipients
            ->flatMap(fn ($r) => $r->user_id ? collect([$r->user]) : ($r->division?->users ?? collect()))
            ->filter()
            ->unique('id');

        Notification::send($users, new DocumentEvent(
            $routing->document,
            'Document routed to you',
            "\"{$routing->document->title}\" was routed to you.",
            'document.routed',
        ));
    }

    public static function documentReleased(Document $document): void
    {
        Notification::send($document->uploader, new DocumentEvent(
            $document,
            'Document released',
            "\"{$document->title}\" has been released.",
            'document.released',
        ));
    }

    /**
     * Records Section confirmed they downloaded the released PDF and emailed
     * it out — the originating office (uploader + their supervisor) should
     * know it's actually been sent, not just released internally.
     */
    public static function documentEmailed(Document $document): void
    {
        $recipients = collect([$document->uploader])
            ->merge(self::divisionSupervisors($document))
            ->filter()
            ->unique('id');

        Notification::send($recipients, new DocumentEvent(
            $document,
            'Document emailed',
            "\"{$document->title}\" has been emailed to the recipient.",
            'document.emailed',
        ));
    }

    public static function documentCompleted(Document $document): void
    {
        $routedByUsers = $document->routings()->with('routedBy')->get()
            ->pluck('routedBy')
            ->filter()
            ->unique('id');

        Notification::send($routedByUsers, new DocumentEvent(
            $document,
            'Document completed',
            "All recipients have completed \"{$document->title}\".",
            'document.completed',
        ));
    }
}
