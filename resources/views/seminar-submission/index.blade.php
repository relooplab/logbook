@extends('layouts.app')

@section('title', 'Riwayat Seminar/Sidang')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="font-heading font-bold text-2xl text-text-primary">Riwayat Seminar/Sidang</h1>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-text-secondary">
                <span class="font-medium text-text-primary">{{ $program->mahasiswa?->name ?? $program->judul_ta }}</span>
                <span>Fase saat ini: <span class="text-text-primary">{{ $program->faseLabel() }}</span></span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($isMember)
                <a href="{{ route('seminar-submission.create', $program) }}"
                   class="btn-primary px-4 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                    Kirim Bahan Seminar/Sidang
                </a>
            @endif
            <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
        </div>
    </div>

    {{-- Rekap --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Total pengajuan', $rekap['total'], 'description'],
            ['Sudah dikirim', $rekap['dikirim'], 'send'],
            ['Terjadwal (akan datang)', $rekap['terjadwal'], 'event_upcoming'],
            ['Berkas di arsip', $rekap['arsip'], 'archive'],
        ] as [$label, $nilai, $ikon])
            <div class="card p-4">
                <div class="flex items-center gap-2 text-text-secondary">
                    <span class="material-symbols-outlined icon-md" aria-hidden="true">{{ $ikon }}</span>
                    <span class="text-xs">{{ $label }}</span>
                </div>
                <p class="mt-2 font-heading font-bold text-2xl text-text-primary">{{ $nilai }}</p>
            </div>
        @endforeach
    </div>

    {{-- Daftar pengajuan --}}
    <section class="card p-0 overflow-hidden" aria-labelledby="riwayat-title">
        <div class="px-4 py-3 bg-bg-panel/50 border-b border-border">
            <h2 id="riwayat-title" class="font-semibold text-sm text-text-primary">Daftar bahan yang diajukan</h2>
            <p class="mt-0.5 text-xs text-text-secondary">
                Bahan fase yang sudah lewat hanya dapat dilihat. Setelah dosen menandai seminar/sidang <strong>selesai</strong>,
                perubahan perlu persetujuan dosen dan berkas lama tersimpan di grup “Arsip” pada Workspace.
            </p>
        </div>

        @if ($submissions->isEmpty())
            <div class="px-4 py-10 text-center">
                <span class="material-symbols-outlined text-text-secondary/50" style="font-size:44px">inbox</span>
                <p class="mt-2 text-sm font-medium text-text-primary">Belum ada bahan yang diajukan</p>
                <p class="text-xs text-text-secondary">Kirim undangan dan materi seminar/sidang Anda dari tombol di atas.</p>
            </div>
        @else
            <div class="divide-y divide-border">
                @foreach ($submissions as $submission)
                    @php
                        $bisaEdit = $isMember && $submission->isUpdatableByStudent();
                        $sudahLewat = $submission->tanggal && $submission->tanggal->isPast();
                        $lokasi = trim((string) $submission->locationText());
                    @endphp
                    <div class="px-4 py-3 flex flex-wrap items-start gap-3">
                        <span class="material-symbols-outlined text-status-info icon-md mt-0.5" aria-hidden="true">co_present</span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-text-primary">{{ $submission->jenisLabel() }}</span>
                                <span class="badge {{ $submission->status === \App\Models\SeminarSubmission::STATUS_SUBMITTED ? 'badge-info' : 'badge-warning' }}">{{ $submission->statusLabel() }}</span>
                                @if ($submission->isSelesai())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-status-success/15 text-status-success">Selesai</span>
                                @elseif ($sudahLewat)
                                    <span class="text-[11px] text-text-secondary">· kegiatan sudah lewat</span>
                                @endif
                                @if ($submission->isMenungguPersetujuanEdit())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-status-pending/15 text-status-pending">Menunggu izin dosen</span>
                                @elseif ($submission->isEditApproved())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-status-info/15 text-status-info">
                                        Izin edit s/d {{ $submission->editApprovalExpiresAt()?->format('d M Y') }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-text-secondary">
                                {{ $submission->tanggal?->format('d M Y') ?? 'Tanggal belum ditentukan' }}
                                @if ($submission->waktu) · {{ $submission->waktu->format('H:i') }} WIB @endif
                                @if ($lokasi !== '') · {{ $lokasi }} @endif
                            </p>
                            <p class="mt-0.5 text-xs text-text-secondary">
                                {{ $submission->documents_count }} dokumen tambahan
                                @if ($submission->undangan_original_name) · undangan: {{ $submission->undangan_original_name }} @endif
                                @if ($submission->materi_original_name) · materi: {{ $submission->materi_original_name }} @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 shrink-0">
                            @if ($bisaEdit)
                                <a href="{{ route('seminar-submission.edit', $submission) }}"
                                   class="px-3 py-1.5 rounded-xl bg-bg-hover text-text-primary text-xs font-medium hover:bg-border">Edit</a>
                            @elseif ($isMember && $submission->bisaMintaEdit())
                                <form method="POST" action="{{ route('seminar-submission.minta-edit', $submission) }}"
                                      onsubmit="return confirm('Kirim permintaan izin edit ke dosen? Setelah seminar/sidang selesai, perubahan perlu persetujuan dosen.')">
                                    @csrf
                                    <button class="px-3 py-1.5 rounded-xl bg-bg-hover text-text-primary text-xs font-medium hover:bg-border">
                                        Minta Izin Edit
                                    </button>
                                </form>
                            @endif
                            <a href="{{ route('seminar-submission.show', $submission) }}"
                               class="{{ $bisaEdit ? 'px-3 py-1.5 rounded-xl border border-border text-xs font-medium hover:bg-bg-hover text-text-primary' : 'btn-primary px-3 py-1.5 text-xs font-semibold' }}">
                                Lihat Detail
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @if ($isMember)
        <p class="text-xs text-text-secondary">
            Mahasiswa dapat mengedit bahan selama fase program belum maju. Menambah bahan untuk jenis lain
            (mis. Sidang saat masih di Seminar Hasil) tetap bisa lewat tombol <strong>Kirim Bahan Seminar/Sidang</strong>.
        </p>
    @endif
</div>
@endsection
