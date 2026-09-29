<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('code')
                ->constrained('divisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Division::class, 'parent_id');
            $table->dropColumn('parent_id');
        });
    }
};
