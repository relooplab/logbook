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
            <form method="POST" action="{{ route('materials-review.bulk') }}" id="bulk-logbook">
                @csrf

                {{-- Bar aksi massal --}}
                <div class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-border bg-bg-panel px-3 py-2">
                    <label class="inline-flex items-center gap-2 text-xs text-text-secondary cursor-pointer">
                        <input type="checkbox" id="pilih-semua"
                               class="rounded border-border bg-bg-surface text-brand focus:ring-brand">
                        Pilih semua
                    </label>
                    <span class="text-xs text-text-secondary" id="jumlah-terpilih">0 dipilih</span>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <input type="text" name="archive_reason" maxlength="500"
                               placeholder="Alasan arsip (opsional)"
                               class="rounded-lg border border-border bg-bg-surface px-2 py-1 text-xs text-text-primary placeholder:text-text-secondary/70">
                        <button type="submit" name="aksi" value="approve"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-status-success/15 px-3 py-1.5 text-xs font-semibold text-status-success hover:bg-status-success/25 disabled:opacity-40"
                                onclick="return confirmBulk('Setujui semua entri yang dipilih?')">
                            <span class="material-symbols-outlined icon-sm">task_alt</span> Setujui
                        </button>
                        <button type="submit" name="aksi" value="archive"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-border px-3 py-1.5 text-xs font-medium text-text-secondary hover:bg-bg-hover disabled:opacity-40"
                                onclick="return confirmBulk('Arsipkan semua entri yang dipilih? Entri tidak dihapus, hanya dikeluarkan dari antrean review.')">
                            <span class="material-symbols-outlined icon-sm">archive</span> Arsipkan
                        </button>
                    </div>
                </div>

                <div class="divide-y divide-border border border-border rounded-xl overflow-hidden">
                    @foreach ($logbook as $entry)
                        @php
                            $dikirim = $entry->submitted_at ?? $entry->created_at;
                        @endphp
                        <div class="px-4 py-3 flex flex-wrap items-center gap-3 bg-bg-surface">
                            <input type="checkbox" name="ids[]" value="{{ $entry->id }}"
                                   class="baris-pilih rounded border-border bg-bg-surface text-brand focus:ring-brand"
                                   aria-label="Pilih entri {{ $entry->id }}">
                            <span class="text-2xl">{{ $entry->jenis === 'revisi' ? '↩' : '📝' }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-text-primary">@include('logbook.partials.student-identity', ['entry' => $entry])</p>
                                <p class="text-xs text-text-secondary">{{ $entry->jenis === 'revisi' ? 'Revisi' : 'Logbook sesi '.$entry->sesi_ke }} · {{ $entry->topik ?: '' }}</p>
                                <p class="text-[11px] text-text-secondary mt-0.5">
                                    <span class="material-symbols-outlined" style="font-size:12px;vertical-align:-2px">schedule</span>
                                    Dikirim {{ $dikirim?->format('d M Y H:i') ?? '—' }}
                                    @if ($dikirim)
                                        <span class="text-text-secondary/70">({{ $dikirim->locale('id')->diffForHumans() }})</span>
                                    @endif
                                </p>
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
            </form>

            <script>
                (function () {
                    const form = document.getElementById('bulk-logbook');
                    if (! form) return;
                    const semua = document.getElementById('pilih-semua');
                    const baris = Array.from(form.querySelectorAll('.baris-pilih'));
                    const label = document.getElementById('jumlah-terpilih');

                    function sync() {
                        const n = baris.filter(c => c.checked).length;
                        label.textContent = n + ' dipilih';
                        semua.checked = n > 0 && n === baris.length;
                        semua.indeterminate = n > 0 && n < baris.length;
                        form.querySelectorAll('button[name="aksi"]').forEach(b => { b.disabled = n === 0; });
                    }

                    semua?.addEventListener('change', () => {
                        baris.forEach(c => { c.checked = semua.checked; });
                        sync();
                    });
                    baris.forEach(c => c.addEventListener('change', sync));
                    sync();
                })();

                function confirmBulk(pesan) {
                    const n = document.querySelectorAll('#bulk-logbook .baris-pilih:checked').length;
                    if (n === 0) return false;
                    return confirm(pesan + ' (' + n + ' entri)');
                }
            </script>
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
                            <p class="text-xs text-text-secondary">{{ $sub->jenisLabel() }} · jadwal {{ optional($sub->tanggal)->format('d M Y') ?: '—' }}</p>
                            <p class="text-[11px] text-text-secondary mt-0.5">
                                <span class="material-symbols-outlined" style="font-size:12px;vertical-align:-2px">schedule</span>
                                Dikirim {{ $sub->created_at?->format('d M Y H:i') ?? '—' }}
                                @if ($sub->created_at)
                                    <span class="text-text-secondary/70">({{ $sub->created_at->locale('id')->diffForHumans() }})</span>
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('seminar-submission.show', $sub) }}" class="px-3 py-1.5 rounded-xl bg-brand text-[#0b1420] text-xs font-medium hover:opacity-90">Baca & Tandai Dibaca →</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
