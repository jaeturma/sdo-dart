<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['type', 'status']);
            $table->index(['division_id', 'type', 'status']);
        });

        Schema::table('document_routings', function (Blueprint $table) {
            $table->index('is_sds_routing');
            $table->index('cancelled_at');
        });

        Schema::table('document_routing_recipients', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['division_id', 'type', 'status']);
        });

        Schema::table('document_routings', function (Blueprint $table) {
            $table->dropIndex(['is_sds_routing']);
            $table->dropIndex(['cancelled_at']);
        });

        Schema::table('document_routing_recipients', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
