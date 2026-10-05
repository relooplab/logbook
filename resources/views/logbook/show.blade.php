@extends('layouts.app')

@section('title', 'Detail Entri')

@section('content')
@php
    $user = auth()->user();
    $program = $logbook->mahasiswaTa;
    $canViewProgram = $program && (
        ($user->isAdmin() && ($user->isSystemAdmin() || $user->institution_id === null || $program->institution_id === $user->institution_id))
        || (!$user->isAdmin() && $user->isDosen() && ($program->isPembimbing($user) || $program->isPenguji($user)))
        || (!$user->isAdmin() && !$user->isDosen() && $user->isMahasiswa() && $program->isMember($user))
    );
    $owner = $user->isMahasiswa() && $logbook->mahasiswaTa?->isMember($user);
    $canReview = $user->can('review', $logbook);
    $canReopen = $user->can('reopen', $logbook);
    $canManageActionItems = $user->can('manageActionItems', $logbook);

    // Peran dosen reviewer entri ini (pembimbing ATAU penguji bila mahasiswa
    // mengirim revisi ke dosen pengujinya).
    $reviewerRole = ($logbook->dosen && $logbook->mahasiswaTa)
        ? $logbook->mahasiswaTa->dosenRoleLabel($logbook->dosen)
        : null;
    $reviewerLabel = $logbook->dosen
        ? (($reviewerRole ? $reviewerRole.' — ' : '').$logbook->dosen->name)
        : ($logbook->mahasiswaTa?->pembimbing1?->name ?? null);
    $chatRecipient = $user->isMahasiswa() ? $logbook->reviewDosen() : $logbook->mahasiswaTa?->mahasiswa;
    $canDiscuss = $logbook->mahasiswaTa && $chatRecipient && $chatRecipient->id !== $user->id
        && (($user->isMahasiswa() && $logbook->mahasiswaTa->user_id === $user->id)
            || ($user->isDosen() && ($logbook->mahasiswaTa->isPembimbing($user) || $logbook->mahasiswaTa->isPenguji($user))));

    // Navigasi "Kembali" konteks-sensitif pada halaman detail entri.
    if ($logbook->parentEntry) {
        // Entri ini adalah revisi yang menjawab entri induk → kembali ke sesi sebelumnya.
        $backUrl = route('logbook.show', $logbook->parentEntry);
        $backLabel = '← Lihat entri induk (sesi sebelumnya)';
    } elseif ($logbook->revisionChildren->count() === 1) {
        // Entri ini adalah induk dari tepat satu revisi berikutnya (umumnya dosen
        // datang dari sini) → kembali ke revisi tersebut.
        $child = $logbook->revisionChildren->first();
        $backUrl = route('logbook.show', $child);
        $backLabel = '← Kembali ke ' . ($child->revision_round ? 'Revisi ke-' . $child->revision_round : 'Revisi');
    } else {
        // Entri berdiri sendiri → kembali ke daftar logbook.
        $backUrl = route('logbook.index', array_filter(['program' => $logbook->mahasiswaTa?->jenis]));
        $backLabel = '← Kembali ke Logbook';
    }
    $revisionRows = collect($logbook->riwayat_perbaikan ?? []);
    $completedRevisions = $revisionRows->filter(fn ($row) => ($row['status'] ?? null) === 'Sudah')->count();
    $revisionPercent = $revisionRows->count() ? (int) round($completedRevisions / $revisionRows->count() * 100) : 0;
@endphp

