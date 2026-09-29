<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Defaults true so existing signed documents keep showing up in
            // Records Section's "Ready to Release" queue exactly as before —
            // only newly-submitted outgoing documents are required to make
            // an explicit choice (enforced in StoreOutgoingDocumentRequest).
            $table->boolean('requires_release')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('requires_release');
        });
    }
};
