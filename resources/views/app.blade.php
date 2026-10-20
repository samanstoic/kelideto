<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} | کلیدتو</title>
    <meta name="description" content="درخواست کلیدساز در محل با کلیدتو: مشاهده ریز قیمت پیش از تایید، متخصصان دارای پروانه، پیگیری لحظه‌ای و پرداخت امن.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/">
    <meta name="theme-color" content="#176f62">
    <meta name="application-name" content="کلیدتو">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="کلیدتو">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/keypro-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <meta property="og:title" content="کلیدساز در محل — قیمت را قبل از تایید ببینید | کلیدتو">
    <meta property="og:description" content="مشاهده ریز قیمت پیش از تایید، متخصصان دارای پروانه و پیگیری لحظه‌ای سفارش.">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}/">
    <meta name="twitter:card" content="summary">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/theme-lockatlas.css', 'resources/js/vanilla/app.js'])
</head>
<body>
    <div id="vanilla-app" class="kp-vanilla-root"></div>
</body>
</html>
