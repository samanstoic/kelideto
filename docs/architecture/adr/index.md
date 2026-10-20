# فهرست تصمیم‌های معماری (Architecture Decision Records - ADR)
**پروژه:** سامانه آنلاین کلیدسازی KeyPro  
**فرمت:** استانداردهای مایکل نیگارد (Michael Nygard format)

| شماره شناسه | عنوان تصمیم معماری | وضعیت | تاریخ تصویب | ماژول‌های مرتبط |
|---|---|---|---|---|
| [ADR-001](file:///e:/Downloads/code/Kelideto.com/docs/architecture/adr/0001-choice-of-backend-laravel.md) | انتخاب PHP 8.3 و فریم‌ورک Laravel 11 برای هسته بک‌اند | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | کلیه ماژول‌های بک‌اند |
| [ADR-002](./0002-choice-of-frontend-react-typescript.md) | انتخاب اولیه React، TypeScript و Vite برای فرانت‌اند | منسوخ‌شده؛ جایگزین‌شده با ADR-009 | ۲۰۲۶-۱۰ | تصمیم تاریخی فرانت‌اند |
| [ADR-003](file:///e:/Downloads/code/Kelideto.com/docs/architecture/adr/0003-choice-of-database-postgresql-postgis.md) | انتخاب PostgreSQL 16 و اکستنشن مکانی PostGIS 3.4 برای پایگاه داده اصلی | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | دیتابیس، GIS، دیسپچ، ردیابی |
| [ADR-004](file:///e:/Downloads/code/Kelideto.com/docs/architecture/adr/0004-architectural-style-modular-monolith.md) | برگزیدن سبک معماری Modular Monolith با مرزبندی قطعی دامنه‌ها | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | ساختار کل سیستم |
| [ADR-005](file:///e:/Downloads/code/Kelideto.com/docs/architecture/adr/0005-pwa-and-offline-capabilities.md) | استراتژی اپلیکیشن وب پیش‌رونده (PWA) و مرزهای واقع‌بینانه عملکرد آفلاین | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | PWA مشتری و متخصص |
| [ADR-006](file:///e:/Downloads/code/Kelideto.com/docs/architecture/adr/0006-mapping-gis-strategy-leaflet-osm.md) | بهره‌گیری از Leaflet.js و کاشی‌های OpenStreetMap بدون کلیدهای انحصاری | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | نقشه، رهگیری، موقعیت‌یابی |
| [ADR-007](file:///e:/Downloads/code/Kelideto.com/docs/architecture/adr/0007-state-management-and-communication.md) | معماری صف‌ها، حافظه نهان و سوکت‌های ارتباط لحظه‌ای با پشتیبانی Fallback | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | نوتیفیکیشن، ردیابی، تراکنش |
| [ADR-008](file:///e:/Downloads/code/Kelideto.com/docs/architecture/adr/0008-payment-and-sms-adapter-architecture.md) | معماری چند‌آداپتوری قابل پیکربندی برای درگاه‌های پرداخت بانکی و پیامک | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | مالی، کیف‌پول، احراز هویت |
| [ADR-009](./0009-vanilla-javascript-frontend.md) | مهاجرت فرانت‌اند به HTML، CSS و JavaScript ماژولار بدون React/TypeScript | پذیرفته‌شده (Accepted) | ۲۰۲۶-۱۰ | تمام پورتال‌های وب و PWA |
