@extends('layouts.app')

@section('title', 'Detail Entri')

@section('content')
@php
    $user = auth()->user();
    $program = $logbook->mahasiswaTa;
    $owner = $user->isMahasiswa() && $logbook->mahasiswaTa?->isMember($user);
    $canReview = $user->can('review', $logbook);
    $canReopen = $user->can('reopen', $logbook);
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

    <div class="detail-workspace-grid">
    <div class="space-y-4 min-w-0">
    <div class="space-y-5">

        @include('logbook.partials.review.summary', ['logbook' => $logbook])
        @if (($otherPendingRevisions ?? collect())->isNotEmpty())
            @php $canReviewOther = auth()->user()->can('review', $logbook); @endphp
            <div class="rounded-xl border border-status-pending/40 bg-status-pending/10 p-4 text-sm" role="alert">
                @if ($canReviewOther)
                    <p class="font-semibold text-text-primary">Mahasiswa masih punya revisi yang belum selesai di thread lain</p>
                @else
                    <p class="font-semibold text-text-primary">Selesaikan dulu revisi yang belum selesai</p>
                @endif
                <ul class="mt-2 space-y-1 text-text-secondary">
                    @foreach ($otherPendingRevisions->take(3) as $pending)
                        <li>
                            <a href="{{ route('logbook.show', $pending) }}" class="text-brand hover:underline">
                                @if ($pending->jenis === 'revisi')
                                    Draf revisi #{{ $pending->id }}
                                @else
                                    Sesi {{ $pending->sesi_ke }} · {{ $pending->topik ?? 'Tanpa topik' }} — Revisi Diminta
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($canReviewOther)
                    <p class="mt-2 text-text-secondary">Periksa thread tersebut sebelum mereview entri ini — minta mahasiswa menjawab lewat jalur revisi bila isinya jawaban revisi.</p>
                @else
                    <p class="mt-2 text-text-secondary">Jawaban revisi harus dikirim lewat jalur revisi — bukan lewat sesi logbook baru — agar tidak terputus dari komentar dosen.</p>
                @endif
            </div>
        @endif
        @include('logbook.partials.review.thread', ['logbook' => $logbook])
        @include('logbook.partials.review.feedback', ['logbook' => $logbook])
        @include('logbook.partials.review.notes', ['logbook' => $logbook])
        @include('logbook.partials.review.context', ['logbook' => $logbook, 'useFeedbackButtons' => true, 'lastFeedback' => $lastFeedback ?? null])

    </div>
    </div>

    {{-- ===== Kolom kanan: aksi (sticky) ===== --}}
    <aside class="detail-workspace-panel space-y-4" aria-label="Dokumen dan tindakan">
        @include('logbook.partials.review.mini', ['logbook' => $logbook])
        @include('logbook.partials.review.documents', ['logbook' => $logbook])
        @include('logbook.partials.review.actions', ['logbook' => $logbook])
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
            @include('logbook.partials.review.decision', ['logbook' => $logbook,
                'approveUrl' => route('logbook.approve', $logbook),
                'revisiUrl' => route('logbook.request-revisi', $logbook),
                'archiveUrl' => route('logbook.archive', $logbook),
                'templates' => $templates ?? null,
                'buildUrl' => route('quick-review.build-feedback', $logbook),
                'lastFeedback' => $lastFeedback ?? null,
                'useButtons' => false,
                'submitLabel' => 'Simpan Keputusan',
                'detailUrl' => null,
                'pdfOpened' => $logbook->review_opened_at || (!$logbook->lampiran_path && !$logbook->catatan_perbaikan_path) ? '1' : '0'])
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
@include('logbook.partials.review.actions-script', ['logbook' => $logbook])
@include('logbook.partials.review.decision-script')
@endsection
