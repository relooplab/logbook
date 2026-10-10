@extends('layouts.app')
@section('title', 'Dashboard Mahasiswa')
@section('content')
@php
    $canCreate = $ta && in_array($ta->status_ta, [\App\Models\MahasiswaTa::STATUS_AKTIF, \App\Models\MahasiswaTa::STATUS_PENDING_APPROVAL], true);
    $selectDosen = $mahasiswaStatus === 'active' && (!$profileIncomplete || $rejectedProgram) && (!$ta || $rejectedProgram);
    $missingDetail = $ta && $ta->status_ta === \App\Models\MahasiswaTa::STATUS_AKTIF && ($ta->isKp() ? blank($ta->tempat_kp) : blank($ta->judul_ta));
    $continueTa = $ta && $ta->isKp() && $programs->where('jenis', 'ta')->isEmpty() && in_array($ta->fase, ['laporan', 'seminar_kp', 'selesai'], true) && !session('lanjut_ta_dismissed');
    $seminarPhase = $ta && ($ta->isKp() ? $ta->fase === 'seminar_kp' : in_array($ta->fase, ['proposal', 'seminar_hasil', 'sidang'], true));
    $recent = collect($timeline)->where('type', '!=', 'future')->take(4);
    $definitions = \App\Models\Achievement::definitions();
    $featured = collect($definitions)->filter(fn ($def, $code) => $unlockedCodes->contains($code))->take(4);
    $featured = $featured->union(collect($definitions)->reject(fn ($def, $code) => $unlockedCodes->contains($code))->take(max(0, 4 - $featured->count())));
    $healthBadge = match ($regularity) { 'green' => 'badge-success', 'yellow' => 'badge-pending', default => 'badge-danger' };
    $healthLabel = match ($regularity) { 'green' => 'Sehat', 'yellow' => 'Perlu Perhatian', default => 'Risiko' };
