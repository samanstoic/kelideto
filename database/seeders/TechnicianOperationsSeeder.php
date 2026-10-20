<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TechnicianOperationsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'technicians.manage_dispatch' => ['مدیریت پیشنهاد اعزام', 'Dispatch'],
            'technicians.verify_kyc' => ['بررسی مدارک متخصص', 'Technician'],
            'bookings.dispatch_manual' => ['تخصیص دستی سفارش', 'Dispatch'],
        ];
        foreach ($permissions as $name => [$displayName, $module]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'display_name' => $displayName,
                    'module' => $module,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        foreach ([
            ['super_admin', 'technicians.manage_dispatch'],
            ['dispatcher', 'technicians.manage_dispatch'],
            ['super_admin', 'bookings.dispatch_manual'],
            ['operator', 'bookings.dispatch_manual'],
            ['super_admin', 'technicians.verify_kyc'],
        ] as [$roleName, $permissionName]) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');
            if ($roleId && $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
}
