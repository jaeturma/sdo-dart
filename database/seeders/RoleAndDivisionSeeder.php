<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RoleAndDivisionSeeder extends Seeder
{
    public function run(): void
    {
        // Roles. 'kmt_focal' is not a primary/selectable role — it's an
        // additional role layered on top of 'originator' (see
        // DocumentPolicy::createOutgoing()) via a separate checkbox in the
        // Users admin UI, never the main role <select>.
        //
        // 'detailed_staff' ("Detailed Official") is for staff temporarily
        // detailed/seconded to the Regional Office from elsewhere (e.g. a
        // School Division Office) who don't fit anywhere in this app's
        // Division hierarchy — assigned with no division_id. They can
        // originate their own outgoing documents (routed the same as a
        // division chief's own upload — see OutgoingDocumentController::
        // initialStatus()) and receive incoming documents routed to them by
        // the RD/her ORD secretary (see RoutingCandidateResolver), but
        // cannot issue routings themselves and cannot upload incoming
        // documents — see DocumentPolicy::createOutgoing()/route().
        $roles = ['admin', 'originator', 'unit_head', 'division_chief', 'ard', 'rd', 'secretary', 'kmt_focal', 'detailed_staff'];
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Top-level divisions
        $topLevel = [
            ['code' => 'ORD',  'name' => 'Office of the Regional Director'],
            ['code' => 'CLMD', 'name' => 'Curriculum and Learning Management Division'],
            ['code' => 'ESSD', 'name' => 'Education Support Services Division'],
            ['code' => 'QAD',  'name' => 'Quality Assurance Division'],
            ['code' => 'FTAD', 'name' => 'Field Technical Assistance Division'],
            ['code' => 'HRDD', 'name' => 'Human Resource Development Division'],
            ['code' => 'PPRD', 'name' => 'Planning and Policy Research Division'],
            ['code' => 'AD',   'name' => 'Administrative Division'],
            ['code' => 'FD',   'name' => 'Finance Division'],
        ];

        foreach ($topLevel as $attrs) {
            Division::firstOrCreate(['code' => $attrs['code']], ['name' => $attrs['name']]);
        }

        // Sub-units under ORD
        $ord = Division::where('code', 'ORD')->first();
        $ordUnits = [
            ['code' => 'ORDP', 'name' => 'ORD Proper'],
            ['code' => 'ICTU', 'name' => 'Information and Communications Technology Unit'],
            ['code' => 'LU',   'name' => 'Legal Unit'],
            ['code' => 'PAU',  'name' => 'Public Affairs Unit'],
        ];

        foreach ($ordUnits as $attrs) {
            Division::firstOrCreate(
                ['code' => $attrs['code']],
                ['name' => $attrs['name'], 'parent_id' => $ord->id]
            );
        }

        // Seed a local admin user for first-run dev access
        if (App::isLocal()) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@drs.local'],
                [
                    'name'              => 'System Admin',
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            $admin->assignRole('admin');
        }
    }
}
