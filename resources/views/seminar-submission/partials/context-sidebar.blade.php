{{--
    Sidebar konteks pengajuan seminar: status, dosen terkait,
    info mahasiswa, dan tips. Presentasi state form/server saja.
--}}
@props([
    'statusBadge' => '<span class="badge badge-neutral">Belum Dikirim</span>',
    'recipients' => [],
    'mahasiswaTa' => null,
    'allowedTypes' => [],
    'maxMb' => null,
])

<section class="card form-workspace-card p-5" aria-labelledby="seminar-status-title">
    <div class="form-card-head">
        <span class="icon-chip h-10 w-10" aria-hidden="true">
            <span class="material-symbols-outlined icon-md text-brand">assignment_turned_in</span>
        </span>
        <div class="min-w-0">
            <h2 id="seminar-status-title" class="font-heading font-semibold text-text-primary">Status Pengajuan</h2>
            <p class="text-caption text-text-secondary">Posisi pengajuan Anda.</p>
        </div>
    </div>
    <div class="mt-4">{!! $statusBadge !!}</div>
    @if (!empty($recipients))
        <div class="mt-4 border-t border-border pt-4">
            <h3 class="text-xs font-medium uppercase tracking-widest text-text-secondary">Diteruskan kepada</h3>
            <ul class="mt-2 space-y-2">
                @foreach ($recipients as $label)
                    <li class="flex min-w-0 items-start gap-2 text-sm">
                        <span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-brand" aria-hidden="true">person</span>
                        <span class="min-w-0 break-words font-medium text-text-primary">{{ $label }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>

@if ($mahasiswaTa)
    <section class="card form-workspace-card p-5" aria-labelledby="seminar-student-title">
        <div class="form-card-head">
            <span class="icon-chip h-10 w-10" aria-hidden="true">
                <span class="material-symbols-outlined icon-md text-brand">school</span>
            </span>
            <div class="min-w-0">
                <h2 id="seminar-student-title" class="font-heading font-semibold text-text-primary">Info Mahasiswa</h2>
                <p class="text-caption text-text-secondary">Konteks akademik pengajuan.</p>
            </div>
        </div>
        <dl class="mt-4 space-y-3 text-sm">
            <div class="min-w-0">
                <dt class="text-xs text-text-secondary">Nama</dt>
                <dd class="break-words font-medium text-text-primary">{{ $mahasiswaTa->mahasiswa?->name ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-text-secondary">NIM</dt>
                <dd class="break-words font-medium text-text-primary">{{ $mahasiswaTa->mahasiswa?->nim ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-text-secondary">Program</dt>
                <dd class="break-words font-medium text-text-primary">{{ $mahasiswaTa->jenisLabel() }}</dd>
            </div>
            @if (filled($mahasiswaTa->judul_ta))
                <div class="min-w-0">
                    <dt class="text-xs text-text-secondary">Judul</dt>
                    <dd class="break-words font-medium text-text-primary">{{ $mahasiswaTa->judul_ta }}</dd>
                </div>
            @endif
        </dl>
    </section>
@endif

<section class="card form-workspace-card p-5" aria-labelledby="seminar-tips-title">
    <div class="form-card-head">
        <span class="icon-chip h-10 w-10" aria-hidden="true">
            <span class="material-symbols-outlined icon-md text-brand">tips_and_updates</span>
        </span>
        <div class="min-w-0">
            <h2 id="seminar-tips-title" class="font-heading font-semibold text-text-primary">Tips Pengajuan</h2>
            <p class="text-caption text-text-secondary">Sesuai aturan pengajuan.</p>
        </div>
    </div>
    <ul class="mt-4 space-y-2 text-sm text-text-secondary">
        @if (!empty($allowedTypes))
            <li class="flex items-start gap-2">
                <span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-status-info" aria-hidden="true">check_circle</span>
                <span>Gunakan format {{ implode(', ', $allowedTypes) }} untuk surat dan materi.</span>
            </li>
        @endif
        @if ($maxMb)
            <li class="flex items-start gap-2">
                <span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-status-info" aria-hidden="true">check_circle</span>
                <span>Ukuran tiap file surat/materi maksimal {{ $maxMb }} MB.</span>
            </li>
        @endif
        <li class="flex items-start gap-2">
            <span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-status-info" aria-hidden="true">check_circle</span>
            <span>Jadwal seminar minimal hari ini — tanggal lampau ditolak.</span>
        </li>
        <li class="flex items-start gap-2">
            <span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-status-info" aria-hidden="true">check_circle</span>
            <span>Pastikan nama dosen pada surat undangan sesuai penerima yang dipilih.</span>
        </li>
    </ul>
</section>
