<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * One-time reset between the test/trial period and the official go-live:
 * wipes every document and its related records/audit logs/files, but leaves
 * users, roles, divisions, signing credentials, and app-wide security
 * settings (magic link secret, 2FA enforcement, password policy) untouched.
 */
class ResetDocumentTestData extends Command
{
    protected $signature = 'documents:reset-test-data {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all documents (with their reviews, routings, signatures, release stamps, and control-number counters), audit logs, and notifications — keeps users/roles/divisions/credentials intact.';

    /**
     * Child-to-parent order so no foreign key ever dangles, regardless of
     * DB-level cascade rules. document_control_number_counters is included so
     * go-live control numbers restart at 001 instead of continuing the trial
     * period's series.
     */
    private const TABLES = [
        'notifications',
        'signature_audit_logs',
        'document_routing_recipients',
        'document_routings',
        'document_reviews',
        'document_signature_placements',
        'document_signatures',
        'document_release_stamps',
        'documents',
        'document_control_number_counters',
    ];

    public function handle(): int
    {
        $counts = collect(self::TABLES)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $this->warn('This will permanently delete:');
        foreach ($counts as $table => $count) {
            $this->line("  - {$table}: {$count} row(s)");
        }
        $this->line('  - every file in storage/app/documents');
        $this->newLine();
        $this->info('Users, roles, divisions, signing credentials, and app security settings will NOT be touched.');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->comment('Aborted — nothing was deleted.');
            return self::SUCCESS;
        }

        DB::transaction(function () {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                foreach (self::TABLES as $table) {
                    DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
                }
            }
        });

        File::cleanDirectory(Storage::disk('documents')->path(''));

        $this->info('Done. All document data, audit logs, notifications, and files have been cleared.');

        return self::SUCCESS;
    }
}
