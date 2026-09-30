<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_routings', function (Blueprint $table) {
            // Stamped at creation time (true only for a real, deliberate
            // routing issued by the SDS herself or an OSDS secretary acting in
            // her lieu — never for the OSDS-Proper auto-forward, and never
            // for a division chief/unit head's own onward routing). Recorded
            // as a fact of the routing itself rather than re-derived later
            // from the router's *current* role/division, so staff
            // reassignment (e.g. a secretary later transferred to a
            // different division) can never retroactively change whether an
            // already-issued routing counts as "the SDS's queue was served".
            $table->boolean('is_sds_routing')->default(false)->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('document_routings', function (Blueprint $table) {
            $table->dropColumn('is_sds_routing');
        });
    }
};
