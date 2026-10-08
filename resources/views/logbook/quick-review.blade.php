@extends('layouts.app')
@section('title', 'Quick Review')
@section('content')
<div class="detail-workspace space-y-5">
@if (!$entry)
    <div class="card mx-auto max-w-2xl p-8 text-center sm:p-12">
        <span class="material-symbols-outlined icon-lg text-status-success" aria-hidden="true">task_alt</span>
        <h1 class="mt-3 font-heading text-2xl font-bold text-text-primary">Quick Review</h1>
        <p class="mt-2 text-sm text-text-secondary">Tidak ada item yang menunggu review.</p>
        <a href="{{ route('dashboard') }}" class="btn-primary mt-5 inline-flex px-5 py-2.5 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Kembali ke Dashboard</a>
    </div>
@else
    @php
        $student = $entry->mahasiswaTa?->mahasiswa;
        $isRevision = $entry->jenis === \App\Models\LogbookEntry::JENIS_REVISI;
        $qrViewer = auth()->user();
        $qrChatRecipient = $entry->mahasiswaTa?->mahasiswa;
        $qrCanDiscuss = $qrChatRecipient && $qrChatRecipient->id !== $qrViewer->id
            && $qrViewer->isDosen() && ($entry->mahasiswaTa?->isPembimbing($qrViewer) || $entry->mahasiswaTa?->isPenguji($qrViewer));
    @endphp
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0"><div class="flex flex-wrap items-center gap-3"><h1 class="font-heading text-2xl font-bold text-text-primary">Quick Review</h1><span class="badge badge-info">{{ $queueIndex + 1 }} dari {{ $queueCount }}</span></div><p class="mt-1 text-sm text-text-secondary">{{ $isRevision ? 'Revisi' : 'Logbook sesi '.$entry->sesi_ke }} · {{ $student?->name ?? 'Mahasiswa' }}</p></div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($qrCanDiscuss)
                <a href="{{ route('chat.start', ['user' => $qrChatRecipient->id, 'ta' => $entry->mahasiswa_ta_id, 'entry' => $entry->id]) }}" class="inline-flex items-center gap-2 rounded-xl border border-brand/40 bg-brand/10 px-4 py-2 text-sm font-medium text-brand hover:bg-brand/20">Diskusikan entri ini</a>
            @endif
            <a href="{{ route('dashboard') }}" class="rounded-xl border border-border bg-bg-surface px-4 py-2 text-sm font-medium text-text-primary hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">← Dashboard</a>
        </div>
    </header>
    <nav class="card flex flex-wrap items-center justify-between gap-3 p-3 sm:flex-nowrap" aria-label="Navigasi antrean review">
        @if ($previousId)<a href="{{ route('quick-review.index', ['item' => $previousId]) }}" class="quick-review-navigation inline-flex min-h-10 items-center rounded-xl bg-bg-panel px-4 py-2 text-sm font-medium text-text-primary hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">← Sebelumnya</a>@else<span class="inline-flex min-h-10 items-center rounded-xl bg-bg-panel px-4 py-2 text-sm text-text-secondary opacity-60" aria-disabled="true">← Sebelumnya</span>@endif
        <div class="order-last w-full text-center sm:order-none sm:w-auto" aria-label="Item {{ $queueIndex + 1 }} dari {{ $queueCount }}"><p class="text-sm font-semibold tabular-nums text-text-primary">{{ $queueIndex + 1 }} dari {{ $queueCount }}</p><div class="mt-1.5 h-1.5 w-32 max-w-full overflow-hidden rounded-full bg-bg-hover" role="progressbar" aria-label="Posisi antrean" aria-valuemin="0" aria-valuemax="{{ $queueCount }}" aria-valuenow="{{ $queueIndex + 1 }}"><div class="h-full rounded-full bg-brand" style="width: {{ (int) round(($queueIndex + 1) / $queueCount * 100) }}%"></div></div></div>
        @if ($nextId)<a href="{{ route('quick-review.index', ['item' => $nextId]) }}" class="quick-review-navigation inline-flex min-h-10 items-center rounded-xl border border-brand/50 bg-brand/10 px-4 py-2 text-sm font-semibold text-brand hover:bg-brand/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Berikutnya →</a>@else<span class="inline-flex min-h-10 items-center rounded-xl bg-bg-panel px-4 py-2 text-sm text-text-secondary opacity-60" aria-disabled="true">Berikutnya →</span>@endif
    </nav>
    <div class="detail-workspace-grid">
        <div class="min-w-0 space-y-4">
            @include('logbook.partials.review.summary', ['logbook' => $entry])
            @include('logbook.partials.review.thread', ['logbook' => $entry])
            @include('logbook.partials.review.feedback', ['logbook' => $entry])
            @include('logbook.partials.review.notes', ['logbook' => $entry])
            @include('logbook.partials.review.context', ['logbook' => $entry, 'useFeedbackButtons' => true, 'lastFeedback' => $lastFeedback])
        </div>
        <aside class="detail-workspace-panel space-y-4" aria-label="Dokumen dan tindakan">
            @include('logbook.partials.review.mini', ['logbook' => $entry])
            @include('logbook.partials.review.documents', ['logbook' => $entry, 'viewerParams' => ['quick_review' => 1]])
            @include('logbook.partials.review.actions', ['logbook' => $entry])
            @include('logbook.partials.review.decision', ['logbook' => $entry,
                'approveUrl' => route('quick-review.approve-next', $entry),
                'revisiUrl' => route('quick-review.revisi-next', $entry),
                'archiveUrl' => route('quick-review.archive-next', $entry),
                'templates' => $templates,
                'buildUrl' => route('quick-review.build-feedback', $entry),
                'lastFeedback' => $lastFeedback,
                'useButtons' => false,
                'submitLabel' => $nextId ? 'Simpan & Berikutnya' : ($queueCount > 1 ? 'Simpan & Lihat Antrean' : 'Simpan & Selesai'),
                'detailUrl' => route('logbook.show', $entry),
                'pdfOpened' => $entry->review_opened_at || (!$entry->lampiran_path && !$entry->catatan_perbaikan_path) ? '1' : '0',
                'feedbackDraft' => $feedbackDraft ?? null])
        </aside>
    </div>
    @if ($nextEntry)
        <section class="card p-5" aria-label="Berikutnya dalam antrean">
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-text-secondary">Berikutnya dalam antrean</p>
                    <p class="mt-1 font-heading font-semibold text-text-primary">{{ $nextEntry->mahasiswaTa?->mahasiswa?->name ?? 'Mahasiswa' }} · {{ $nextEntry->jenis === 'revisi' ? 'Revisi' : 'Logbook sesi '.$nextEntry->sesi_ke }}</p>
                    <p class="text-xs text-text-secondary mt-0.5">{{ $nextEntry->topik ?: 'Tanpa topik' }}</p>
                </div>
                @include('partials.status-badge', ['status' => $nextEntry->status])
                <a href="{{ route('quick-review.index', ['item' => $nextEntry->id]) }}" class="btn-primary inline-flex px-4 py-2 text-sm font-semibold">Buka →</a>
            </div>
        </section>
    @elseif ($queueCount > 1)
        <section class="card p-5 text-sm text-text-secondary" aria-label="Akhir antrean">
            Item terakhir dalam antrean — setelah disimpan, kembali ke awal antrean.
        </section>
    @endif
@endif
</div>
@endsection
@section('scripts')
@if ($entry)
    @include('logbook.partials.review.actions-script', ['logbook' => $entry])
    @include('logbook.partials.review.decision-script')
@endif
@endsection
