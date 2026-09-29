<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reclaims disk space from soft-deleted documents' physical files, once
// past config('documents.file_retention_days'). See
// App\Console\Commands\PurgeDeletedDocumentFiles — runs unattended (no
// confirm() prompt), gated only by --apply. Requires an OS-level trigger
// for Laravel's scheduler to actually run (`* * * * * php artisan
// schedule:run`) — not something this repo can set up on its own.
Schedule::command('documents:purge-deleted-files --apply')
    ->daily()
    ->withoutOverlapping();
