<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // A confirmation step Records Section performs after releasing —
            // "I downloaded the released PDF and emailed it out." Mirrors
            // released_at/released_by; kept as a supplementary flag rather
            // than a new DocumentStatus since it doesn't gate any further
            // workflow action, just records that it happened.
            $table->timestamp('emailed_at')->nullable();
            $table->foreignId('emailed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('emailed_by');
            $table->dropColumn('emailed_at');
        });
    }
};
