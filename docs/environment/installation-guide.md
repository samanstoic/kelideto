# راهنمای جامع نصب و اجرای محلی و توسعه (Local Development & Deployment Guide)
**پروژه:** پلتفرم آنلاین خدمات کلیدسازی KeyPro  
**نسخه:** ۱.۰.۰  
**فاز:** فاز یک (پی‌ریزی اسکلت و سلامت سیستم)  

---

## ۱. پیش‌نیازهای نرم‌افزاری (Prerequisites)

برای اجرای کامل سیستم در محیط توسعه به ابزارهای زیر نیاز دارید:
- **PHP 8.3+** با اکستنشن‌های `pdo_pgsql`, `pgsql`, `zip`, `bcmath`, `curl`, `mbstring`
- **Composer 2.8+**
- **Node.js v20+ / v26+** و **NPM**
- **Docker & Docker Compose** (اختیاری جهت اجرای خودکار پایگاه داده PostGIS و Redis)
- **PostgreSQL 16 + PostGIS 3.4** (در صورت عدم استفاده از داکر)

---

## ۲. روش راه‌اندازی در سیستم‌عامل ویندوز (Windows Setup)

### گام اول: شبیه‌سازی متغیرهای محیطی
```powershell
Copy-Item .env.example .env
& "C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe" artisan key:generate
```

### گام دوم: نصب وابستگی‌های بک‌اند
```powershell
& "C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe" "C:\laragon\bin\composer\composer.phar" install
```

### گام سوم: اجرای میگریشن‌های دیتابیس
```powershell
& "C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe" artisan migrate
```

### گام چهارم: نصب وابستگی‌های فرانت‌اند و ساخت بیلد
```powershell
npm install
npm run build
```

### گام پنجم: اجرای سرورهای توسعه
- **ترمینال ۱ (بک‌اند):**
  ```powershell
  & "C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe" artisan serve --host=127.0.0.1 --port=8001
  ```
- **ترمینال ۲ (فرانت‌اند با HMR بلادرنگ):**
  ```powershell
  npm run dev
  ```
- آدرس دسترسی مرورگر: `http://127.0.0.1:8001`
- آدرس پایش سلامت سرور: `http://127.0.0.1:8001/api/health`

---

## ۳. روش راه‌اندازی در لینوکس و مک (Linux / Ubuntu Setup)

```bash
# ۱. کلون و ایجاد فایل تنظیمات
cp .env.example .env
php artisan key:generate

# ۲. نصب وابستگی‌ها
composer install --prefer-dist
npm ci

# ۳. اجرای مایگریشن‌ها
php artisan migrate

# ۴. بیلد استاتیک فرانت‌اند یا اجرای محیط توسعه
npm run build
# یا
npm run dev

# ۵. بالا آوردن سرور بک‌اند
php artisan serve
```

---

## ۴. روش راه‌اندازی کانتینری با داکر (Docker & Docker Compose)

فایل `docker-compose.yml` سرویس‌های PostGIS 16-3.4، Redis 7.2، اپلیکیشن لاراول و scheduler لاراول را مدیریت می‌کند. scheduler برای پاک‌سازی telemetry منقضی و اجرای jobهای زمان‌بندی‌شده لازم است:

پیش از اجرا، `APP_KEY` را با `php artisan key:generate` بسازید و `INITIAL_SETUP_TOKEN` و `DB_PASSWORD` را با مقادیر تصادفیِ محرمانه در `.env` تنظیم کنید. Compose بدون این مقادیر شروع نمی‌شود؛ پیش‌فرض‌های نمونه برای محیط عملیاتی نیستند. پورت‌های برنامه، دیتابیس و Redis فقط روی loopback در دسترس‌اند.

```bash
# راه‌اندازی تمامی کانتینرها در پس‌زمینه
docker compose up -d --build

# بررسی وضعیت سلامت سرویس‌ها
docker compose ps

# اجرای مایگریشن‌ها درون کانتینر
docker compose exec keypro-app php artisan migrate
```

---

## ۵. اجرای آزمون‌های خودکار و کنترل کیفیت (QA & Testing)

```bash
# اجرای تست‌های واحد و فیچر بک‌اند
php artisan test

# تست کیفیت کد با ESLint
npm run lint

# تست ماژول‌ها و رفتارهای فرانت‌اند با Vitest
npm run test

# ساخت assetهای Production
npm run build
```

## ۶. ورود مدیر با Passkey

ورود مدیر علاوه بر گذرواژه به Passkey/WebAuthn با تأیید کاربر نیاز دارد. گذرواژه به‌تنهایی هیچ توکن مدیریتی صادر نمی‌کند. نخستین ورود معتبر مدیر، ثبت Passkey را در همان نشست کوتاه‌عمر آغاز می‌کند؛ ورودهای بعدی باید assertion همان حساب را نیز تأیید کنند. مرورگر باید WebAuthn را پشتیبانی کند؛ برای محیط محلی از مبدأ `http://127.0.0.1:8001` و در تولید فقط از HTTPS استفاده کنید.

برای تولید، مقادیر را با دامنهٔ واقعی جایگزین و secret را با مولد رمزنگاری‌شده بسازید:

```dotenv
PASSKEY_RP_ID=your-domain.example
PASSKEY_ALLOWED_ORIGINS=https://your-domain.example
PASSKEYS_USER_HANDLE_SECRET=<at-least-32-random-characters>
```

`PASSKEY_RP_ID` باید دامنهٔ پایهٔ مبدأهای مجاز باشد؛ فهرست originها را با جداکنندهٔ ویرگول بنویسید. کلید `PASSKEYS_USER_HANDLE_SECRET` را پایدار و محرمانه نگه دارید؛ تغییر آن، شناسهٔ WebAuthn کاربران را عوض می‌کند. وضعیت نصب، وجود دامنه و originهای سازگار را بررسی می‌کند و در تولید پیکربندی صریح را لازم می‌داند. migration جدول `passkeys` همراه migrationهای پروژه اجرا می‌شود.

OTP عمومی فقط برای مشتری و متخصص است و راه جایگزین ورود مدیر نیست. در این نسخه، اگر تمام Passkeyهای یک مدیر از دست بروند، بازیابی خودکار در UI ارائه نمی‌شود؛ دسترسی باید از فرایند عملیاتی احراز هویت و بازیابی کنترل‌شدهٔ سازمان انجام شود و هرگز با خاموش‌کردن MFA در تولید جایگزین نشود.
