# سامانهٔ اعلان فاز ده

## کانال‌ها و رویدادها

اعلان‌های درون‌برنامه‌ای در `notifications_log` به‌عنوان صندوق ورودی نگهداری می‌شوند. پیامک و Web Push از همان صف ارسال می‌شوند؛ هر ثبت outbox کلید idempotency یکتا دارد. کانال‌های پشتیبانی‌شده عبارت‌اند از `in_app`، `sms` و `web_push`.

رویدادها:

- `booking.status_changed`
- `booking.cancelled`
- `booking.delayed`
- `booking.rescheduled`
- `dispatch.offer.issued`

متن‌های فارسی در جدول `notification_templates` نگهداری و توسط مدیر ارشد ویرایش می‌شوند. فقط متغیرهای محدود کد سفارش، نام خدمت، وضعیت و زمان مجازند. مختصات، نشانی، شرح درخواست، اطلاعات ورود و شماره تلفن در متن اعلان قرار نمی‌گیرند.

## تنظیم و اجرا

```dotenv
QUEUE_CONNECTION=database
SMS_DRIVER=kavenegar
KAVENEGAR_API_KEY=
KAVENEGAR_SENDER=10008663
WEBPUSH_VAPID_PUBLIC_KEY=
WEBPUSH_VAPID_PRIVATE_KEY=
WEBPUSH_VAPID_SUBJECT=mailto:support@kelideto.com
```

پس از مهاجرت پایگاه داده، قالب‌ها و مجوزها را با `php artisan db:seed --class=Database\\Seeders\\NotificationTemplatesSeeder` ایجاد کنید. صف worker باید به‌طور دائم فعال باشد: `php artisan queue:work --queue=default --tries=5`. زمان‌بندی Laravel برای انقضای صف لازم نیست؛ worker صف را پردازش می‌کند.

کلیدهای واقعی Kavenegar و کلیدهای VAPID را فقط در محیط secret manager/متغیرهای محیطی قرار دهید. در نبود کلید، API وضعیت سرویس اعلان را `disconnected` گزارش می‌کند. SMS mock صرفاً شبیه‌سازی توسعه است و پیام واقعی تحویل نمی‌دهد.

## وضعیت ارسال و تحویل

`provider_status` ارسال صف، retry، پذیرش provider یا شکست ارسال را نشان می‌دهد. `delivery_status` جداست: برای صندوق درون‌برنامه‌ای پس از ثبت `delivered` است؛ برای SMS و Web Push پس از پذیرش provider همچنان `unknown` می‌ماند تا زمانی که رسید تحویل معتبر از provider دریافت شود. این پروژه webhook رسید تحویل ندارد و پاسخ HTTP provider را تحویل به دستگاه تلقی نمی‌کند.

تلاش مجدد صف با backoff برابر ۳۰ ثانیه، ۲ دقیقه، ۱۰ دقیقه و ۳۰ دقیقه انجام می‌شود. `attempts`، `next_attempt_at` و `last_error` برای پیگیری نگهداری می‌شوند. پس از پنج تلاش، وضعیت ارسال `failed` می‌شود. محدودیت دفعات به‌ازای کاربر، رویداد و کانال و در بازهٔ یک‌ساعته اعمال می‌شود.

## API

- `GET /api/notifications` و `PATCH /api/notifications/{id}/read`: صندوق فقط برای حساب جاری.
- `GET|PUT /api/notifications/preferences`: ترجیحات کانال/رویداد و سقف ساعتی حساب جاری.
- `GET /api/notifications/web-push/public-key`: کلید عمومی VAPID؛ subscription تنها بعد از رضایت صریح مرورگر ثبت می‌شود.
- `POST|DELETE /api/notifications/web-push-subscriptions`: ثبت/لغو subscription متعلق به حساب جاری.
- `GET /api/admin/notifications/services`: وضعیت سرویس‌ها برای اپراتور، دیسپچر یا مدیر ارشد.
- `GET|PATCH /api/admin/notifications/templates`: مشاهده و ویرایش قالب‌ها فقط برای مدیر ارشد.
- `POST /api/admin/bookings/{id}/schedule`: ثبت تغییر زمان/تأخیر توسط نقش مجاز و ارسال اعلان به طرف‌های مرتبط.
