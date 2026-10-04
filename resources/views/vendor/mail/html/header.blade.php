@props(['url'])
{{-- Wordmark email: teks two-tone dengan inline style (klien email tidak
     menjalankan Tailwind). "Logbook" → Log biru + book emas sesuai token
     brand; nama kustom institusi → satu warna. Tanpa logo gambar karena
     dukungan SVG di Gmail/Outlook buruk. --}}
@php
    $brandName = trim((string) config('app.name', 'Logbook'));
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
@if ($brandName === 'Logbook')
<span style="font-family: 'Plus Jakarta Sans', -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 22px; font-weight: 800; letter-spacing: 0.01em;"><span class="brand-log" style="color: #4a6fc0;">Log</span><span class="brand-book" style="color: #b8903f;">book</span></span>
@else
<span style="font-family: 'Plus Jakarta Sans', -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 20px; font-weight: 700; color: #1e2024;">{{ $brandName }}</span>
@endif
</a>
</td>
</tr>
