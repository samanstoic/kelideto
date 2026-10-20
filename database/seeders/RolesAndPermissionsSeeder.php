<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed the 8 core application roles and permissions.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'customer',
                'display_name' => 'مشتری متقاضی خدمات',
                'description' => 'امکان ثبت سفارش، انتخاب آدرس، مشاهده موقعیت متخصص و پرداخت آنلاین',
            ],
            [
                'name' => 'technician',
                'display_name' => 'متخصص و کلیدساز تایید صلاحیت‌شده',
                'description' => 'پذیرش سفارشات، ارسال تله‌متری موقعیت، ثبت احراز هویت ملک و ثبت فاکتور',
            ],
            [
                'name' => 'operator',
                'display_name' => 'اپراتور پشتیبانی و مرکز تماس',
                'description' => 'پاسخگویی به تیکت‌ها، حل اختلاف در محل و پیگیری سفارش‌های معلق',
            ],
            [
                'name' => 'dispatcher',
                'display_name' => 'مسئول اعزام و دیسپچ',
                'description' => 'پایش نقشه زنده شهر و اعزام دستی متخصصین در شرایط اضطراری',
            ],
            [
                'name' => 'support',
                'display_name' => 'کارشناس امور مشتریان',
                'description' => 'بررسی شکایات، حل اختلافات صدمه قفل و استرداد وجه',
            ],
            [
                'name' => 'accountant',
                'display_name' => 'کارشناس مالی و حسابداری',
                'description' => 'بررسی مغایرت بانکی، صدور حواله پایا و تایید تسویه شبای متخصصین',
            ],
            [
                'name' => 'content_manager',
                'display_name' => 'مدیر محتوا و سئو',
                'description' => 'مدیریت کاتالوگ خدمات، مقالات آموزشی امنیت و سوالات متداول',
            ],
            [
                'name' => 'super_admin',
                'display_name' => 'مدیر ارشد سامانه',
                'description' => 'دسترسی کامل سیستمی، تنظیم ضرایب قیمت‌گذاری، ممیزی لاگ‌ها و تایید مدارک',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                [
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Standard permissions
        $permissions = [
            ['name' => 'bookings.create', 'display_name' => 'ثبت سفارش جدید', 'module' => 'Booking'],
            ['name' => 'bookings.view_own', 'display_name' => 'مشاهده سفارشات خود', 'module' => 'Booking'],
            ['name' => 'bookings.view_assigned_location', 'display_name' => 'مشاهده نشانی سفارش فعال واگذارشده', 'module' => 'Booking'],
            ['name' => 'bookings.dispatch_manual', 'display_name' => 'اعزام دستی سفارش', 'module' => 'Dispatch'],
            ['name' => 'technicians.verify_kyc', 'display_name' => 'تایید مدارک هویتی کلیدساز', 'module' => 'Technician'],
            ['name' => 'finance.payout_approve', 'display_name' => 'تایید تسویه حساب بانکی', 'module' => 'Financial'],
            ['name' => 'finance.refund', 'display_name' => 'ثبت بازپرداخت مالی', 'module' => 'Financial'],
            ['name' => 'finance.reports.view', 'display_name' => 'مشاهده گزارش‌های مالی', 'module' => 'Financial'],
            ['name' => 'finance.reconciliation.run', 'display_name' => 'اجرای تطبیق تراکنش‌ها', 'module' => 'Financial'],
            ['name' => 'admin.dashboard.view', 'display_name' => 'مشاهده داشبورد عملیاتی', 'module' => 'Admin'],
            ['name' => 'admin.users.manage', 'display_name' => 'مدیریت کاربران و نقش‌ها', 'module' => 'Admin'],
            ['name' => 'admin.technicians.manage', 'display_name' => 'مدیریت متخصصان', 'module' => 'Admin'],
            ['name' => 'admin.bookings.view', 'display_name' => 'مدیریت سفارش‌ها', 'module' => 'Admin'],
            ['name' => 'admin.map.view', 'display_name' => 'مشاهده نقشه عملیاتی مجاز', 'module' => 'Admin'],
            ['name' => 'admin.catalog.manage', 'display_name' => 'مدیریت کاتالوگ و اعزام', 'module' => 'Admin'],
            ['name' => 'admin.support.manage', 'display_name' => 'مدیریت پشتیبانی', 'module' => 'Admin'],
            ['name' => 'admin.content.manage', 'display_name' => 'مدیریت محتوا', 'module' => 'Admin'],
            ['name' => 'admin.settings.manage', 'display_name' => 'مدیریت تنظیمات عمومی', 'module' => 'Admin'],
            ['name' => 'admin.audit.view', 'display_name' => 'مشاهده لاگ امنیتی', 'module' => 'Admin'],
            ['name' => 'admin.reports.view', 'display_name' => 'دسترسی به گزارش‌ها و خروجی‌ها', 'module' => 'Admin'],
            ['name' => 'admin.access.manage', 'display_name' => 'مدیریت سیاست‌های دسترسی', 'module' => 'Admin'],
            ['name' => 'audit.view_logs', 'display_name' => 'مشاهده لاگ‌های حسابرسی امنیتی', 'module' => 'Audit'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $perm['name']],
                [
                    'display_name' => $perm['display_name'],
                    'module' => $perm['module'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        foreach ([
            ['role' => 'customer', 'permission' => 'bookings.create'],
            ['role' => 'customer', 'permission' => 'bookings.view_own'],
            ['role' => 'technician', 'permission' => 'bookings.view_assigned_location'],
            ['role' => 'accountant', 'permission' => 'finance.payout_approve'],
            ['role' => 'accountant', 'permission' => 'finance.refund'],
            ['role' => 'accountant', 'permission' => 'finance.reports.view'],
            ['role' => 'accountant', 'permission' => 'finance.reconciliation.run'],
            ['role' => 'super_admin', 'permission' => 'finance.payout_approve'],
            ['role' => 'super_admin', 'permission' => 'finance.refund'],
            ['role' => 'super_admin', 'permission' => 'finance.reports.view'],
            ['role' => 'super_admin', 'permission' => 'finance.reconciliation.run'],
            ['role' => 'super_admin', 'permission' => 'admin.dashboard.view'],
            ['role' => 'super_admin', 'permission' => 'admin.users.manage'],
            ['role' => 'super_admin', 'permission' => 'admin.technicians.manage'],
            ['role' => 'super_admin', 'permission' => 'admin.bookings.view'],
            ['role' => 'super_admin', 'permission' => 'admin.map.view'],
            ['role' => 'super_admin', 'permission' => 'admin.catalog.manage'],
            ['role' => 'super_admin', 'permission' => 'admin.support.manage'],
            ['role' => 'super_admin', 'permission' => 'admin.content.manage'],
            ['role' => 'super_admin', 'permission' => 'admin.settings.manage'],
            ['role' => 'super_admin', 'permission' => 'admin.audit.view'],
            ['role' => 'super_admin', 'permission' => 'admin.reports.view'],
            ['role' => 'super_admin', 'permission' => 'admin.access.manage'],
            ['role' => 'operator', 'permission' => 'admin.dashboard.view'],
            ['role' => 'operator', 'permission' => 'admin.users.manage'],
            ['role' => 'operator', 'permission' => 'admin.technicians.manage'],
            ['role' => 'operator', 'permission' => 'admin.bookings.view'],
            ['role' => 'operator', 'permission' => 'admin.map.view'],
            ['role' => 'operator', 'permission' => 'admin.catalog.manage'],
            ['role' => 'operator', 'permission' => 'admin.support.manage'],
            ['role' => 'operator', 'permission' => 'admin.reports.view'],
            ['role' => 'content_manager', 'permission' => 'admin.dashboard.view'],
            ['role' => 'content_manager', 'permission' => 'admin.catalog.manage'],
            ['role' => 'content_manager', 'permission' => 'admin.content.manage'],
            ['role' => 'dispatcher', 'permission' => 'admin.dashboard.view'],
            ['role' => 'dispatcher', 'permission' => 'admin.bookings.view'],
            ['role' => 'dispatcher', 'permission' => 'admin.map.view'],
            ['role' => 'dispatcher', 'permission' => 'admin.catalog.manage'],
            ['role' => 'support', 'permission' => 'admin.dashboard.view'],
            ['role' => 'support', 'permission' => 'admin.support.manage'],
            ['role' => 'accountant', 'permission' => 'admin.dashboard.view'],
            ['role' => 'accountant', 'permission' => 'admin.reports.view'],
        ] as $grant) {
            $roleId = DB::table('roles')->where('name', $grant['role'])->value('id');
            $permissionId = DB::table('permissions')->where('name', $grant['permission'])->value('id');
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }
}
