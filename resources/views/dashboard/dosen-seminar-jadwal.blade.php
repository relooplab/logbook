@extends('layouts.app')

@section('title', 'Agenda Seminar/Sidang')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">Agenda Seminar/Sidang</h1>
            <p class="text-sm text-text-secondary mt-0.5">{{ $tab === 'past' ? 'Riwayat jadwal seminar/sidang mahasiswa yang Anda bimbing/uji, diurutkan dari yang terbaru.' : 'Jadwal & bahan seminar/sidang mahasiswa yang Anda bimbing/uji, diurutkan dari jadwal terdekat.' }}</p>
        </div>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
    </div>

    {{-- ===== Filter: tab Akan Datang / Riwayat + jenis ===== --}}
    <div class="card p-4 flex flex-wrap items-center gap-3">
        <div class="flex gap-1">
            <a href="{{ route('dosen.seminar-jadwal', array_filter(['tab' => 'upcoming', 'jenis' => $jenis, 'q' => $q ?? null, 'peran' => $peran ?? null])) }}"
                class="px-3 py-1.5 rounded-lg text-sm font-medium {{ $tab !== 'past' ? 'bg-brand text-[#0b1420]' : 'bg-bg-hover text-text-secondary hover:bg-border' }}">Akan Datang</a>
            <a href="{{ route('dosen.seminar-jadwal', array_filter(['tab' => 'past', 'jenis' => $jenis, 'q' => $q ?? null, 'peran' => $peran ?? null])) }}"
                class="px-3 py-1.5 rounded-lg text-sm font-medium {{ $tab === 'past' ? 'bg-brand text-[#0b1420]' : 'bg-bg-hover text-text-secondary hover:bg-border' }}">Riwayat</a>
        </div>
        <form method="GET" action="{{ route('dosen.seminar-jadwal') }}" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="search" name="q" value="{{ $q ?? '' }}" placeholder="Cari nama mahasiswa…"
                class="rounded-lg border border-border bg-bg-surface px-3 py-1.5 text-sm w-48">
            <select name="peran" onchange="this.form.submit()" class="rounded-lg border border-border bg-bg-surface px-3 py-1.5 text-sm">
                <option value="">Semua Peran</option>
                <option value="pembimbing" @selected(($peran ?? null) === 'pembimbing')>Pembimbing</option>
                <option value="penguji" @selected(($peran ?? null) === 'penguji')>Penguji</option>
            </select>
            <select name="jenis" onchange="this.form.submit()" class="rounded-lg border border-border bg-bg-surface px-3 py-1.5 text-sm">
                <option value="">Semua Jenis</option>
                @foreach (\App\Models\SeminarSubmission::JENISES as $j)
                    <option value="{{ $j }}" @selected($jenis === $j)>{{ \App\Models\SeminarSubmission::staticLabel($j) }}</option>
                @endforeach
            </select>
        </form>
        <span class="text-xs text-text-secondary ml-auto">{{ $tab === 'past' ? 'Riwayat jadwal' : $submissions->total().' agenda' }}</span>
    </div>

    {{-- ===== Daftar agenda ===== --}}
    <div class="card p-6">
        @if ($submissions->isEmpty())
            <div class="px-4 py-10 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
                <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">event_busy</span>
                <p>Tidak ada {{ $tab === 'past' ? 'riwayat jadwal' : 'agenda' }}.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($submissions as $sub)
                    @php
                        $isRead = in_array($sub->id, $readIds, true);
                        $isPast = $sub->tanggal->lt(today()) || ($sub->tanggal->isToday() && ($sub->waktu?->format('H:i') ?? '00:00') < now()->format('H:i'));
                        $hasSidang = (bool) $sub->sidang_id;
                    @endphp
                    <div class="bg-bg-panel rounded-xl border border-border p-4 hover:border-brand/30 transition-colors">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="icon-circle w-10 h-10 {{ $isPast ? 'bg-bg-hover text-text-secondary' : 'bg-brand-light text-brand' }} flex-shrink-0">
                                    <span class="material-symbols-outlined icon-md">{{ $hasSidang ? 'how_to_reg' : 'event' }}</span>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold text-text-primary">{{ $sub->jenisLabel() }}</p>
                                        @if (!$isRead)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-status-danger/15 text-status-danger">Baru</span>
                                        @endif
                                        @if ($hasSidang)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-status-success/15 text-status-success">Sudah Dinilai</span>
                                        @elseif ($isPast)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-bg-hover text-text-secondary">Selesai</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-status-pending/15 text-status-pending">Menunggu</span>
                                        @endif
                                    </div>
                                    <p class="text-sm text-text-secondary mt-0.5">{{ $sub->mahasiswaTa?->mahasiswa?->name ?? 'Mahasiswa' }}</p>
                                    <p class="text-xs text-text-secondary font-mono mt-1">
                                        {{ $sub->tanggal->format('d M Y') }} · {{ $sub->waktu?->format('H:i') }}@if ($sub->lokasi) · {{ $sub->lokasi }}@endif
                                    </p>
                                </div>
                            </div>
                            <a href="{{ route('seminar-submission.show', $sub) }}"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand text-[#0b1420] text-xs font-semibold hover:opacity-90">
                                @if ($sub->materi_path || $sub->materi_workspace_file_id)
                                    <span class="material-symbols-outlined icon-sm">folder_open</span> Lihat Bahan
                                @else
                                    <span class="material-symbols-outlined icon-sm">visibility</span> Detail
                                @endif
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-4">
                {{ $submissions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection