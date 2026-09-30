<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            // Which office is the Records Section — designated by an admin
            // from Office Management (at most one office at a time; see
            // DivisionController::keepRecordsSectionUnique()) rather than
            // inferred from a magic "RS" division code an SDO admin would
            // have no way of knowing about.
            $table->boolean('is_records_section')->default(false)->after('allow_sds_direct_routing');
        });
    }

    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            $table->dropColumn('is_records_section');
        });
    }
};
