<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            'booking.status_changed' => [
                'title' => 'به‌روزرسانی سفارش',
                'body' => 'وضعیت سفارش {{booking_code}} برای خدمت {{service_name}} تغییر کرد: {{status_label}}.',
                'sms' => 'کلیدتو: وضعیت سفارش {{booking_code}} تغییر کرد: {{status_label}}.',
            ],
            'booking.cancelled' => [
                'title' => 'لغو سفارش',
                'body' => 'سفارش {{booking_code}} برای خدمت {{service_name}} لغو شد.',
                'sms' => 'کلیدتو: سفارش {{booking_code}} لغو شد.',
            ],
            'booking.delayed' => [
                'title' => 'تأخیر در زمان سفارش',
                'body' => 'زمان سفارش {{booking_code}} تغییر کرد. زمان جدید: {{scheduled_at}}.',
                'sms' => 'کلیدتو: زمان سفارش {{booking_code}} با تأخیر به {{scheduled_at}} تغییر کرد.',
            ],
            'booking.rescheduled' => [
                'title' => 'تغییر زمان سفارش',
                'body' => 'زمان سفارش {{booking_code}} به {{scheduled_at}} تغییر کرد.',
                'sms' => 'کلیدتو: زمان سفارش {{booking_code}} به {{scheduled_at}} تغییر کرد.',
            ],
            'dispatch.offer.issued' => [
                'title' => 'پیشنهاد مأموریت جدید',
                'body' => 'پیشنهاد مأموریت {{booking_code}} برای خدمت {{service_name}} برای شما ثبت شد. مهلت پاسخ تا {{expires_at}} است.',
                'sms' => 'کلیدتو: پیشنهاد مأموریت {{booking_code}} ثبت شد. برای جزئیات وارد برنامه شوید.',
            ],
        ];

        foreach ($templates as $eventKey => $messages) {
            foreach (['in_app', 'sms', 'web_push'] as $channel) {
                DB::table('notification_templates')->updateOrInsert(
                    ['event_key' => $eventKey, 'channel' => $channel],
                    [
                        'title_fa' => $channel === 'sms' ? null : $messages['title'],
                        'body_fa' => $channel === 'sms' ? $messages['sms'] : $messages['body'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        $permissions = [
            'notifications.manage' => ['مدیریت اعلان‌های عملیاتی', 'Notification'],
            'notifications.templates.manage' => ['مدیریت قالب‌های اعلان', 'Notification'],
        ];
        foreach ($permissions as $name => [$displayName, $module]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'display_name' => $displayName,
                    'module' => $module,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        foreach (['super_admin', 'operator', 'dispatcher'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            $permissionId = DB::table('permissions')->where('name', 'notifications.manage')->value('id');
            if ($roleId && $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'notifications.templates.manage')->value('id');
        if ($roleId && $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }
}
