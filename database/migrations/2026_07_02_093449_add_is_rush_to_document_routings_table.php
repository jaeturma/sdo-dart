<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_routings', function (Blueprint $table) {
            $table->boolean('is_rush')->default(false)->after('routed_by');
        });
    }

    public function down(): void
    {
        Schema::table('document_routings', function (Blueprint $table) {
            $table->dropColumn('is_rush');
        });
    }
};
