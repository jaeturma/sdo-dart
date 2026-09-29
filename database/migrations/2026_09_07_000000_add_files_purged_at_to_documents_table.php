<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Set by `documents:purge-deleted-files` once a soft-deleted
            // document's physical files (file_path + every related signed/
            // stamped file) have been removed from the `documents` disk.
            // The row itself, and its document_signatures/
            // document_release_stamps rows, are never touched — this only
            // marks that the bytes are gone, so a re-run doesn't
            // needlessly re-attempt Storage::delete() on paths already
            // reclaimed.
            $table->timestamp('files_purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('files_purged_at');
        });
    }
};
