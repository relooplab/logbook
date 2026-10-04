<x-mail::message>
# Halo {{ $penerima }}

{{ $opening }}

@foreach ($files as $file)
- **{{ $file['name'] }}**@if(!empty($file['bab'])) ({{ $file['bab'] }})@endif — {{ $file['time'] }}
@endforeach

Klik tombol di bawah untuk membuka workspace dan meninjau file.

<x-mail::button :url="$url">
Buka Workspace
</x-mail::button>
</x-mail::message>