<div class="detail-workspace space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">{{ $logbook->jenis === 'revisi' ? 'Revisi' : 'Sesi '.$logbook->sesi_ke }}</h1>
            <p class="text-sm text-text-secondary mt-1">{{ $logbook->jenis === 'revisi' ? 'Review revisi mahasiswa dan berikan keputusan.' : 'Detail logbook bimbingan dan tindak lanjutnya.' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canDiscuss)
                <a href="{{ route('chat.start', ['user' => $chatRecipient->id, 'ta' => $logbook->mahasiswa_ta_id, 'entry' => $logbook->id]) }}" class="inline-flex items-center gap-2 rounded-xl border border-brand/40 bg-brand/10 px-4 py-2 text-sm font-medium text-brand hover:bg-brand/20">Diskusikan entri ini</a>
            @endif
            <a href="{{ $logbook->jenis === 'revisi' ? route('logbook.index', array_filter(['program' => $logbook->mahasiswaTa?->jenis])) : $backUrl }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">{{ $logbook->jenis === 'revisi' ? '← Kembali ke Logbook' : $backLabel }}</a>
        </div>
    </div>

        <section class="card p-5 sm:p-6" aria-label="{{ $logbook->jenis === 'revisi' ? 'Ringkasan revisi' : 'Ringkasan logbook bimbingan' }}">
            <div class="revision-summary detail-workspace-card">
                <div class="min-w-0">
                    <p class="font-heading font-semibold text-lg text-text-primary">
                        @if ($canViewProgram && $program->mahasiswa)
                            <a href="{{ route($program->isKp() ? 'mahasiswa-kp.show' : 'mahasiswa-ta.show', $program) }}" class="text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">{{ $program->mahasiswa->name }}</a>
                        @else
                            {{ $program?->mahasiswa?->name ?? '—' }}
                        @endif
                    </p>
                    <p class="text-sm text-text-secondary">Mahasiswa</p>
                </div>
                <dl class="revision-summary-fields text-sm">
                    <div><dt class="text-xs text-text-secondary">Topik</dt><dd class="font-medium mt-1">{{ $logbook->topik ?? ($logbook->jenis === 'revisi' ? 'Revisi' : '—') }}</dd></div>
                    <div><dt class="text-xs text-text-secondary">{{ $logbook->jenis === 'revisi' ? 'Tanggal Pengiriman' : 'Tanggal Bimbingan' }}</dt><dd class="font-medium mt-1">{{ $logbook->tanggal_tampil?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-text-secondary">{{ $logbook->jenis === 'revisi' ? 'Ditujukan kepada' : 'Dosen' }}</dt><dd class="font-medium mt-1">{{ $reviewerLabel ?? '—' }}</dd></div>
                </dl>
                <div class="text-left lg:text-right">@include('partials.status-badge', ['status' => $logbook->status]) @if($logbook->revision_round)<p class="text-xs text-text-secondary mt-2">Revisi ke-{{ $logbook->revision_round }}</p>@endif</div>
            </div>
        </section>

    <div class="detail-workspace-grid">
    <div class="space-y-4 min-w-0">
    <div class="space-y-5">

        @if ($owner && $logbook->status === 'submitted')
            <div class="px-4 py-3 rounded-xl bg-bg-panel border border-border text-sm flex flex-wrap items-center gap-2">
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

        @if ($logbook->parentEntry)
            <div class="px-4 py-3 rounded-xl bg-brand/10 border border-brand/20 text-sm">
                <p class="font-semibold">Menjawab entri induk #{{ $logbook->parentEntry->id }}</p>
                <a href="{{ route('logbook.show', $logbook->parentEntry) }}" class="text-brand hover:underline">Lihat feedback dan anotasi sesi sebelumnya</a>
            </div>
        @endif

        @if ($logbook->revisionChildren->isNotEmpty())
            <div class="px-4 py-3 rounded-xl bg-bg-panel border border-border text-sm">
                <p class="font-semibold mb-1">Riwayat revisi</p>
                @foreach ($logbook->revisionChildren as $child)
                    <a href="{{ route('logbook.show', $child) }}" class="block text-brand hover:underline">Revisi {{ $child->revision_round ?: '—' }} · {{ ucfirst($child->status) }}</a>
                @endforeach
            </div>
        @endif

        @if ($logbook->jenis === 'revisi')
            <div>
                <h3 class="text-sm font-semibold text-text-secondary mb-1">Pesan untuk Dosen</h3>
                <div class="text-sm whitespace-pre-wrap">{{ $logbook->progres_kendala ?: '—' }}</div>
            </div>
            <section class="card p-5 sm:p-6" aria-labelledby="revision-list-title">
                <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                    <div><h2 id="revision-list-title" class="font-heading font-semibold text-lg text-text-primary">Catatan Perbaikan</h2><p class="text-sm text-text-secondary mt-1">Daftar catatan perbaikan dari dosen beserta respons mahasiswa.</p></div>
                    @if ($revisionRows->isNotEmpty())<button type="button" id="revision-expand-all" class="px-3 py-2 rounded-xl border border-border text-sm text-text-primary hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" aria-controls="revision-items">Expand Semua</button>@endif
                </div>
                <div class="flex items-center gap-3 mb-4 text-sm"><span>{{ $completedRevisions }} dari {{ $revisionRows->count() }} diperbaiki</span><div class="h-2 flex-1 rounded-full bg-bg-hover overflow-hidden" role="progressbar" aria-label="Progres perbaikan" aria-valuenow="{{ $completedRevisions }}" aria-valuemin="0" aria-valuemax="{{ $revisionRows->count() }}"><div class="h-full bg-status-success rounded-full" style="width: {{ $revisionPercent }}%"></div></div><span>{{ $revisionPercent }}%</span></div>
                <div id="revision-items" class="space-y-3">
                @forelse ($revisionRows as $r)
                    @php $revisionStatus = $r['status'] ?? '—'; @endphp
                    <details class="revision-item rounded-xl bg-bg-panel border border-border p-4 sm:p-5 detail-workspace-card" @if($loop->first) open @endif>
                        <summary class="revision-item-summary cursor-pointer flex flex-wrap items-center gap-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand rounded-lg">
                            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-bg-hover font-mono text-sm">{{ $loop->iteration }}</span>
                            <span class="font-semibold text-sm text-text-primary">{{ $r['halaman'] ?? 'Bagian tidak disebutkan' }}</span>
                            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium
                                {{ $revisionStatus === 'Sudah' ? 'bg-status-success/10 text-status-success' : '' }}
                                {{ $revisionStatus === 'Sebagian' ? 'bg-status-pending/10 text-status-pending' : '' }}
                                {{ $revisionStatus === 'Belum' ? 'bg-status-danger/10 text-status-danger' : '' }}
                            ">{{ $revisionStatus }}</span><span class="material-symbols-outlined icon-sm ml-auto revision-chevron" aria-hidden="true">expand_more</span>
                            <span class="revision-preview text-xs text-text-secondary truncate w-full pl-12">{{ $r['komentar_dosen'] ?? '—' }} · {{ $r['perbaikan'] ?? '—' }}</span>
                        </summary>
                        @if($logbook->lampiran_path || $logbook->catatan_perbaikan_path)<a href="{{ route('logbook.pdf-viewer', $logbook) }}" class="inline-flex mt-3 text-xs text-status-info hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Lihat di PDF ↗</a>@endif
                        <dl class="revision-pair mt-4 text-sm">
                            <div class="rounded-xl bg-bg-surface p-4 min-w-0"><dt class="text-xs font-semibold text-text-secondary mb-2">Komentar Dosen</dt><dd class="whitespace-pre-wrap text-text-primary">{{ $r['komentar_dosen'] ?? '—' }}</dd></div>
                            <div class="rounded-xl bg-bg-surface p-4 min-w-0"><dt class="text-xs font-semibold text-text-secondary mb-2">Perbaikan yang Dilakukan Mahasiswa</dt><dd class="whitespace-pre-wrap text-text-primary">{{ $r['perbaikan'] ?? '—' }}</dd></div>
                        </dl>
                    </details>
                @empty
                    <p class="text-sm text-text-secondary">Belum ada catatan perbaikan.</p>
                @endforelse
                </div>
            </section>
        @else
            <section class="card p-5 sm:p-6 detail-workspace-card" aria-labelledby="logbook-progress-title">
                <h2 id="logbook-progress-title" class="font-heading font-semibold text-lg text-text-primary mb-3">Ringkasan Perbaikan</h2>
                <div class="text-sm whitespace-pre-wrap break-words">{{ $logbook->progres_kendala ?: 'Belum ada ringkasan perbaikan.' }}</div>
            </section>
        @endif

        @if ($logbook->feedback_dosen)
            <div class="px-4 py-3 rounded-xl bg-status-pending/10 border-l-4 border-status-pending text-sm">
                <div class="flex items-center gap-1.5 mb-1 text-xs font-semibold text-status-pending uppercase tracking-wide"><span class="material-symbols-outlined icon-sm text-accent-teal">forum</span> Umpan Balik Dosen</div>
                <div class="text-sm">{{ $logbook->feedback_dosen }}</div>
            </div>
        @endif

    </div>
    </div>

    {{-- ===== Kolom kanan: aksi (sticky) ===== --}}
    <aside class="detail-workspace-panel space-y-4" aria-label="Dokumen dan tindakan">
        @if ($logbook->jenis === 'revisi')
            <section class="card p-5 detail-workspace-card" aria-label="Status review revisi">
                <h2 class="font-heading font-semibold text-text-primary mb-3">Review</h2>
                @include('partials.status-badge', ['status' => $logbook->status])
                <dl class="grid grid-cols-3 gap-2 mt-4 text-center text-xs text-text-secondary">
                    <div><dt>Total Catatan</dt><dd class="text-lg font-semibold text-text-primary mt-1">{{ $revisionRows->count() }}</dd></div>
                    <div><dt>Sudah Diperbaiki</dt><dd class="text-lg font-semibold text-status-success mt-1">{{ $completedRevisions }}</dd></div>
                    <div><dt>Belum Diperbaiki</dt><dd class="text-lg font-semibold text-status-danger mt-1">{{ $revisionRows->count() - $completedRevisions }}</dd></div>
                </dl>
                <div class="mt-4 h-2 rounded-full bg-bg-hover overflow-hidden" role="progressbar" aria-label="Progres review revisi" aria-valuenow="{{ $completedRevisions }}" aria-valuemin="0" aria-valuemax="{{ $revisionRows->count() }}"><div class="h-full bg-status-success rounded-full" style="width: {{ $revisionPercent }}%"></div></div>
            </section>
        @endif
        @if ($logbook->lampiran_path || $logbook->catatan_perbaikan_path)
            <section class="card p-5 space-y-3 detail-workspace-card">
                <h2 class="font-heading font-semibold text-text-primary">Dokumen</h2>
                <a href="{{ route('logbook.pdf-viewer', $logbook) }}" class="block text-center px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">{{ $canReview ? 'Buka PDF & Anotasi' : 'Lihat PDF & Komentar' }}</a>
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

        @if ($canManageActionItems)
            <section class="card p-5 space-y-3 detail-workspace-card">
                <div class="flex items-center justify-between gap-2"><h2 class="font-heading font-semibold text-text-primary">Action Items</h2><button type="button" id="action-item-add-toggle" class="px-3 py-2 rounded-xl border border-border text-sm hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" aria-expanded="false" aria-controls="action-item-add-form">+ Tambah</button></div>
                <div id="action-items-list" class="space-y-3">
                    @forelse ($logbook->actionItems as $item)
                        <div class="flex items-start gap-2 action-item-row" data-item-id="{{ $item->id }}">
                            @if ($user->can('update', $logbook))
                                <input type="checkbox" class="action-item-toggle rounded bg-bg-surface mt-1" aria-label="Tandai selesai: {{ $item->text }}" @checked($item->is_done)>
                            @else
                                <span class="material-symbols-outlined icon-sm {{ $item->is_done ? '' : 'text-text-secondary' }}">{{ $item->is_done ? 'check_circle' : 'radio_button_unchecked' }}</span>
                            @endif
                            <span class="flex-1 min-w-0 text-sm {{ $item->is_done ? 'line-through text-text-secondary' : '' }}">{{ $item->text }}</span>
                            <button type="button" class="action-item-delete text-status-danger hover:underline text-xs shrink-0">Hapus</button>
                        </div>
                    @empty
                        <p class="text-sm text-text-secondary">Belum ada action item.</p>
                    @endforelse
                </div>
                <form id="action-item-add-form" class="hidden space-y-2">
                    @csrf
                    <label for="action-item-text" class="sr-only">Tambah action item</label>
                    <input id="action-item-text" type="text" name="text" placeholder="Tambah action item..." maxlength="500" required
                        class="w-full min-w-0 rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <p id="action-item-error" class="hidden text-status-danger text-xs" role="alert"></p>
                    <div class="flex gap-2"><button type="submit" class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Tambah</button><button type="button" id="action-item-add-cancel" class="px-4 py-2 rounded-xl bg-bg-hover text-sm hover:bg-border">Batal</button></div>
                </form>
            </section>
        @endif
        @if ($owner && $logbook->isEditable())
            <div class="card p-5 space-y-2">
                <h2 class="font-heading font-semibold text-text-primary mb-1">Tindakan</h2>
                <a href="{{ route('logbook.edit', $logbook) }}" class="block text-center px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Edit</a>
                <form method="POST" action="{{ route('logbook.submit', $logbook) }}">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Kirim ke dosen</button>
                </form>
                <form method="POST" action="{{ route('logbook.destroy', $logbook) }}"
                    onsubmit="return confirm('Hapus entri ini? Tindakan tidak dapat dibatalkan.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm font-medium hover:bg-status-danger/20">Hapus</button>
                </form>
            </div>
        @endif

        @if ($owner && $logbook->status === 'revisi' && !$logbook->isLockedByActiveRevision())
            <a href="{{ route('logbook.create-revisi', ['parent_entry_id' => $logbook->id, 'program' => $logbook->mahasiswaTa?->jenis]) }}" class="block text-center px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Buat Revisi dari Umpan Balik Ini</a>
        @endif

        @if ($logbook->status === \App\Models\LogbookEntry::STATUS_ARCHIVED)
            <section class="card p-5 space-y-2" aria-label="Informasi arsip">
                <h2 class="font-heading font-semibold text-text-primary">Entri Diarsipkan</h2>
                @if ($logbook->archive_reason)<p class="text-sm text-text-secondary">Catatan: {{ $logbook->archive_reason }}</p>@endif
                @if ($logbook->archived_at)<p class="text-xs text-text-secondary">Diarsipkan {{ $logbook->archived_at->format('d M Y H:i') }}</p>@endif
            </section>
        @endif

        @if ($canReview && $logbook->status === 'submitted')
            <section class="card p-5 space-y-4 detail-workspace-card">
                <div>
                    <h2 class="font-heading font-semibold text-text-primary">Keputusan Review</h2>
                    @if ($logbook->jenis === 'revisi')
                        <p class="text-sm text-text-secondary mt-1">{{ $completedRevisions }} dari {{ $revisionRows->count() }} catatan ditandai sudah oleh mahasiswa.</p>
                    @endif
                </div>
                @php $reviewDecision = old('review_decision', old('feedback_dosen') ? 'revisi' : ''); @endphp
                <form method="POST" action="{{ route('logbook.request-revisi', $logbook) }}" id="review-decision-form" class="space-y-4"
                    data-entry-kind="{{ $logbook->jenis }}"
                    data-approve-url="{{ route('logbook.approve', $logbook) }}"
                    data-revision-url="{{ route('logbook.request-revisi', $logbook) }}"
                    data-archive-url="{{ route('logbook.archive', $logbook) }}"
                    data-pdf-opened="{{ $logbook->review_opened_at ? '1' : '0' }}"
                    data-has-pdf="{{ $logbook->lampiran_path || $logbook->catatan_perbaikan_path ? '1' : '0' }}">
                    @csrf
                    <fieldset class="space-y-2">
                        <legend class="sr-only">Pilih keputusan review</legend>
                        <label class="decision-choice flex items-start gap-3 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                            <input type="radio" name="review_decision" value="approve" class="mt-1 accent-brand" required @checked($reviewDecision === 'approve')><span><strong class="block text-text-primary">Setujui</strong><span class="text-xs text-text-secondary">{{ $logbook->jenis === 'revisi' ? 'Revisi telah sesuai dan dapat dilanjutkan.' : 'Entri bimbingan telah sesuai dan dapat dilanjutkan.' }}</span></span>
                        </label>
                        <label class="decision-choice flex items-start gap-3 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                            <input type="radio" name="review_decision" value="revisi" class="mt-1 accent-brand" required @checked($reviewDecision === 'revisi')><span><strong class="block text-text-primary">Minta Revisi</strong><span class="text-xs text-text-secondary">Masih perlu revisi atau perbaikan tambahan.</span></span>
                        </label>
                        <label class="decision-choice flex items-start gap-3 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                            <input type="radio" name="review_decision" value="archive" class="mt-1 accent-brand" required @checked($reviewDecision === 'archive')><span><strong class="block text-text-primary">Arsipkan</strong><span class="text-xs text-text-secondary">Simpan sebagai riwayat tanpa menyetujui atau meminta revisi.</span></span>
                        </label>
                    </fieldset>
                    <div id="revision-feedback-wrap" class="space-y-2">
                        <label for="revision-feedback" class="block text-xs font-semibold text-text-secondary">Feedback dosen <span id="revision-feedback-required" class="{{ $reviewDecision === 'revisi' ? '' : 'hidden' }}">(wajib untuk revisi, minimal 20 karakter)</span></label>
                        <textarea id="revision-feedback" name="feedback_dosen" rows="4" maxlength="5000" @if($reviewDecision === 'revisi') minlength="20" required @endif placeholder="Opsional saat menyetujui; untuk revisi jelaskan perbaikan minimal 20 karakter..."
                            class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">{{ old('feedback_dosen') }}</textarea>
                        @error('feedback_dosen') <p class="text-status-danger text-xs">{{ $message }}</p> @enderror
                    </div>
                    <div id="archive-reason-wrap" class="space-y-2 {{ $reviewDecision === 'archive' ? '' : 'hidden' }}">
                        <label for="archive-reason" class="block text-xs font-semibold text-text-secondary">Catatan arsip (opsional)</label>
                        <textarea id="archive-reason" name="archive_reason" rows="3" maxlength="5000" placeholder="Tambahkan catatan jika perlu..." class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">{{ old('archive_reason') }}</textarea>
                        @error('archive_reason') <p class="text-status-danger text-xs">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" id="review-decision-submit" class="w-full px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90 disabled:opacity-50" disabled>Simpan Keputusan</button>
                </form>
            </section>
        @endif

        @if ($canReopen && $logbook->status === 'approved')
            <div class="card p-5 space-y-4">
                <h2 class="font-heading font-semibold text-text-primary">Buka Kembali Persetujuan</h2>
                <div class="px-4 py-3 rounded-xl bg-status-pending/10 border border-status-pending/20 text-sm text-text-secondary">
                    Entri ini sudah disetujui. Jika ternyata masih perlu perbaikan, batalkan persetujuan atau minta revisi kembali ke mahasiswa.
                </div>
                <form method="POST" action="{{ route('logbook.reopen', $logbook) }}"
                    onsubmit="return confirm('Batalkan persetujuan? Entri akan kembali menunggu review.');">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Batalkan Persetujuan → Menunggu Review</button>
                </form>
                <form method="POST" action="{{ route('logbook.reopen-revisi', $logbook) }}" class="space-y-2"
                    onsubmit="return confirm('Minta revisi kembali ke mahasiswa dengan feedback ini?');">
                    @csrf
                    <textarea name="feedback_dosen" rows="3" required minlength="20" placeholder="Feedback revisi lanjutan wajib diisi (minimal 20 karakter)..."
                        class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">{{ old('feedback_dosen') }}</textarea>
                    @error('feedback_dosen')
                        <p class="text-status-danger text-xs">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm font-medium hover:bg-status-danger/20">Minta Revisi Kembali</button>
                </form>
            </div>
        @endif
    </aside>
    </div>
</div>
@endsection

@section('scripts')
<script>
    var expandAll = document.getElementById('revision-expand-all');
    if (expandAll) {
        var revisionItems = Array.from(document.querySelectorAll('#revision-items .revision-item'));
        function syncExpandLabel() { expandAll.textContent = revisionItems.every(item => item.open) ? 'Collapse Semua' : 'Expand Semua'; }
        expandAll.addEventListener('click', function () {
            var open = !revisionItems.every(item => item.open);
            revisionItems.forEach(item => { item.open = open; });
            syncExpandLabel();
        });
        revisionItems.forEach(item => item.addEventListener('toggle', syncExpandLabel));
        syncExpandLabel();
    }
    // ---- Action items (pemilik boleh toggle + tambah; reviewer boleh tambah/hapus) ----
    var actionList = document.getElementById('action-items-list');
    var actionAddForm = document.getElementById('action-item-add-form');
    var logbookId = {{ $logbook->id }};
    var ownerCanToggle = {{ $user->can('update', $logbook) ? 'true' : 'false' }};

    function actionItemRow(item, interactive) {
        interactive = interactive !== undefined ? interactive : true;
        var row = document.createElement('div');
        row.className = 'flex items-start gap-2 action-item-row';
        row.dataset.itemId = item.id;
        if (interactive) {
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'action-item-toggle rounded bg-bg-surface mt-1';
            checkbox.setAttribute('aria-label', 'Tandai selesai: ' + item.text);
            checkbox.checked = !!item.is_done;
            row.appendChild(checkbox);
        } else {
            var icon = document.createElement('span');
            icon.className = 'material-symbols-outlined icon-sm ' + (item.is_done ? '' : 'text-text-secondary');
            icon.textContent = item.is_done ? 'check_circle' : 'radio_button_unchecked';
            row.appendChild(icon);
        }
        var text = document.createElement('span');
        text.className = 'flex-1 min-w-0 text-sm' + (item.is_done ? ' line-through text-text-secondary' : '');
        text.textContent = item.text;
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'action-item-delete text-status-danger hover:underline text-xs shrink-0';
        del.textContent = 'Hapus';
        row.appendChild(text);
        row.appendChild(del);
        return row;
    }

    if (actionList) {
        // Toggle
        actionList.addEventListener('change', function (e) {
            if (e.target.classList.contains('action-item-toggle')) {
                var row = e.target.closest('.action-item-row');
                var id = row.dataset.itemId;
                var previous = !e.target.checked;
                fetch('/logbook/' + logbookId + '/action-items/' + id + '/toggle', {
                    method: 'POST',
                    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json'},
                }).then(r => { if (!r.ok) throw new Error('Gagal memperbarui action item.'); return r.json(); }).then(data => {
                    e.target.checked = data.is_done;
                    var textEl = row.querySelector('span');
                    textEl.classList.toggle('line-through', data.is_done);
                    textEl.classList.toggle('text-text-secondary', data.is_done);
                }).catch(() => { e.target.checked = previous; window.alert('Action item belum tersimpan. Coba lagi.'); });
            }
        });

        // Delete
        actionList.addEventListener('click', function (e) {
            if (e.target.classList.contains('action-item-delete')) {
                var row = e.target.closest('.action-item-row');
                var id = row.dataset.itemId;
                if (!confirm('Hapus action item ini?')) return;
                fetch('/logbook/' + logbookId + '/action-items/' + id, {
                    method: 'DELETE',
                    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json'},
                }).then(r => { if (!r.ok) throw new Error('Gagal menghapus action item.'); return r.json(); }).then(() => {
                    row.remove();
                    if (!actionList.querySelector('.action-item-row')) {
                        actionList.innerHTML = '<p class="text-sm text-text-secondary">Belum ada action item.</p>';
                    }
                }).catch(() => window.alert('Action item belum terhapus. Coba lagi.'));
            }
        });
    }

    if (actionAddForm) {
        var actionToggle = document.getElementById('action-item-add-toggle');
        function setActionFormOpen(open) {
            actionAddForm.classList.toggle('hidden', !open);
            actionToggle.setAttribute('aria-expanded', String(open));
            if (open) actionAddForm.querySelector('input[name="text"]').focus();
            else actionToggle.focus();
        }
        actionToggle.addEventListener('click', function () { setActionFormOpen(actionAddForm.classList.contains('hidden')); });
        document.getElementById('action-item-add-cancel').addEventListener('click', function () { setActionFormOpen(false); });
        actionAddForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = actionAddForm.querySelector('input[name="text"]');
            var val = input.value.trim();
            if (!val) return;
            var submitButton = actionAddForm.querySelector('button[type="submit"]');
            var error = document.getElementById('action-item-error');
            submitButton.disabled = true;
            error.classList.add('hidden');
            fetch('/logbook/' + logbookId + '/action-items', {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({text: val}),
            }).then(r => { if (!r.ok) throw new Error('Gagal menambah action item.'); return r.json(); }).then(item => {
                input.value = '';
                var empty = actionList.querySelector('p');
                if (empty) empty.remove();
                actionList.appendChild(actionItemRow(item, ownerCanToggle));
                setActionFormOpen(false);
            }).catch(() => {
                error.textContent = 'Action item belum tersimpan. Coba lagi.';
                error.classList.remove('hidden');
            }).finally(() => { submitButton.disabled = false; });
        });
    }

    var decisionForm = document.getElementById('review-decision-form');
    if (decisionForm) {
        var decisionChoices = decisionForm.querySelectorAll('input[name="review_decision"]');
        var feedbackWrap = document.getElementById('revision-feedback-wrap');
        var feedback = document.getElementById('revision-feedback');
        var archiveReason = document.getElementById('archive-reason');
        var archiveWrap = document.getElementById('archive-reason-wrap');
        var decisionSubmit = document.getElementById('review-decision-submit');
        function syncDecision() {
            var selected = decisionForm.querySelector('input[name="review_decision"]:checked');
            var needsRevision = selected && selected.value === 'revisi';
            var isArchive = selected && selected.value === 'archive';
            decisionForm.action = isArchive ? decisionForm.dataset.archiveUrl : (needsRevision ? decisionForm.dataset.revisionUrl : decisionForm.dataset.approveUrl);
            feedbackWrap.classList.toggle('hidden', !!isArchive);
            feedback.disabled = !!isArchive;
            feedbackWrap.querySelector('#revision-feedback-required').classList.toggle('hidden', !needsRevision);
            feedback.required = !!needsRevision;
            feedback.minLength = needsRevision ? 20 : 0;
            archiveWrap.classList.toggle('hidden', !isArchive);
            archiveReason.disabled = !isArchive;
            archiveReason.required = false;
            decisionSubmit.disabled = !selected;
            decisionSubmit.textContent = isArchive ? 'Arsipkan Entri' : (needsRevision ? 'Kirim Permintaan Revisi' : (selected ? (decisionForm.dataset.entryKind === 'revisi' ? 'Setujui Revisi' : 'Setujui Entri') : 'Pilih Keputusan'));
        }
        decisionChoices.forEach(choice => choice.addEventListener('change', syncDecision));
        syncDecision();
        decisionForm.addEventListener('submit', function (event) {
            var selected = decisionForm.querySelector('input[name="review_decision"]:checked');
            if (!selected) {
                event.preventDefault();
                decisionChoices[0].focus();
                return;
            }
            if (selected.value === 'approve' && decisionForm.dataset.hasPdf === '1' && decisionForm.dataset.pdfOpened !== '1' &&
                !window.confirm('Lampiran PDF belum dibuka. Tetap setujui entri ini?')) {
                event.preventDefault();
                return;
            }
            var message = selected.value === 'archive' ? 'Arsipkan entri ini tanpa menyetujui atau meminta revisi?' : (selected.value === 'approve' ? (decisionForm.dataset.entryKind === 'revisi' ? 'Setujui revisi ini?' : 'Setujui entri logbook ini?') : 'Kirim permintaan revisi kepada mahasiswa?');
            if (!window.confirm(message)) {
                event.preventDefault();
                return;
            }
            var button = decisionForm.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Memproses...';
            }
        });
    }
</script>
@endsection