@endphp
<div class="mx-auto max-w-[1600px] min-w-0 space-y-4 lg:space-y-5">
    <x-page-header title="Dashboard Mahasiswa" :description="'Selamat datang, '.auth()->user()->name.'. Berikut ringkasan bimbingan '.($ta?->jenisLabel() ?? 'TA/KP').' Anda.'">
        <x-slot:actions>
            @if ($canCreate)
                @if (!empty($pendingRevisionAction['url']))
                    <a href="{{ $pendingRevisionAction['url'] }}" class="btn-primary flex flex-1 items-center justify-center px-3 py-2 text-sm font-semibold sm:flex-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Jawab Revisi →</a>
                    <a href="{{ route('logbook.create', ['program' => $ta->jenis]) }}" class="btn-secondary flex flex-1 items-center justify-center px-3 py-2 text-sm sm:flex-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">+ Logbook</a>
                @else
                    <a href="{{ route('logbook.create', ['program' => $ta->jenis]) }}" class="btn-primary flex flex-1 items-center justify-center px-3 py-2 text-sm font-semibold sm:flex-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">+ Logbook</a>
                    <a href="{{ route('logbook.index', ['program' => $ta->jenis, 'status' => 'revisi']) }}" class="btn-secondary flex flex-1 items-center justify-center px-3 py-2 text-sm sm:flex-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Jawab Revisi</a>
                @endif
            @endif
            <a href="{{ route('logbook.index') }}" class="btn-ghost flex flex-1 items-center justify-center px-3 py-2 text-sm sm:flex-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">Semua Entri</a>
        </x-slot:actions>
    </x-page-header>
    @if (!empty($pendingRevisionAction['url']))
        <div class="rounded-xl border border-status-danger/40 bg-status-danger/10 p-4" role="alert">
            <p class="font-semibold text-text-primary">
                @if (($pendingRevisionThreads ?? collect())->count() > 1)
                    {{ $pendingRevisionThreads->count() }} sesi menunggu jawabanmu
                @else
                    {{ $pendingRevisionAction['entry']->shortTitle() }} menunggu jawabanmu
                @endif
            </p>
            <a href="{{ $pendingRevisionAction['url'] }}" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-[#0b1420] hover:opacity-90">Jawab Revisi →</a>
            @if (($pendingRevisionThreads ?? collect())->count() > 1)
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($pendingRevisionThreads as $thread)
                        <li>
                            <a href="{{ route('logbook.show', $thread['root']) }}" class="text-brand hover:underline">{{ $thread['root']->shortTitle() }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-2 text-xs"><a href="{{ route('logbook.create', ['program' => $ta->jenis]) }}" class="text-text-secondary hover:text-text-primary hover:underline">Mau bimbingan topik baru? Buat logbook baru</a></p>
        </div>
    @endif
    @include('partials.program-selector', ['ta' => $ta, 'route' => 'dashboard'])

    <section class="card min-w-0 p-4 sm:p-5" aria-labelledby="actions-heading">
        <h2 id="actions-heading" class="font-heading font-semibold text-text-primary">Perlu Tindakan</h2>
        <p class="mb-3 text-sm text-text-secondary">Hal-hal yang perlu Anda selesaikan untuk melanjutkan bimbingan.</p>
        <div class="grid min-w-0 gap-2 sm:grid-cols-2 xl:grid-cols-4">
            @if ($profileIncomplete)
                <a href="{{ route('profile.index') }}" class="rounded-control border border-status-pending/40 bg-status-pending/10 p-3 text-sm hover:border-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"><strong class="block text-text-primary">Lengkapi Profil Anda</strong><span class="text-xs text-text-secondary">Isi NIM, WhatsApp dan afiliasi akademik · Isi Profil →</span></a>
            @endif
            @if ($selectDosen)
                <a href="{{ route('profile.select-dosen') }}" class="rounded-control border border-status-pending/40 bg-status-pending/10 p-3 text-sm hover:border-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"><strong class="block text-text-primary">Pilih Dosen untuk Memulai Program</strong><span class="break-words text-xs text-text-secondary">@if ($rejectedProgram) Permintaan sebelumnya ditolak{{ $ta->alasan_ditolak ? ': '.$ta->alasan_ditolak : '' }}. @endif Pilih Dosen →</span></a>
            @elseif ($pendingApproval)
                <div role="status" class="rounded-control border border-status-pending/30 bg-status-pending/10 p-3 text-sm"><strong class="block text-text-primary">Menunggu Persetujuan Dosen</strong><span class="text-xs text-text-secondary">Permintaan bimbingan sedang menunggu persetujuan.</span></div>
            @endif
            @if ($missingDetail)
                <a href="{{ route('profile.index') }}" class="rounded-control border border-status-danger/40 bg-status-danger/10 p-3 text-sm hover:border-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"><strong class="block text-text-primary">Lengkapi {{ $ta->isKp() ? 'Tempat Kerja Praktek' : 'Judul Tugas Akhir' }} Anda</strong><span class="text-xs text-text-secondary">Data wajib diisi agar program dapat diproses · Isi di Profil →</span></a>
            @endif
            @if ($continueTa)
                <div class="flex items-center gap-2 rounded-control border border-brand/30 bg-brand/5 p-3 text-sm"><div class="min-w-0 flex-1"><strong class="block text-text-primary">Selesai KP? Lanjut ke Tugas Akhir</strong><a href="{{ route('profile.select-dosen') }}" class="text-xs font-semibold text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Buat program TA →</a></div><form method="POST" action="{{ route('dashboard.lanjut-ta.dismiss') }}">@csrf<button type="submit" aria-label="Tutup pengingat lanjut ke TA" class="rounded-control p-2 text-text-secondary hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">×</button></form></div>
            @endif
            @if ($ta)
                @foreach ([['count' => $draftCount, 'label' => 'Draf belum dikirim', 'url' => route('logbook.index', ['status' => 'draft', 'program' => $ta->jenis])], ['count' => $revisiCount, 'label' => 'Revisi perlu ditanggapi', 'url' => route('logbook.index', ['status' => 'revisi', 'program' => $ta->jenis])], ['count' => $unresolvedActionItems, 'label' => 'Action items belum selesai', 'url' => route('logbook.feedback', ['program' => $ta->jenis])]] as $action)
                    <a href="{{ $action['url'] }}" class="flex min-w-0 items-center justify-between gap-2 rounded-control border border-border bg-bg-panel p-3 hover:border-brand/50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand {{ $action['count'] ? '' : 'opacity-75' }}"><span class="min-w-0"><strong class="block text-lg tabular-nums text-text-primary">{{ $action['count'] }}</strong><span class="text-xs text-text-secondary">{{ $action['label'] }}</span></span><span class="text-brand" aria-hidden="true">→</span></a>
                @endforeach
            @elseif (!$profileIncomplete && !$selectDosen)
                <p class="text-sm text-text-secondary">Data program Anda (TA/KP) belum diinput oleh admin.</p>
            @endif
        </div>
    </section>

    @if ($ta)
        @if ($unreadAnnouncements->isNotEmpty())
            <section class="card p-4 sm:p-5" aria-label="Pengumuman belum dibaca"><h2 class="font-semibold text-text-primary">Pengumuman Belum Dibaca</h2><div class="divide-y divide-border">@foreach ($unreadAnnouncements as $a)
                <div class="flex flex-wrap items-center gap-3 py-2 text-sm"><div class="min-w-0 flex-1"><strong class="block break-words">{{ $a->title }}</strong><p class="break-words text-text-secondary">{{ $a->body }}</p><p class="text-xs text-text-secondary">Dari: {{ $a->sender?->name }} · {{ $a->created_at?->diffForHumans() }}</p></div><form method="POST" action="{{ route('announcements.read', $a) }}">@csrf<button class="btn-secondary px-3 py-2 text-xs focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Tandai Dibaca</button></form></div>
            @endforeach</div></section>
        @endif
        <div class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <section class="card min-w-0 p-4 sm:p-5" aria-labelledby="journey-heading">
                <div class="flex flex-wrap items-start justify-between gap-2"><div><h2 id="journey-heading" class="font-semibold text-text-primary">Perjalanan {{ $ta->jenisLabel() }}</h2><p class="text-sm text-text-secondary">Fase saat ini dan langkah selanjutnya dalam proses Anda.</p></div><span class="badge badge-info">Fase Saat Ini: {{ $ta->faseLabel() }}</span></div>
                <div class="mt-4 overflow-x-auto pb-2" aria-label="Tahapan {{ $ta->jenisLabel() }}"><div class="flex min-w-max items-start gap-1">
                    @foreach ($faseKeys as $i => $key)
                        <div class="flex items-start"><div class="flex w-20 flex-col items-center text-center sm:w-24"><span class="flex h-8 w-8 items-center justify-center rounded-full border {{ $i < $faseIndex ? 'border-status-success bg-status-success/15 text-status-success' : ($i === $faseIndex ? 'border-brand bg-brand-light text-brand' : 'border-border bg-bg-panel text-text-secondary') }}" aria-hidden="true">{{ $i < $faseIndex ? '✓' : $i + 1 }}</span><span class="mt-1 text-[11px] leading-tight {{ $i === $faseIndex ? 'font-semibold text-brand' : 'text-text-secondary' }}">{{ $faseLabels[$key] ?? $key }}</span><span class="sr-only">{{ $i < $faseIndex ? 'Selesai' : ($i === $faseIndex ? 'Saat ini' : 'Akan datang') }}</span></div>@if (!$loop->last)<span class="mt-4 h-px w-3 shrink-0 {{ $i < $faseIndex ? 'bg-status-success' : 'bg-border' }}" aria-hidden="true"></span>@endif</div>
                    @endforeach
                </div></div>
                <p class="mt-2 text-xs text-text-secondary">Fase ditetapkan oleh dosen pembimbing.</p>
                <div class="mt-4 rounded-control border border-brand/20 bg-brand/5 p-3 sm:p-4"><p class="text-xs font-semibold text-brand">{{ $seminarPhase ? 'Milestone Berikutnya' : 'Konteks Program' }}</p>
                    @if ($seminarPhase)
                        <div class="mt-1 flex flex-wrap items-center justify-between gap-3"><div class="min-w-0"><h3 class="font-semibold text-text-primary">{{ $seminarSubmission ? 'Bahan '.$seminarSubmission->jenisLabel().': '.$seminarSubmission->statusLabel() : 'Kirim Bahan '.$ta->faseLabel() }}</h3><p class="text-xs text-text-secondary">{{ $seminarSubmission ? 'Jadwal: '.$seminarSubmission->tanggal->format('d M Y').' · '.($seminarSubmission->waktu?->format('H:i') ?? 'Belum ditentukan') : 'Unggah dokumen yang diminta untuk '.$ta->faseLabel().'.' }}</p></div><a href="{{ $seminarSubmission ? route('seminar-submission.show', $seminarSubmission) : route('seminar-submission.create', $ta) }}" class="btn-primary px-3 py-2 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">{{ $seminarSubmission ? 'Lihat Detail' : 'Kirim Bahan' }}</a></div>
                    @else
                        <p class="mt-1 text-sm font-semibold text-text-primary">{{ $ta->faseLabel() }}</p><p class="text-xs text-text-secondary">Lanjutkan bimbingan pada fase ini.</p>
                    @endif
                </div>
                <div class="mt-3 text-xs text-text-secondary">{{ $ta->isKp() ? 'Tempat Kerja Praktek' : 'Judul TA' }}: <strong class="break-words text-text-primary">{{ ($ta->isKp() ? $ta->tempat_kp : $ta->judul_ta) ?: 'Belum diisi' }}</strong>@if ($ta->isKp() && $ta->periode_mulai) · {{ $ta->periode_mulai->format('d M Y') }} – {{ $ta->periode_selesai?->format('d M Y') ?? 'sekarang' }} @endif</div>
                @if ($ta->isKp() && $ta->members->isNotEmpty())<p class="mt-2 break-words text-xs text-text-secondary">Anggota Kelompok: {{ $ta->allMembers()->pluck('name')->join(', ') }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-3 border-t border-border pt-3 text-xs font-semibold text-brand">@if ($ta->pembimbing1 || $ta->pembimbing2)<a href="{{ route('logbook.export.pdf', $ta) }}" class="hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Rekap PDF</a><a href="{{ route('logbook.export.excel', $ta) }}" class="hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Excel</a>@endif @if ($ta->isKp())<a href="{{ route('logbook-harian.index', $ta) }}" class="hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Logbook Harian</a><a href="{{ route('profil-perusahaan.index', $ta) }}" class="hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Profil Perusahaan</a>@endif</div>
            </section>
            <section class="card min-w-0 p-4 sm:p-5" aria-labelledby="guidance-heading"><div class="flex flex-wrap items-center justify-between gap-2"><h2 id="guidance-heading" class="font-semibold text-text-primary">Progres Bimbingan</h2><span class="badge {{ $healthBadge }}">{{ $healthLabel }}</span></div>
                <div class="mt-4 flex justify-between text-sm"><span class="text-text-secondary">{{ $approved }} / {{ $target }} sesi disetujui</span><strong class="tabular-nums text-text-primary">{{ $progressPercent }}%</strong></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-bg-hover" role="progressbar" aria-label="Sesi bimbingan disetujui" aria-valuemin="0" aria-valuemax="{{ max(1, $target) }}" aria-valuenow="{{ min($approved, max(1, $target)) }}" aria-valuetext="{{ $approved }} dari {{ $target }} sesi disetujui"><div class="h-full rounded-full bg-brand" style="width: {{ min(100, max(0, $progressPercent)) }}%"></div></div>
                <p class="mt-3 rounded-control border {{ $regularity === 'red' ? 'border-status-danger/30 bg-status-danger/10' : ($regularity === 'yellow' ? 'border-status-pending/30 bg-status-pending/10' : 'border-status-success/30 bg-status-success/10') }} px-3 py-2 text-xs text-text-primary"><strong>{{ $healthLabel }}:</strong> {{ $regularityTooltip }}</p>
                <dl class="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 text-xs">@foreach ([['Pembimbing 1', $ta->pembimbing1], ['Pembimbing 2', $ta->pembimbing2]] as [$label, $lecturer])<dt class="text-text-secondary">{{ $label }}</dt><dd class="min-w-0 break-words text-text-primary">@if ($lecturer)<a href="{{ route('profile.show', $lecturer) }}" class="hover:text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">{{ $lecturer->name }}</a>@else Belum ditentukan @endif</dd>@endforeach
                    @if ($ta->isKp())<dt class="text-text-secondary">Pembimbing Lapangan</dt><dd class="min-w-0 break-words text-text-primary">{{ $ta->pembimbing_lapangan ?: 'Belum ditentukan' }}</dd>@else @foreach ([['Penguji 1', $ta->penguji1], ['Penguji 2', $ta->penguji2]] as [$label, $lecturer])<dt class="text-text-secondary">{{ $label }}</dt><dd class="min-w-0 break-words text-text-primary">@if ($lecturer)<a href="{{ route('profile.show', $lecturer) }}" class="hover:text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">{{ $lecturer->name }}</a>@else Belum ditentukan @endif</dd>@endforeach @endif</dl>
            </section>
        </div>
        @if ($agendaTerdekat->isNotEmpty() || $sidangs->isNotEmpty())
            <section class="card min-w-0 p-4 sm:p-5" aria-label="Agenda dan hasil program"><div class="grid gap-4 md:grid-cols-2">
                @if ($agendaTerdekat->isNotEmpty())<div class="min-w-0"><h2 class="mb-2 font-semibold text-text-primary">Agenda Terdekat</h2><div class="space-y-2">@foreach ($agendaTerdekat as $agenda)<a href="{{ route('seminar-submission.show', $agenda) }}" class="block rounded-control bg-bg-panel p-2 text-sm hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">{{ $agenda->jenisLabel() }} · {{ $agenda->tanggal->format('d M Y') }} · {{ $agenda->waktu?->format('H:i') }}{{ $agenda->lokasi ? ' · '.$agenda->lokasi : '' }}</a>@endforeach</div></div>@endif
                @if ($sidangs->isNotEmpty())<div class="min-w-0"><h2 class="mb-2 font-semibold text-text-primary">Hasil {{ $ta->isKp() ? 'Seminar KP' : 'Sidang' }}</h2><div class="space-y-2">@foreach ($sidangs as $sidang)<div class="flex flex-wrap items-center gap-2 rounded-control bg-bg-panel p-2 text-sm"><span class="min-w-0 flex-1">{{ $sidang->jenisLabel() }} · {{ $sidang->tanggal?->format('d M Y') }}</span><span class="badge {{ $sidang->hasil === 'lulus' ? 'badge-success' : ($sidang->hasil === 'lulus_revisi' ? 'badge-pending' : ($sidang->hasil === 'mengulang' ? 'badge-danger' : 'badge-neutral')) }}">{{ $sidang->hasil ? $sidang->hasilLabel() : 'Belum ada hasil' }}</span>@php $sidang->loadMissing('grades.user'); $grades = $sidang->grades->whereNotNull('filled_at'); @endphp<span class="text-xs text-text-secondary">{{ $grades->isNotEmpty() ? $grades->count().'/'.$sidang->grades->count().' dinilai'.($sidang->nilaiFinal() !== null ? ' · rerata '.$sidang->nilaiFinal() : '') : 'Nilai belum diisi dosen' }}</span></div>@endforeach</div></div>@endif
            </div></section>
        @endif

        <div class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)_minmax(0,1fr)]">
            <section class="card min-w-0 p-4 sm:p-5" aria-labelledby="activity-heading"><div class="mb-3 flex flex-wrap items-start justify-between gap-2"><div><h2 id="activity-heading" class="font-semibold text-text-primary">Aktivitas Terbaru</h2><p class="text-xs text-text-secondary">Riwayat aktivitas bimbingan dan logbook Anda.</p></div><a href="{{ route('logbook.index') }}" class="text-xs font-semibold text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Lihat Semua →</a></div>
                @if ($recent->isEmpty())<p class="rounded-control bg-bg-panel p-3 text-sm text-text-secondary">Belum ada aktivitas terbaru.</p>@else<div class="divide-y divide-border">@foreach ($recent as $item)<div class="flex min-w-0 items-center gap-2 py-2.5"><span class="icon-chip h-8 w-8" aria-hidden="true"><span class="material-symbols-outlined icon-sm">{{ match ($item['type']) { 'comment' => 'forum', 'workspace' => 'folder', default => ($item['status'] === 'approved' ? 'check_circle' : 'description') } }}</span></span><div class="min-w-0 flex-1">@if (!empty($item['url']))<a href="{{ $item['url'] }}" class="block break-words text-sm font-medium text-text-primary hover:text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">{{ $item['label'] }}</a>@else<span class="block break-words text-sm text-text-primary">{{ $item['label'] }}</span>@endif<span class="text-xs text-text-secondary">{{ \App\Models\LogbookEntry::STATUS_LABELS[$item['status']] ?? ($item['type'] === 'comment' ? 'Komentar diterima' : 'File workspace') }}</span></div><time class="shrink-0 text-xs text-text-secondary">{{ $item['date'] }}</time></div>@endforeach</div>@endif
                @if ($ta->isKp())<div class="mt-3 border-t border-border pt-3"><div class="flex items-center justify-between gap-2"><h3 class="text-xs font-semibold text-text-primary">Catatan Harian KP</h3><a href="{{ route('logbook-harian.index', $ta) }}" class="text-xs font-semibold text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Lihat Semua →</a></div>@forelse ($logbookHarian as $lh)<p class="mt-2 text-xs text-text-secondary">{{ $lh->tanggal->format('d M Y') }} · {{ \Illuminate\Support\Str::limit($lh->kegiatan, 90) }}</p>@empty<p class="mt-2 text-xs text-text-secondary">Belum ada catatan harian.</p>@endforelse</div>@endif
            </section>
            @if ($ta->isTa())<section class="card min-w-0 p-4 sm:p-5" aria-labelledby="achievements-heading"><h2 id="achievements-heading" class="font-semibold text-text-primary">Pencapaian</h2><p class="text-xs text-text-secondary">{{ $unlockedAchievements->count() }} / {{ $totalAchievements }} tercapai</p>@if ($totalAchievements === 0)<p class="mt-3 text-sm text-text-secondary">Belum ada pencapaian yang tersedia.</p>@else<div class="mt-3 space-y-1.5">@foreach ($featured as $code => [$icon, $name, $desc])<div class="flex min-w-0 items-center gap-2 rounded-control bg-bg-panel px-3 py-2 {{ $unlockedCodes->contains($code) ? '' : 'opacity-70' }}"><span aria-hidden="true">{{ $icon }}</span><span class="min-w-0 flex-1"><strong class="block truncate text-xs text-text-primary" title="{{ $name }}">{{ $name }}</strong><span class="block text-[11px] text-text-secondary">{{ $unlockedCodes->contains($code) ? 'Terbuka' : 'Terkunci' }} · {{ $desc }}</span></span></div>@endforeach</div>@if (count($definitions) > $featured->count())<p class="mt-2 text-xs text-text-secondary">+{{ count($definitions) - $featured->count() }} pencapaian lainnya</p>@endif @endif</section>@endif
            <section class="card min-w-0 p-4 sm:p-5" aria-labelledby="stats-heading"><h2 id="stats-heading" class="font-semibold text-text-primary">Statistik Ringkas</h2><p class="text-xs text-text-secondary">Ringkasan aktivitas bimbingan Anda.</p><div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                @foreach ([['Streak konsistensi', $stats['streak'].' minggu'], ['Rasio revisi', $stats['ratioRevisi'].'%'], ['Rata-rata tunggu review', $stats['avgWait'] === null ? 'Belum ada data' : $stats['avgWait'].' hari'], ['Rata-rata respons revisi', $stats['avgResponse'] === null ? 'Belum ada data revisi' : $stats['avgResponse'].' hari']] as [$label, $value])<div class="min-w-0 rounded-control bg-bg-panel p-2.5"><strong class="block break-words text-base tabular-nums text-text-primary">{{ $value }}</strong><span class="text-text-secondary">{{ $label }}</span></div>@endforeach
            </div><div class="mt-3 flex flex-wrap gap-1.5 border-t border-border pt-3 text-xs"><span class="rounded-full bg-bg-hover px-2 py-1 text-text-primary">Draf: {{ $stats['draft'] }}</span><span class="rounded-full bg-status-pending/10 px-2 py-1 text-status-pending">Dikirim: {{ $stats['submitted']->count() }}</span><span class="rounded-full bg-status-danger/10 px-2 py-1 text-status-danger">Revisi: {{ $stats['revisi'] }}</span></div></section>
        </div>
        <section class="card min-w-0 p-4 sm:p-5" aria-labelledby="heatmap-heading"><h2 id="heatmap-heading" class="font-semibold text-text-primary">Aktivitas 12 Bulan</h2><p class="mb-3 text-xs text-text-secondary">Intensitas logbook dan bimbingan Anda dalam 12 bulan terakhir.</p>@include('partials.heatmap', ['heatmap' => $heatmap])</section>
    @endif
</div>
@endsection
