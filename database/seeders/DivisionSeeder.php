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
        // Division Office's admin adds any sub-units (e.g. Records Section,
        // Legal Unit, ICT Unit) afterward via the Office Management screen
        // (DivisionController/Divisions/Form.vue), and designates which one
        // is the Records Section there.
        //
        // Several behaviors (the secretary's "acts on the SDS's behalf"
        // powers, an OSDS-sub-unit head's review-skip, etc.) key off the
        // exact code 'OSDS' (see isOsdsSecretary() and its duplicates), so
        // Office Management refuses to change it — see Division::isOsds().
        $topLevel = [
            ['name' => 'Office of the Schools Division Superintendent', 'code' => 'OSDS'],
            ['name' => 'Curriculum Implementation Division', 'code' => 'CID'],
            ['name' => 'School Governance and Operations Division', 'code' => 'SGOD'],
        ];

        foreach ($topLevel as $data) {
            Division::create($data);
        }
    }
}
