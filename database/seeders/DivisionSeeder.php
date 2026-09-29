<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        // Wipe placeholder data; nullOnDelete on users.division_id keeps user
        // rows intact. SET FOREIGN_KEY_CHECKS is MySQL-only syntax — the
        // test suite and a fresh .env.example both default to sqlite, which
        // has no equivalent statement (and doesn't need one: truncate()
        // doesn't trip FK checks there the way it does under MySQL).
        $isMysql = DB::connection()->getDriverName() === 'mysql';

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        Division::truncate();

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Top-level divisions. Only these three are seeded — this Schools
        // Division Office's admin adds any sub-units (e.g. an "OSDS Proper"
        // or Records Section equivalent) afterward via the Office
        // Management screen (DivisionController/Divisions/Form.vue), the
        // same dynamic mechanism already used for allow_rd_direct_routing.
        //
        // OSDS keeps the literal code 'ORD' rather than a renamed one —
        // several behaviors (the secretary's "acts on the SDS's behalf"
        // powers, an ORD-sub-unit head's review-skip, etc.) key off this
        // exact string across ~10 call sites (see isOrdSecretary() and its
        // duplicates). Renaming the code itself would mean updating every
        // one of those call sites for what's ultimately a cosmetic detail —
        // the code is an internal identifier, not a user-facing label (the
        // name field is what's actually shown to staff).
        $topLevel = [
            ['name' => 'Office of the Schools Division Superintendent', 'code' => 'ORD'],
            ['name' => 'Curriculum Implementation Division', 'code' => 'CID'],
            ['name' => 'School Governance and Operations Division', 'code' => 'SGOD'],
        ];

        foreach ($topLevel as $data) {
            Division::create($data);
        }
    }
}
