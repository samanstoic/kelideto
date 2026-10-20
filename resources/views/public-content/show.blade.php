<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="{{ isset($structuredData['@type']) && $structuredData['@type'] === 'Article' ? 'article' : 'website' }}">
    <meta property="og:title" content="{{ $title }}">
    @if ($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="fa_IR">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    <meta name="theme-color" content="#176f62">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/keypro-192.png">
    @vite(['resources/css/app.css', 'resources/css/theme-lockatlas.css'])
    @if (!empty($structuredData))
        <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @endif
</head>
<body>
    <main class="kp-public-content" dir="rtl">
        <header>
            <a href="/" class="kp-public-brand">کلیدتو</a>
            <nav aria-label="ناوبری اصلی">
                <a href="/services">خدمات</a>
                <a href="/areas">مناطق تحت پوشش</a>
            </nav>
        </header>
        <article>
            <h1>{{ $contentTitle }}</h1>
            @if (!empty($ogImage))
                <img class="kp-public-content-image" src="{{ $ogImage }}" alt="{{ $contentTitle }}"
                     width="{{ $ogImageWidth }}" height="{{ $ogImageHeight }}" loading="lazy" decoding="async">
            @endif
            @if (!empty($excerpt))
                <p class="kp-public-content-excerpt">{{ $excerpt }}</p>
            @endif
            @if (!empty($serviceDetails))
                <dl>
                    <dt>دسته‌بندی</dt>
                    <dd>{{ $serviceDetails->category }}</dd>
                    @if ($serviceDetails->estimated_duration_minutes)
                        <dt>مدت تخمینی خدمت</dt>
                        <dd>حدود {{ (int) $serviceDetails->estimated_duration_minutes }} دقیقه</dd>
                    @endif
                </dl>
                <p>هزینهٔ نهایی بر اساس محل و زمان درخواست و تعرفهٔ فعال محاسبه و پیش از تأیید به شما نمایش داده می‌شود.</p>
            @endif
            @if (!empty($areaDetails))
                <dl>
                    <dt>شهر</dt>
                    <dd>{{ $areaDetails->city_name }}</dd>
                    <dt>محدوده</dt>
                    <dd>{{ $areaDetails->zone_name }}</dd>
                    <dt>شعاع پوشش ثبت‌شده</dt>
                    <dd>{{ rtrim(rtrim(number_format((float) $areaDetails->radius_km, 2, '.', ''), '0'), '.') }} کیلومتر</dd>
                </dl>
                <p>پوشش دقیق سفارش بر اساس موقعیت واردشده و محدوده‌های فعال بررسی می‌شود.</p>
            @endif
            @if (!empty($body))
                <div class="kp-public-content-body">{!! nl2br(e($body)) !!}</div>
            @endif
            @if (!empty($publishedAt))
                <p class="kp-public-content-date">انتشار: <time datetime="{{ $publishedAt }}">{{ \Illuminate\Support\Carbon::parse($publishedAt)->format('Y-m-d') }}</time></p>
            @endif
            <a class="kp-btn kp-btn-primary" href="/booking?frontend=vanilla">ثبت درخواست</a>
        </article>
    </main>
</body>
</html>
