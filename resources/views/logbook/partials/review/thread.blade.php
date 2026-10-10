@if ($logbook->parentEntry)
    <div class="px-4 py-3 rounded-xl bg-brand/10 border border-brand/20 text-sm">
        <p class="font-semibold">Menjawab Sesi {{ $logbook->parentEntry->id }}</p>
        <a href="{{ route('logbook.show', $logbook->parentEntry) }}" class="text-brand hover:underline">Lihat anotasi sesi sebelumnya</a>
    </div>
@endif
@if ($logbook->revisionChildren->isNotEmpty())
    <div class="px-4 py-3 rounded-xl bg-bg-panel border border-border text-sm">
        <p class="font-semibold mb-1">Jawaban</p>
        @foreach ($logbook->revisionChildren as $child)
            @php $childLabel = \App\Models\LogbookEntry::STATUS_LABELS[$child->status] ?? ucfirst($child->status); @endphp
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 py-0.5">
                <a href="{{ route('logbook.show', $child) }}" class="text-brand hover:underline">Revisi {{ $child->revision_round ?: '—' }} · {{ $childLabel }}</a>
                @if (auth()->user()->isMahasiswa() && auth()->user()->can('update', $child))
                    <a href="{{ route('logbook.edit', $child) }}" class="text-xs font-semibold text-brand hover:underline">Lanjutkan draf →</a>
                @elseif ($child->status === \App\Models\LogbookEntry::STATUS_SUBMITTED)
                    <span class="text-xs text-text-secondary">Menunggu review dosen</span>
                @endif
            </div>
        @endforeach
    </div>
@endif
