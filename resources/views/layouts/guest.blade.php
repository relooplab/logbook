@php
    $inst = \App\Models\Institution::active();
    $appName = $inst?->app_name ?: config('app.name');
    $version = \App\Support\ReleaseVersion::get();
    $adminContactEmail = \App\Models\Institution::adminContactEmailFor(null);
@endphp
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Masuk') · {{ $inst->app_name }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32x32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet" />
    <style>
        .material-symbols-outlined {
            user-select: none;
            vertical-align: middle;
            font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 24;
        }
        .icon-sm { font-size: 16px; }
        .icon-md { font-size: 20px; }
        .icon-lg { font-size: 24px; }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        (function () {
            var saved = localStorage.getItem('lbta-theme');
            if (saved === 'light') document.documentElement.classList.remove('dark');
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('css/global.css') }}?v={{ @filemtime(public_path('css/global.css')) }}">
</head>
<body class="bg-bg-base text-text-primary min-h-screen flex items-center justify-center p-4 font-sans antialiased ctx-mahasiswa">
    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            @include('partials.wordmark', [
                'markSize' => 'w-14 h-14',
                'accent' => 'text-brand',
            ])
            <p class="text-sm text-text-secondary mt-1">Aplikasi pencatatan &amp; monitoring bimbingan Tugas Akhir mahasiswa</p>
        </div>
        <div class="bg-bg-surface rounded-card shadow-lg border border-border p-6">
            @yield('guest-content')
        </div>
        <footer class="mt-6 border-t border-border pt-4 text-center">
        <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm">
            <span class="font-heading font-bold text-text-primary">@if ($appName === 'Logbook')<span class="text-accent-blue">Log</span><span class="text-status-pending">book</span>@else{{ $appName }}@endif</span>
            <span class="text-xs text-text-secondary">v{{ $version }}</span>
        </div>
        <div class="mt-2 flex flex-wrap items-center justify-center gap-3 text-xs leading-5 text-text-secondary">
            <p>© <span class="text-accent-teal font-semibold">{{ now()->year }}</span> Made <span class="text-accent-blue font-semibold">with</span> <span aria-hidden="true">❤️</span> by <a href="https://reloop.id" target="_blank" rel="noopener noreferrer" class="hover:underline">ReLoop Lab</a>.</p>
            @if ($adminContactEmail)
                <a href="mailto:{{ $adminContactEmail }}" class="hover:text-text-primary hover:underline">Hubungi admin</a>
            @endif
        </div>
        <div class="mt-2 flex items-center justify-center gap-1">
            <a href="https://github.com/relooplab/logbook" target="_blank" rel="noopener noreferrer" class="landing-social-link" aria-label="GitHub Logbook (tab baru)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .75a11.25 11.25 0 0 0-3.56 21.92c.56.1.77-.24.77-.54v-2.1c-3.13.68-3.79-1.33-3.79-1.33-.51-1.3-1.25-1.65-1.25-1.65-1.02-.7.08-.69.08-.69 1.13.08 1.73 1.16 1.73 1.16 1 1.72 2.64 1.22 3.28.93.1-.72.39-1.22.71-1.5-2.5-.28-5.13-1.25-5.13-5.56 0-1.23.44-2.23 1.16-3.02-.12-.28-.5-1.43.11-2.98 0 0 .95-.3 3.09 1.15a10.77 10.77 0 0 1 5.62 0c2.15-1.45 3.09-1.15 3.09-1.15.61 1.55.23 2.7.11 2.98.72.79 1.16 1.79 1.16 3.02 0 4.32-2.63 5.28-5.14 5.56.4.35.76 1.03.76 2.08v3.1c0 .3.2.65.78.54A11.25 11.25 0 0 0 12 .75Z"/></svg>
            </a>
            <a href="https://www.linkedin.com/company/relooplab" target="_blank" rel="noopener noreferrer" class="landing-social-link" aria-label="LinkedIn Reloop Lab (tab baru)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 2H3.55C2.69 2 2 2.68 2 3.52v16.96C2 21.32 2.69 22 3.55 22h16.9c.86 0 1.55-.68 1.55-1.52V3.52c0-.84-.69-1.52-1.55-1.52ZM7.93 18.75H4.98V9.2h2.95v9.55ZM6.45 7.9a1.71 1.71 0 1 1 0-3.42 1.71 1.71 0 0 1 0 3.42Zm12.3 10.85H15.8v-4.65c0-1.11-.02-2.54-1.55-2.54-1.55 0-1.79 1.21-1.79 2.46v4.73H9.51V9.2h2.83v1.3h.04c.4-.76 1.36-1.55 2.79-1.55 2.99 0 3.58 1.97 3.58 4.53v5.27Z"/></svg>
            </a>
        </div>
    </footer>
    </div>
    @yield('guest-scripts')
</body>
</html>
