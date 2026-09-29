<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        // Wipe placeholder data; nullOnDelete on users.division_id keeps user rows intact
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Division::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Top-level divisions
        $topLevel = [
            ['name' => 'Office of the Regional Director',          'code' => 'ORD'],
            ['name' => 'Curriculum & Learning Management Division', 'code' => 'CLMD'],
            ['name' => 'Education Support Services Division',       'code' => 'ESSD'],
            ['name' => 'Field Technical Assistance Division',       'code' => 'FTAD'],
            ['name' => 'Quality Assurance Division',                'code' => 'QAD'],
            ['name' => 'Policy, Planning & Research Division',      'code' => 'PPRD'],
            ['name' => 'Human Resource & Development Division',     'code' => 'HRDD'],
            ['name' => 'Finance Division',                          'code' => 'FD'],
            ['name' => 'Administrative Division',                   'code' => 'AD'],
        ];

        foreach ($topLevel as $data) {
            Division::create($data);
        }

        // Units / sections under ORD
        $ord = Division::where('code', 'ORD')->first();
        $ordUnits = [
            'Legal Unit',
            'Public Affairs Unit',
            'Information and Communication Technology Unit',
            'Office of the Regional Director Proper',
            'Office of the Assistant Regional Director',
        ];
        foreach ($ordUnits as $name) {
            Division::create([
                'name' => $name,
                // Unlike its ORD-sub-unit siblings, OARD has no unit head of
                // its own — its staff report directly to the ARD — so it
                // needs a stable code (matching ORD/ORDP/RS's convention) for
                // OutgoingDocumentController::initialStatus() to route their
                // uploads straight to ARD review.
                'code' => $name === 'Office of the Assistant Regional Director' ? 'OARD' : null,
                'parent_id' => $ord->id,
            ]);
        }

        // Sections / units under Administrative Division
        $ad = Division::where('code', 'AD')->first();
        $adUnits = [
            'General Services Unit',
            'Asset Management Section',
            'Cash Section',
            'Personnel Section',
            'Procurement Unit',
            'Records Section',
            'Payroll Services Unit',
        ];
        foreach ($adUnits as $name) {
            Division::create([
                'name'      => $name,
                'code'      => $name === 'Records Section' ? 'RS' : null,
                'parent_id' => $ad->id,
            ]);
        }
    }
}
