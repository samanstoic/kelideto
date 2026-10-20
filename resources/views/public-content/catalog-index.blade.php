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
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="fa_IR">
    <meta name="theme-color" content="#176f62">
    <link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/css/theme-lockatlas.css'])
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
        <section>
            <h1>{{ $heading }}</h1>
            @if ($records->isEmpty())
                <p role="status">در حال حاضر مورد فعالی برای نمایش ثبت نشده است.</p>
            @else
                <div class="kp-public-content-grid">
                    @foreach ($records as $record)
                        @php($href = $recordType === 'service' ? '/services/'.$record->slug : '/areas/'.$record->slug)
                        <article class="kp-public-content-card">
                            <h2><a href="{{ $href }}">{{ $record->title }}@if ($recordType === 'area')، {{ $record->zone_name }}@endif</a></h2>
                            @if ($recordType === 'service')
                                @if ($record->category)
                                    <p>{{ $record->category }}</p>
                                @endif
                                @if ($record->description)
                                    <p>{{ $record->description }}</p>
                                @endif
                            @else
                                <p>شعاع پوشش ثبت‌شده: {{ rtrim(rtrim(number_format((float) $record->radius_km, 2, '.', ''), '0'), '.') }} کیلومتر</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </main>
</body>
</html>
