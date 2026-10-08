@php
    // Kartu dokumen (dipakai show & quick-review). $viewerParams menambah query
    // viewer (mis. ['quick_review' => 1] agar tombol kembali ke antrean).
    // $canAnnotate: label tombol untuk pemilik/reviewer.
    $viewerParams = $viewerParams ?? [];
    $canAnnotate = $canAnnotate ?? (auth()->user()->can('review', $logbook) || (auth()->user()->isMahasiswa() && $logbook->mahasiswaTa?->isMember(auth()->user())));
@endphp
@if ($logbook->lampiran_path || $logbook->catatan_perbaikan_path)
    <section class="card p-5 space-y-3 detail-workspace-card" aria-label="Dokumen">
        <h2 class="font-heading font-semibold text-text-primary">Dokumen</h2>
        <a href="{{ route('logbook.pdf-viewer', array_merge(['logbook' => $logbook], $viewerParams)) }}" class="block text-center px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">{{ $canAnnotate ? 'Buka PDF & Anotasi' : 'Lihat PDF & Komentar' }}</a>
        @if (auth()->user()->isMahasiswa() && $logbook->jenis === 'revisi' && $logbook->isEditable() && empty($logbook->riwayat_perbaikan))
            <p class="text-xs text-text-secondary">Draf tersimpan tanpa tabel perbaikan. <a href="{{ route('logbook.edit', $logbook) }}" class="font-semibold text-brand hover:underline">Buka Edit</a> → tandai di PDF → isi otomatis ke kartu.</p>
        @endif
        <div class="flex flex-wrap gap-x-3 gap-y-2 text-xs">
            @if ($logbook->lampiran_path)
                <a href="{{ route('logbook.pdf', $logbook) }}" target="_blank" rel="noopener noreferrer" class="text-brand hover:underline">Buka PDF di browser</a>
            @endif
            @if ($logbook->catatan_perbaikan_path)
                <a href="{{ route('logbook.catatan-pdf', $logbook) }}" target="_blank" rel="noopener noreferrer" class="text-brand hover:underline">Buka catatan di browser</a>
            @endif
        </div>
    </section>
@endif
