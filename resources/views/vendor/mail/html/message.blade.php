<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@php $footerBrand = trim((string) config('app.name', 'Logbook')); @endphp
@if ($footerBrand === 'Logbook')
© {{ date('Y') }} <span style="font-weight: 700;"><span style="color: #4a6fc0;">Log</span><span style="color: #b8903f;">book</span></span>. {{ __('All rights reserved.') }}
@else
© {{ date('Y') }} {{ $footerBrand }}. {{ __('All rights reserved.') }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
