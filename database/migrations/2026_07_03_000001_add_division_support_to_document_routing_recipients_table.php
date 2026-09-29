<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_routing_recipients', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();

            $table->foreignId('division_id')->nullable()->after('user_id')
                ->constrained('divisions')->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_routing_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('completed_by');

            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
