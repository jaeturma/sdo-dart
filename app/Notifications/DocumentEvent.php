<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

// Not ShouldQueue: QUEUE_CONNECTION=database is configured but no queue worker
// is guaranteed to be running — queuing here would mean notifications
// silently never appear. Sending synchronously is a single cheap DB insert.
class DocumentEvent extends Notification
{
    public function __construct(
        private readonly Document $document,
        private readonly string $headline,
        private readonly string $body,
        private readonly string $eventType,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'title' => $this->document->title,
            'headline' => $this->headline,
            'body' => $this->body,
            'event_type' => $this->eventType,
            // Deliberately not an absolute URL — that would bake in whatever
            // host/scheme was active when the notification was created,
            // going stale the moment the app is reached via a different host
            // later (custom domain vs. LAN IP, or a future domain change).
            // The frontend builds the link itself from document_id via
            // Ziggy's route() helper, using the current origin instead.
        ];
    }
}
