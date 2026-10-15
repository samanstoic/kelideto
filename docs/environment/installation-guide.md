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
  & "C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe" artisan serve --port=8000
  ```
- **ترمینال ۲ (فرانت‌اند با HMR بلادرنگ):**
  ```powershell
  npm run dev
  ```
- آدرس دسترسی مرورگر: `http://localhost:8000`  
- آدرس پایش سلامت سرور: `http://localhost:8000/api/health`

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

فایل `docker-compose.yml` کلیه سرویس‌های موردنیاز (PostGIS 16-3.4، Redis 7.2 و اپلیکیشن لاراول) را به همراه Health Check خودکار مدیریت می‌کند:

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

# تست اعتبارسنجی نوع‌ها (Strict TypeScript)
npm run typecheck

# تست کیفیت کد با ESLint
npm run lint

# تست کامپوننت‌های فرانت‌اند با Vitest
npm run test
```
