@php
    // Kartu ringkasan identitas + pesan/ringkasan + penanda (dipakai show & quick-review).
    $reviewUser = auth()->user();
    $reviewProgram = $logbook->mahasiswaTa;
    $reviewerRole = ($logbook->dosen && $reviewProgram)
        ? $reviewProgram->dosenRoleLabel($logbook->dosen)
        : null;
    $reviewerLabel = $logbook->dosen
        ? (($reviewerRole ? $reviewerRole.' — ' : '').$logbook->dosen->name)
        : ($reviewProgram?->pembimbing1?->name ?? null);
    $canViewProgram = $reviewProgram && (
        ($reviewUser->isAdmin() && ($reviewUser->isSystemAdmin() || $reviewUser->institution_id === null || $reviewProgram->institution_id === $reviewUser->institution_id))
        || (!$reviewUser->isAdmin() && $reviewUser->isDosen() && ($reviewProgram->isPembimbing($reviewUser) || $reviewProgram->isPenguji($reviewUser)))
        || (!$reviewUser->isAdmin() && !$reviewUser->isDosen() && $reviewUser->isMahasiswa() && $reviewProgram->isMember($reviewUser))
    );
    $isOwner = $reviewUser->isMahasiswa() && $reviewProgram?->isMember($reviewUser);
    $reviewStudent = $reviewProgram?->mahasiswa;
@endphp
<section class="card p-5 sm:p-6" aria-label="{{ $logbook->jenis === 'revisi' ? 'Ringkasan revisi' : 'Ringkasan logbook bimbingan' }}">
    <div class="revision-summary detail-workspace-card">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                @if ($reviewStudent?->photoUrl())
                    <img src="{{ $reviewStudent->photoUrl() }}" alt="" class="h-7 w-7 shrink-0 rounded-full object-cover">
                @else
                    <span class="avatar h-7 w-7 shrink-0 text-[10px]" aria-hidden="true">{{ $reviewStudent?->initials() ?? 'M' }}</span>
                @endif
                <div class="min-w-0">
                    <p class="font-heading font-semibold text-sm text-text-primary truncate leading-tight">
                        @if ($canViewProgram && $reviewProgram->mahasiswa)
                            <a href="{{ route($reviewProgram->isKp() ? 'mahasiswa-kp.show' : 'mahasiswa-ta.show', $reviewProgram) }}" class="text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">{{ $reviewProgram->mahasiswa->name }}</a>
                        @else
                            {{ $reviewProgram?->mahasiswa?->name ?? '—' }}
                        @endif
                        @if ($reviewStudent?->nim)<span class="ml-1.5 font-mono text-xs font-normal text-text-secondary">{{ $reviewStudent->nim }}</span>@endif
                    </p>
                </div>
            </div>
        </div>
        <dl class="revision-summary-fields text-sm">
            <div><dt class="text-xs text-text-secondary">Topik</dt><dd class="font-medium mt-1">{{ $logbook->topik ?? ($logbook->jenis === 'revisi' ? 'Revisi' : '—') }}</dd></div>
            <div><dt class="text-xs text-text-secondary">{{ $logbook->jenis === 'revisi' ? 'Tanggal Pengiriman' : 'Tanggal Bimbingan' }}</dt><dd class="font-medium mt-1">{{ $logbook->tanggal_tampil?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt class="text-xs text-text-secondary">{{ $logbook->jenis === 'revisi' ? 'Ditujukan kepada' : 'Dosen' }}</dt><dd class="font-medium mt-1">{{ $reviewerLabel ?? '—' }}</dd></div>
            @if ($logbook->submitted_at)
                <div><dt class="text-xs text-text-secondary">Menunggu review</dt><dd class="font-medium mt-1">{{ max(0, (int) $logbook->submitted_at->diffInDays(now())) }} hari</dd></div>
            @endif
            @if ($reviewProgram?->judul_ta)
                <div><dt class="text-xs text-text-secondary">Judul TA</dt><dd class="font-medium mt-1">{{ $reviewProgram->judul_ta }}</dd></div>
            @endif
        </dl>
        <div class="text-left lg:text-right">@include('partials.status-badge', ['status' => $logbook->status]) @if($logbook->revision_round)<p class="text-xs text-text-secondary mt-2">Revisi ke-{{ $logbook->revision_round }}</p>@endif</div>
    </div>
    @if (collect($logbook->riwayat_perbaikan ?? [])->isNotEmpty())
        <div class="mt-4">
            <h3 class="text-sm font-semibold text-text-secondary mb-1">Pesan untuk Dosen</h3>
            <div class="text-sm whitespace-pre-wrap">{{ $logbook->progres_kendala ?: '—' }}</div>
        </div>
    @else
        <div class="mt-4">
            <h3 class="text-sm font-semibold text-text-secondary mb-1">Ringkasan Perbaikan</h3>
            <div class="text-sm whitespace-pre-wrap break-words">{{ $logbook->progres_kendala ?: 'Belum ada ringkasan perbaikan.' }}</div>
        </div>
    @endif
</section>
@if ($isOwner && $logbook->status === 'submitted')
    <div class="px-4 py-3 rounded-xl bg-bg-panel border border-border text-sm flex flex-wrap items-center gap-2 mt-4">
        @if ($logbook->review_opened_at)
            <span class="inline-flex items-center gap-1.5 text-status-success font-medium">
                <span class="material-symbols-outlined icon-sm text-status-info">visibility</span> Sudah dilihat dosen
            </span>
            <span class="text-xs text-text-secondary">dibuka {{ $logbook->review_opened_at->diffForHumans() }}</span>
        @else
            <span class="inline-flex items-center gap-1.5 text-text-secondary font-medium">
                <span class="material-symbols-outlined icon-sm text-status-info">visibility_off</span> Belum dilihat dosen
            </span>
        @endif
    </div>
@endif
@if ($logbook->isLockedByActiveRevision())
    <p class="mt-4 rounded-xl bg-status-pending/10 p-3 text-xs text-text-secondary"><strong class="text-status-pending">Terkunci:</strong> entri induk sudah mempunyai revisi anak. Perbaikan selanjutnya dilakukan melalui revisi baru.</p>
@endif
@if ($logbook->exceedsRevisionRoundLimit())
    <p class="mt-3 text-sm text-status-pending">Sesi revisi sudah mencapai batas perhatian. Pertimbangkan bimbingan tatap muka sebelum siklus berikutnya.</p>
@endif
