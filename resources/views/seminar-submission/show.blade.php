@extends('layouts.app')

@section('title', 'Detail '.$submission->jenisLabel())

@section('content')
@php
    // Lokasi dan tautan masih disimpan dalam satu field. Pisahkan hanya untuk tampilan.
    $rawLocation = (string) ($submission->lokasi ?? '');
    preg_match_all('~https?://[^\s<>()]+~iu', $rawLocation, $locationMatches);
    $meetingLinks = [];
    foreach ($locationMatches[0] as $match) {
        $url = rtrim($match, '.,;!?');
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $meetingLinks[$url] = $url;
        }
    }
    $locationText = trim(str_replace($locationMatches[0], '', $rawLocation), " \t\n\r\0\x0B,;()");
@endphp

<div class="detail-workspace space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="font-heading font-bold text-2xl text-text-primary">Detail {{ $submission->jenisLabel() }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-text-secondary">
                <span class="font-medium text-text-primary">{{ $submission->mahasiswaTa->mahasiswa?->name }}</span>
                <span>{{ $submission->tanggal?->format('d M Y') ?? '—' }} · {{ $submission->waktu?->format('H:i') ?? '—' }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($isMember && $submission->isUpdatableByStudent())
                <a href="{{ route('seminar-submission.edit', $submission) }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Edit</a>
            @endif
            <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
        </div>
    </div>

    <div class="detail-workspace-grid">
        <div class="space-y-5 min-w-0">
            <section class="card p-5 sm:p-6" aria-labelledby="seminar-schedule-title">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-5"><div><h2 id="seminar-schedule-title" class="font-heading font-semibold text-lg text-text-primary">Jadwal {{ $submission->jenisLabel() }}</h2><p class="text-sm text-text-secondary mt-1">Informasi jadwal dan tempat pelaksanaan.</p></div><span class="badge badge-info">{{ $submission->statusLabel() }}</span></div>
                <div class="seminar-schedule-grid text-sm">
                    <div class="rounded-xl bg-bg-panel border border-border p-4 min-w-0"><span class="material-symbols-outlined text-status-info icon-md" aria-hidden="true">calendar_month</span><p class="text-xs text-text-secondary mt-2">Tanggal</p><p class="font-semibold text-text-primary mt-1">{{ $submission->tanggal?->format('d M Y') ?? '—' }}</p><p class="text-xs text-text-secondary">{{ $submission->tanggal?->locale('id')->translatedFormat('l') }}</p></div>
                    <div class="rounded-xl bg-bg-panel border border-border p-4 min-w-0"><span class="material-symbols-outlined text-status-info icon-md" aria-hidden="true">schedule</span><p class="text-xs text-text-secondary mt-2">Waktu</p><p class="font-semibold text-text-primary mt-1">{{ $submission->waktu?->format('H:i') ?? '—' }} WIB</p></div>
                    <div class="rounded-xl bg-bg-panel border border-border p-4 min-w-0 detail-workspace-card"><span class="material-symbols-outlined text-status-info icon-md" aria-hidden="true">location_on</span><p class="text-xs text-text-secondary mt-2">Lokasi</p><p class="font-semibold text-text-primary mt-1">{{ $locationText !== '' ? $locationText : ($meetingLinks ? 'Pertemuan daring' : ($rawLocation ?: '—')) }}</p></div>
                    <div class="rounded-xl bg-bg-panel border border-border p-4 min-w-0"><span class="material-symbols-outlined text-status-info icon-md" aria-hidden="true">videocam</span><p class="text-xs text-text-secondary mt-2">Zoom Meeting</p><p class="font-semibold text-text-primary mt-1">{{ $meetingLinks ? 'Tersedia' : 'Tidak tersedia' }}</p></div>
                </div>
                @if ($meetingLinks)
                    <div class="mt-4 rounded-xl border border-border bg-bg-panel p-4 flex flex-wrap items-center justify-between gap-3 min-w-0">
                        <div class="min-w-0"><p class="text-xs text-text-secondary">Link pertemuan</p><p class="text-sm text-status-info truncate" title="{{ implode(', ', $meetingLinks) }}">{{ implode(', ', $meetingLinks) }}</p></div>
                        <div class="flex flex-wrap gap-2">
                        @foreach ($meetingLinks as $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90 detail-workspace-card">
                                <span class="material-symbols-outlined icon-sm">open_in_new</span> Buka Zoom{{ count($meetingLinks) > 1 ? ' '. $loop->iteration : '' }}
                            </a>
                        @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section class="card p-5 sm:p-6 space-y-4" aria-labelledby="seminar-documents-title">
                <h2 id="seminar-documents-title" class="font-heading font-semibold text-text-primary">Dokumen</h2>
                <article class="rounded-xl bg-bg-panel border border-border p-4 detail-workspace-card seminar-document-row">
                    <div class="flex items-start gap-3 min-w-0">
                        <span class="material-symbols-outlined text-accent-blue">description</span>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-text-primary">Surat Undangan</h3>
                            <p class="text-sm text-text-primary mt-1 truncate" title="{{ $submission->undangan_original_name ?: 'Dokumen undangan' }}">{{ $submission->undangan_original_name ?: 'Dokumen undangan' }}</p>
                            <p class="text-xs text-text-secondary mt-1">Diundang: {{ $submission->undanganKepadaLabel() }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        @if ($submission->isUndanganPdf())
                            <a href="{{ route('seminar-submission.undangan-preview', $submission) }}" target="_blank" rel="noopener noreferrer" class="px-3 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Buka PDF</a>
                        @endif
                        <a href="{{ route('seminar-submission.undangan-download', $submission) }}" class="px-3 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Download</a>
                    </div>
                </article>
                @if ($submission->materi_path)
                    <article class="rounded-xl bg-bg-panel border border-border p-4 detail-workspace-card seminar-document-row">
                        <div class="flex items-start gap-3 min-w-0">
                            <span class="material-symbols-outlined text-accent-orange">picture_as_pdf</span>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-text-primary">Dokumen Materi</h3>
                                <p class="text-sm text-text-primary mt-1 truncate" title="{{ $submission->materi_original_name ?: 'Dokumen materi' }}">{{ $submission->materi_original_name ?: 'Dokumen materi' }}</p>
                                <p class="text-xs text-text-secondary mt-1">{{ $submission->materiFromWorkspace() ? 'Dari workspace' : 'Upload baru' }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 shrink-0">
                            @if ($submission->isMateriPdf())
                                <a href="{{ route('seminar-submission.materi-preview', $submission) }}" target="_blank" rel="noopener noreferrer" class="px-3 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Buka PDF</a>
                            @endif
                            <a href="{{ route('seminar-submission.materi-download', $submission) }}" class="px-3 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Download</a>
                        </div>
                    </article>
                @else
                    <p class="text-sm text-text-secondary">Belum ada dokumen materi.</p>
                @endif
            </section>

            @php
                $dokumenFiles = $submission->documents->where('type', 'file');
                $dokumenLinks = $submission->documents->where('type', 'link');
            @endphp
            @if ($dokumenFiles->isNotEmpty() || $dokumenLinks->isNotEmpty())
                <section class="card p-5 sm:p-6 space-y-4" aria-labelledby="seminar-tambahan-title">
                    <h2 id="seminar-tambahan-title" class="font-heading font-semibold text-text-primary">Dokumen Tambahan</h2>
                    @foreach ($dokumenFiles as $doc)
                        <article class="rounded-xl bg-bg-panel border border-border p-4 detail-workspace-card seminar-document-row">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="material-symbols-outlined text-accent-teal">description</span>
                                <div class="min-w-0">
                                    <p class="text-sm text-text-primary mt-1 truncate" title="{{ $doc->original_name }}">{{ $doc->original_name }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 shrink-0">
                                <a href="{{ route('seminar-submission.dokumen-download', [$submission, $doc]) }}" class="px-3 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Download</a>
                            </div>
                        </article>
                    @endforeach
                    @foreach ($dokumenLinks as $doc)
                        <article class="rounded-xl bg-bg-panel border border-border p-4 detail-workspace-card seminar-document-row">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="material-symbols-outlined text-accent-blue">link</span>
                                <div class="min-w-0">
                                    <a href="{{ $doc->url }}" target="_blank" rel="noopener noreferrer" class="text-sm text-brand hover:underline break-all">{{ $doc->url }}</a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </section>
            @endif

            @if ($submission->catatan_keterangan)
                <section class="card p-5 sm:p-6 detail-workspace-card">
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                        <h2 class="font-heading font-semibold text-text-primary">Catatan Keterangan</h2>
                        @if ($isDosen && $submission->mahasiswaTa)
                            <a href="{{ route('chat.start', ['user' => $submission->mahasiswaTa->user_id, 'ta' => $submission->mahasiswa_ta_id, 'seminar' => $submission->id, 'quote' => 'catatan']) }}"
                                class="px-3 py-1.5 rounded-xl bg-bg-hover text-text-primary text-xs font-medium hover:bg-border inline-flex items-center gap-1.5">
                                <span class="material-symbols-outlined icon-sm">chat</span> Balas via Chat
                            </a>
                        @endif
                    </div>
                    <p class="text-sm text-text-secondary whitespace-pre-line">{{ $submission->catatan_keterangan }}</p>
                </section>
            @endif

            @if ($submission->sidang_id && $submission->sidang)
                @php $sidangR = $submission->sidang; @endphp
                <section class="card p-5 sm:p-6">
                    <h2 class="font-heading font-semibold text-text-primary mb-3">Hasil & Nilai {{ $sidangR->jenisLabel() }}</h2>
                    <p class="text-sm mb-2">Hasil: <span class="font-medium">{{ $sidangR->hasilLabel() }}</span></p>
                    @php $grades = $sidangR->loadMissing('grades.user')->grades; @endphp
                    @if ($grades->isNotEmpty())
                        <ul class="space-y-1 text-sm">
                            @foreach ($grades as $g)
                                <li class="flex items-center justify-between gap-2">
                                    <span>{{ $g->user?->name }} <span class="text-xs text-text-secondary">({{ ucfirst($g->role) }})</span></span>
                                    <span class="font-medium">{{ $g->filled_at ? $g->nilai : 'Belum dinilai' }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($sidangR->nilaiFinal() !== null)
                            <p class="mt-2 font-semibold text-text-primary">Rerata: {{ $sidangR->nilaiFinal() }}</p>
                        @else
                            <p class="mt-2 text-xs text-text-secondary">Nilai belum lengkap — menunggu dosen terkait melengkapi.</p>
                        @endif
                    @else
                        <p class="text-xs text-text-secondary">Belum ada penilaian dicatat untuk sidang ini.</p>
                    @endif
                </section>
            @endif
        </div>

        <aside class="detail-workspace-panel space-y-4" aria-label="Ringkasan dan tindakan seminar">
            <section class="card p-5 detail-workspace-card">
                <h2 class="font-heading font-semibold text-text-primary mb-4">Ringkasan</h2>
                <dl class="grid grid-cols-[80px_minmax(0,1fr)] gap-x-3 gap-y-3 text-sm">
                    <dt class="text-text-secondary">Jenis</dt><dd class="font-medium">{{ $submission->jenisLabel() }}</dd>
                    <dt class="text-text-secondary">Tanggal</dt><dd class="font-medium">{{ $submission->tanggal?->format('d M Y') ?? '—' }}</dd>
                    <dt class="text-text-secondary">Waktu</dt><dd class="font-medium">{{ $submission->waktu?->format('H:i') ?? '—' }} WIB</dd>
                    <dt class="text-text-secondary">Lokasi</dt><dd class="font-medium">{{ $locationText !== '' ? $locationText : ($meetingLinks ? 'Pertemuan daring' : ($rawLocation ?: '—')) }}</dd>
                    <dt class="text-text-secondary">Zoom</dt><dd class="font-medium">{{ $meetingLinks ? 'Tersedia' : 'Tidak tersedia' }}</dd>
                </dl>
            </section>

            <section class="card p-5 detail-workspace-card">
                <h2 class="font-heading font-semibold text-text-primary mb-3">Catatan Hardcopy</h2>
                <p class="text-sm text-text-secondary mb-3">Catatan pribadi terkait dokumen fisik atau hal lainnya.</p>
                @if ($isDosen)
                    <form method="POST" action="{{ route('seminar-submission.hardcopy-note', $submission) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <label for="catatan-hardcopy" class="sr-only">Catatan Hardcopy</label>
                        <textarea id="catatan-hardcopy" name="catatan_hardcopy" rows="4" required placeholder="Tulis catatan di sini..." class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">{{ old('catatan_hardcopy', $submission->catatan_hardcopy) }}</textarea>
                        @error('catatan_hardcopy') <p class="text-status-danger text-xs">{{ $message }}</p> @enderror
                        <button type="submit" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Simpan Catatan</button>
                    </form>
                @else
                    <p class="text-sm text-text-secondary whitespace-pre-line">{{ $submission->catatan_hardcopy ?: 'Belum ada catatan.' }}</p>
                @endif
            </section>

            @if ($isDosen && !$submission->sidang_id)
                <section class="card p-5 detail-workspace-card">
                    <h2 class="font-heading font-semibold text-text-primary mb-2">Setelah Seminar</h2>
                    <p class="text-sm text-text-secondary mb-3">Setelah sidang/seminar berlangsung, catat hasilnya ke Riwayat Sidang. Pembimbing & penguji akan mengisi nilai.</p>
                    <a href="{{ route('dosen-sidang.index', ['submission' => $submission->id]) }}" class="block text-center px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Catat Hasil Sidang / Seminar</a>
                </section>
            @endif
        </aside>
    </div>
</div>
@endsection
