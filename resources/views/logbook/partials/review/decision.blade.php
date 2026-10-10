@php
    // Form keputusan review unified (dipakai show & quick-review).
    // $approveUrl, $revisiUrl, $archiveUrl wajib. $templates null → blok template
    // disembunyikan. $buildUrl null → tombol ambil-dari-komentar disembunyikan.
    // $useButtons + $lastFeedback → tombol pakai-ulang feedback terakhir.
    // $submitLabel teks tombol kirim; $detailUrl → tautan "Detail penuh".
    $templates = $templates ?? null;
    $buildUrl = $buildUrl ?? null;
    $lastFeedback = $lastFeedback ?? null;
    $useButtons = $useButtons ?? false;
    $submitLabel = $submitLabel ?? 'Simpan Keputusan';
    $detailUrl = $detailUrl ?? null;
    $pdfOpened = $pdfOpened ?? '0';
    $feedbackDraft = $feedbackDraft ?? null;
    $reviewDecision = old('review_decision', $errors->has('archive_reason') ? 'archive' : ($errors->has('feedback_dosen') ? 'revisi' : ''));
@endphp
<section class="card p-5 space-y-4 detail-workspace-card" aria-labelledby="decision-heading">
    <div>
        <h2 id="decision-heading" class="font-heading font-semibold text-text-primary">Keputusan Review</h2>
        @if (collect($logbook->riwayat_perbaikan ?? [])->isNotEmpty())
            <p class="text-sm text-text-secondary mt-1">{{ $logbook->completePerbaikanRows()->count() }} dari {{ count($logbook->riwayat_perbaikan ?? []) }} jawaban ditandai sudah oleh mahasiswa.</p>
        @endif
    </div>
    <form method="POST" action="{{ $revisiUrl }}" id="review-decision-form" class="space-y-4"
        data-entry-kind="{{ $logbook->jenis }}"
        data-approve-url="{{ $approveUrl }}"
        data-revision-url="{{ $revisiUrl }}"
        data-archive-url="{{ $archiveUrl }}"
        data-pdf-opened="{{ $pdfOpened }}">
        @csrf
        @if ($templates !== null)
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label for="template-select" class="text-sm font-semibold text-text-primary">Template Feedback</label>
                    <button type="button" id="new-tpl" class="text-xs font-semibold text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">+ Simpan sebagai Template</button>
                </div>
                <select id="template-select" class="mt-2 w-full min-w-0 rounded-xl border border-border bg-bg-panel px-3 py-2.5 text-sm text-text-primary focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="">{{ $templates->isEmpty() ? 'Belum ada pesan tersimpan' : 'Pilih template...' }}</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}" data-body="{{ $template->body }}">{{ $template->title ?: \Illuminate\Support\Str::limit($template->body, 50) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="feedback_dosen" class="block text-sm font-semibold text-text-primary">Pesan untuk mahasiswa <span id="feedback-required" class="{{ $reviewDecision === 'revisi' ? '' : 'hidden' }} text-status-danger">(wajib bila minta revisi, min. 20 huruf)</span></label>
            <textarea id="feedback_dosen" name="feedback_dosen" rows="4" maxlength="5000" @if($reviewDecision === 'revisi') minlength="20" required @endif placeholder="Tulis pesan untuk mahasiswa..."
                class="mt-2 w-full min-w-0 rounded-xl border border-border bg-bg-panel px-3 py-2.5 text-sm text-text-primary placeholder:text-text-secondary focus:outline-none focus:ring-2 focus:ring-brand/40" aria-describedby="feedback-hint feedback-error">{{ old('feedback_dosen', $feedbackDraft ?? '') }}</textarea>
            <p id="feedback-hint" class="mt-1 text-xs text-text-secondary">Boleh kosong bila disetujui; wajib min. 20 huruf bila minta revisi.</p>
            @error('feedback_dosen') <p id="feedback-error" class="mt-2 text-xs text-status-danger" role="alert">{{ $message }}</p>
            @else <p id="feedback-error" class="mt-2 hidden text-xs text-status-danger" role="alert">Pesan revisi min. 20 huruf.</p>
            @enderror
            @if ($buildUrl)
                <button type="button" id="build-feedback" data-build-url="{{ $buildUrl }}" class="mt-2 inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-brand/40 bg-brand/10 px-3 py-2 text-sm font-medium text-brand hover:bg-brand/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"><span class="material-symbols-outlined icon-sm" aria-hidden="true">bolt</span>Buat dari Anotasi</button>
            @endif
            @if ($useButtons && $lastFeedback)
                <button type="button" class="use-last-feedback mt-2 ml-2 text-xs font-semibold text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" data-body="{{ $lastFeedback }}">Gunakan feedback terakhir</button>
            @endif
        </div>
        <div id="archive-reason-wrap" class="space-y-2 {{ $reviewDecision === 'archive' ? '' : 'hidden' }}">
            <label for="archive_reason" class="block text-xs font-semibold text-text-secondary">Catatan arsip (opsional)</label>
            <textarea id="archive_reason" name="archive_reason" rows="3" maxlength="5000" placeholder="Tambahkan catatan jika perlu..." class="w-full rounded-xl border border-border bg-bg-panel px-3 py-2.5 text-sm text-text-primary focus:outline-none focus:ring-2 focus:ring-brand/40">{{ old('archive_reason') }}</textarea>
            @error('archive_reason') <p class="text-xs text-status-danger" role="alert">{{ $message }}</p> @enderror
        </div>
        <fieldset class="space-y-2">
            <legend class="mb-2 text-sm font-semibold text-text-primary">Keputusan</legend>
            <label class="decision-choice flex items-start gap-3 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                <input type="radio" name="review_decision" value="approve" class="mt-1 accent-brand" required @checked($reviewDecision === 'approve')><span><strong class="block text-text-primary">Setujui</strong><span class="text-xs text-text-secondary">{{ $logbook->jenis === 'revisi' ? 'Jawaban sudah benar, lanjut.' : 'Sudah benar, lanjut.' }}</span></span>
            </label>
            <label class="decision-choice flex items-start gap-3 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                <input type="radio" name="review_decision" value="revisi" class="mt-1 accent-brand" required @checked($reviewDecision === 'revisi')><span><strong class="block text-text-primary">Minta Revisi</strong><span class="text-xs text-text-secondary">Masih ada yang perlu dibetulkan.</span></span>
            </label>
            <label class="decision-choice flex items-start gap-3 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                <input type="radio" name="review_decision" value="archive" class="mt-1 accent-brand" required @checked($reviewDecision === 'archive')><span><strong class="block text-text-primary">Arsipkan</strong><span class="text-xs text-text-secondary">Simpan saja, tidak dinilai.</span></span>
            </label>
        </fieldset>
        <button type="submit" id="review-decision-submit" class="btn-primary w-full px-4 py-2.5 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand disabled:cursor-not-allowed disabled:opacity-50" disabled>{{ $submitLabel }}</button>
        @if ($detailUrl)
            <a href="{{ $detailUrl }}" class="inline-flex w-full items-center justify-center rounded-xl border border-border bg-bg-panel px-4 py-2.5 text-sm font-medium text-text-primary hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Detail penuh</a>
        @endif
    </form>
    @if ($templates !== null)
        <div id="tpl-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-bg-base/80 p-4" role="dialog" aria-modal="true" aria-labelledby="tpl-modal-title" data-store-url="{{ route('feedback-templates.store') }}"><div class="w-full max-w-md rounded-card border border-border bg-bg-surface p-5 shadow-lg"><h2 id="tpl-modal-title" class="font-heading font-semibold text-text-primary">Simpan Pesan Ini</h2><div class="mt-4 space-y-3"><div><label for="tpl-title" class="block text-sm text-text-primary">Judul (opsional)</label><input id="tpl-title" type="text" maxlength="100" class="mt-1 w-full rounded-xl border border-border bg-bg-panel px-3 py-2 text-sm text-text-primary focus:outline-none focus:ring-2 focus:ring-brand/40"></div><div><label for="tpl-body" class="block text-sm text-text-primary">Isi pesan</label><textarea id="tpl-body" rows="4" class="mt-1 w-full rounded-xl border border-border bg-bg-panel px-3 py-2 text-sm text-text-primary focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea></div><p id="tpl-error" class="hidden text-xs text-status-danger" role="alert"></p></div><div class="mt-4 flex justify-end gap-2"><button type="button" id="tpl-cancel" class="rounded-xl bg-bg-panel px-4 py-2 text-sm text-text-primary hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Batal</button><button type="button" id="tpl-save" class="btn-primary px-4 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Simpan</button></div></div></div>
    @endif
</section>
