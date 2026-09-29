<?php

use App\Models\Division;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            // Lets an admin mark a specific sub-unit (e.g. ICTU, Legal Unit,
            // PAU, or a newly-added one like NEAP under HRDD) as one the RD
            // (and her ORD secretary) may route incoming documents directly
            // to the unit head of — replaces a previous hardcoded rule tied
            // to a specific parent division code.
            $table->boolean('allow_rd_direct_routing')->default(false)->after('parent_id');
        });

        // Preserve today's live behavior for the divisions that currently
        // get this ability implicitly via the old "parent is coded ORD"
        // rule (Legal Unit, Public Affairs Unit, ICT Unit) — scoped tightly
        // by parent code so this can never touch any other row.
        $ordId = Division::where('code', 'ORD')->value('id');

        if ($ordId !== null) {
            Division::where('parent_id', $ordId)->update(['allow_rd_direct_routing' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            $table->dropColumn('allow_rd_direct_routing');
        });
    }
};
