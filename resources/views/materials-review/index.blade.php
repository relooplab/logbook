@extends('layouts.app')

@section('title', 'Antrean Review Bahan')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">Antrean Review Bahan</h1>
            <p class="text-sm text-text-secondary mt-0.5">Bahan mahasiswa yang belum Anda tinjau. Anda tetap dapat mengakses dashboard kapan saja.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">← Ke Dashboard</a>
    </div>

    {{-- Logbook / Revisi --}}
    <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-heading font-semibold text-text-primary">Logbook & Revisi menunggu ({{ $logbook->count() }})</h2>
            <a href="{{ route('quick-review.index') }}" class="text-sm text-brand hover:underline">Review Cepat →</a>
        </div>

        @if ($logbook->isEmpty())
            <div class="px-4 py-8 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
                <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">task_alt</span>
                <p>Tidak ada logbook/revisi yang menunggu.</p>
            </div>
        @else
            <div class="divide-y divide-border border border-border rounded-xl overflow-hidden">
                @foreach ($logbook as $entry)
                    <div class="px-4 py-3 flex flex-wrap items-center gap-3 bg-bg-surface">
                        <span class="text-2xl">{{ $entry->jenis === 'revisi' ? '↩' : '📝' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-text-primary">@include('logbook.partials.student-identity', ['entry' => $entry])</p>
                            <p class="text-xs text-text-secondary">{{ $entry->jenis === 'revisi' ? 'Revisi' : 'Logbook sesi '.$entry->sesi_ke }} · {{ $entry->topik ?: '' }}</p>
                        </div>
                        @if (($draftEntryIds ?? collect())->contains($entry->id))
                            <span class="badge badge-draft" title="Draf keputusan tersimpan">Draf</span>
                        @endif
                        @if (($unreadEntryIds ?? collect())->contains($entry->id))
                            <span class="badge badge-info" title="Ada aktivitas belum dibaca pada entri ini">Baru</span>
                        @endif
                        @if ($entry->review_opened_by && (int) $entry->review_opened_by !== (int) auth()->id() && $entry->reviewOpener)
                            <span class="badge badge-neutral" title="Dibuka {{ $entry->reviewOpener->name }} {{ $entry->review_opened_at?->diffForHumans() ?? '' }} — koordinasi agar keputusan tidak ganda">Dibuka {{ \Illuminate\Support\Str::limit($entry->reviewOpener->name, 14) }}</span>
                        @endif
                        <a href="{{ route('logbook.show', $entry) }}" class="px-3 py-1.5 rounded-xl bg-brand text-[#0b1420] text-xs font-medium hover:opacity-90">Tinjau →</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Seminar / Sidang --}}
    <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-heading font-semibold text-text-primary">Bahan Seminar / Sidang belum dibaca ({{ $seminar->count() }})</h2>
        </div>

        @if ($seminar->isEmpty())
            <div class="px-4 py-8 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
                <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">mark_email_read</span>
                <p>Semua bahan seminar/sidang sudah dibaca.</p>
            </div>
        @else
            <div class="divide-y divide-border border border-border rounded-xl overflow-hidden">
                @foreach ($seminar as $sub)
                    <div class="px-4 py-3 flex flex-wrap items-center gap-3 bg-bg-surface">
                        <span class="text-2xl">📄</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-text-primary">@include('seminar-submission.partials.student-identity', ['submission' => $sub])</p>
                            <p class="text-xs text-text-secondary">{{ $sub->jenisLabel() }} · {{ optional($sub->tanggal)->format('d M Y') ?: '' }}</p>
                        </div>
                        <a href="{{ route('seminar-submission.show', $sub) }}" class="px-3 py-1.5 rounded-xl bg-brand text-[#0b1420] text-xs font-medium hover:opacity-90">Baca & Tandai Dibaca →</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
