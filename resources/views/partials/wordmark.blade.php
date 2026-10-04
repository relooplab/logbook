{{-- Wordmark (brand guideline §01): mark two-tone A2 tanpa background
     (atap + L kecil biru accent-blue, stroke-J besar emas status-pending),
     diikuti wordmark dinamis. Nama "Logbook" dirender dua warna
     (Log biru + book emas sesuai token); nama kustom lain memakai
     pola lama (kata terakhir berwarna accent).
     Baca nama dari $name (param) atau fallback Institution::active()->app_name.
     Opsional: $accent (kini hanya dipakai nama kustom),
     $markSize (default w-14 h-14), $textAlign. --}}
@php
    $accent = $accent ?? 'text-brand';
    $markSize = $markSize ?? 'w-14 h-14';
    $textAlign = $textAlign ?? 'text-center';
    $appName = $name ?? optional(\App\Models\Institution::active())->app_name ?: 'Logbook';
    $words = preg_split('/\s+/', trim($appName));
    $lastWord = (string) array_pop($words);
    $firstWords = implode(' ', $words);
@endphp
<div class="inline-flex flex-col items-center {{ $textAlign }}">
    <div class="inline-flex {{ $markSize }} items-center justify-center mb-3">
        @include('partials.logo-mark')
    </div>
    @if ($appName === 'Logbook')
        <span class="font-heading font-extrabold text-2xl"><span class="text-accent-blue">Log</span><span class="text-status-pending">book</span></span>
    @else
        <span class="font-heading font-extrabold text-2xl text-text-primary">@if ($firstWords !== '')<span>{{ $firstWords }}</span> <span class="{{ $accent }}">{{ $lastWord }}</span>@else<span class="{{ $accent }}">{{ $lastWord }}</span>@endif</span>
    @endif
</div>
