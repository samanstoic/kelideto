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
            ['name' => 'bookings.dispatch_manual', 'display_name' => 'اعزام دستی سفارش', 'module' => 'Dispatch'],
            ['name' => 'technicians.verify_kyc', 'display_name' => 'تایید مدارک هویتی کلیدساز', 'module' => 'Technician'],
            ['name' => 'finance.payout_approve', 'display_name' => 'تایید تسویه حساب بانکی', 'module' => 'Financial'],
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
    }
}
