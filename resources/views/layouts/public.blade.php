<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#14161c">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <title>@yield('title', $appName . ' — Bimbingan Tugas Akhir & Kerja Praktik')</title>
    <meta name="description" content="Catat bimbingan, kelola revisi, dan pantau perjalanan Tugas Akhir atau Kerja Praktik dalam satu tempat bersama mahasiswa dan dosen.">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="{{ $appName }}">
    <meta property="og:title" content="{{ $appName }} — Logbook Bimbingan TA & KP">
    <meta property="og:description" content="Logbook, revisi, review dokumen, hingga seminar dan sidang dalam satu alur bimbingan Tugas Akhir dan Kerja Praktik.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ url('/images/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:alt" content="Screenshot dashboard Logbook">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $appName }} — Logbook Bimbingan TA & KP">
    <meta name="twitter:description" content="Logbook, revisi, review dokumen, hingga seminar dan sidang dalam satu alur bimbingan.">
    <meta name="twitter:image" content="{{ url('/images/og-image.png') }}">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32x32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet">
    <style>
        .material-symbols-outlined { user-select: none; vertical-align: middle; font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 24; }
        .icon-sm { font-size: 16px; } .icon-md { font-size: 20px; } .icon-lg { font-size: 24px; }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        try { if (localStorage.getItem('lbta-theme') === 'light') document.documentElement.classList.remove('dark'); } catch (e) {}
    </script>
    <link rel="stylesheet" href="{{ asset('css/global.css') }}?v={{ @filemtime(public_path('css/global.css')) }}">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "WebSite",
                "@id": "{{ url('/') }}#website",
                "url": "{{ url('/') }}",
                "name": "{{ $appName }}",
                "inLanguage": "id-ID",
                "publisher": { "@id": "https://reloop.id/#organization" }
            },
            {
                "@type": "SoftwareApplication",
                "@id": "{{ url('/') }}#software",
                "url": "{{ url('/') }}",
                "name": "{{ $appName }}",
                "applicationCategory": "EducationApplication",
                "operatingSystem": "Web",
                "inLanguage": "id-ID",
                "description": "Aplikasi pencatatan dan monitoring bimbingan Tugas Akhir (TA) dan Kerja Praktik (KP): logbook, revisi, review dokumen, hingga seminar dan sidang.",
                "offers": { "@type": "Offer", "price": "0", "priceCurrency": "IDR" },
                "publisher": { "@id": "https://reloop.id/#organization" }
            }
        ]
    }
    </script>
    @yield('head-extra')
</head>
<body class="landing bg-bg-base text-text-primary font-sans antialiased">
    @yield('content')
    @yield('scripts')
</body>
</html>