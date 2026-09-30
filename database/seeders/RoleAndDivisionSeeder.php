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
        // detailed/seconded to this Schools Division Office from elsewhere
        // (e.g. a school, or another division office) who don't fit
        // anywhere in this app's Division hierarchy — assigned with no
        // division_id. They can originate their own outgoing documents
        // (routed the same as a division chief's own upload — see
        // OutgoingDocumentController::initialStatus()) and receive incoming
        // documents routed to them by the SDS/her secretary (see
        // RoutingCandidateResolver), but cannot issue routings themselves
        // and cannot upload incoming documents — see
        // DocumentPolicy::createOutgoing()/route().
        $roles = ['admin', 'originator', 'unit_head', 'division_chief', 'asds', 'sds', 'secretary', 'kmt_focal', 'detailed_staff'];
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Top-level divisions. Only these three are seeded — this Schools
        // Division Office's admin adds any sub-units afterward via Office
        // Management. Several behaviors key off the exact code 'OSDS' — see
        // DivisionSeeder.php's comment.
        $topLevel = [
            ['code' => 'OSDS',  'name' => 'Office of the Schools Division Superintendent'],
            ['code' => 'CID',  'name' => 'Curriculum Implementation Division'],
            ['code' => 'SGOD', 'name' => 'School Governance and Operations Division'],
        ];

        foreach ($topLevel as $attrs) {
            Division::firstOrCreate(['code' => $attrs['code']], ['name' => $attrs['name']]);
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
