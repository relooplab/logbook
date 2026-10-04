<x-mail::message>
# Halo {{ $penerima }}

{{ $opening }}

{{ $intro }}

@foreach ($items as $item)
- **{{ $item['sender'] }}** ({{ $item['time'] }}): {{ $item['body'] }}
@endforeach

{{ $closing }}

<x-mail::button :url="$url">
Balas di Aplikasi
</x-mail::button>
</x-mail::message>
