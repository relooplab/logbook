@extends('layouts.app')

@section('title', 'Entri Revisi')

@section('content')
@php
    $inst = \App\Models\Institution::current();
    $maxMb = $inst->maxUploadSizeMb();
    $accept = $inst->fileAccept();
    $allowedTypes = $inst->allowedFileTypes();
    $typesLabel = strtoupper(implode(', ', $allowedTypes));
    $fileHint = $typesLabel . ' • Maks. ' . $maxMb . ' MB';
    $statusOptions = \App\Models\LogbookEntry::PERBAIKAN_STATUSES;
    $oldRiwayat = old("riwayat_perbaikan", []);
    $initialRows = $oldRiwayat;
    if (!$initialRows && $parentComments->isNotEmpty()) {
        $initialRows = $parentComments->map(fn ($c) => [
            'halaman' => 'Hal. '.$c->page_number,
            'komentar_dosen' => $c->comment,
            'perbaikan' => $c->reply,
            'status' => $statusOptions[0] ?? null,
        ])->toArray();
    }

    // Langkah yang perlu dibuka ulang saat validasi gagal (kesalahan tetap terlihat).
    $riwayatErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => str_starts_with($key, 'riwayat_perbaikan'))
        ->flatten();
    $errorStep = 1;
    if ($riwayatErrors->isNotEmpty()) {
        $errorStep = 2;
    }
    if ($errors->has('lampiran') || $errors->has('tanggal_pengiriman') || $errors->has('progres_kendala')) {
        $errorStep = 3;
    }

    $steps = [
        1 => ['Pilih Umpan Balik', 'forum', 'Pilih umpan balik yang dijawab'],
        2 => ['Isi Perbaikan', 'build', 'Isi kartu perbaikan'],
        3 => ['Upload File', 'upload_file', 'Unggah file revisi'],
        4 => ['Review & Kirim', 'send', 'Periksa ringkasan dan kirim'],
    ];
    // Baris kartu perbaikan: hasil old()/prefill komentar, atau satu kartu kosong.
    $cardRows = array_values($initialRows ?: [[]]);
@endphp
<div class="form-workspace">
    <x-page-header title="Entri Revisi" description="Kirim revisi dan dokumentasikan perbaikan Anda" class="mb-5">
        <x-slot:actions>
            <a href="{{ route('logbook.index', array_filter(['program' => $ta?->jenis])) }}"
                class="btn-ghost inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_back</span> Kembali
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($ta)
        <p class="mb-4 -mt-3 text-sm text-text-secondary">Program:
            <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-brand/10 text-brand">{{ $ta->jenisLabel() }}</span>
        </p>
    @endif

    <div class="mb-5">
        <ol class="stepper" aria-label="Langkah pengisian entri revisi">
            @foreach ($steps as $num => [$label, $icon, $desc])
                <li class="stepper-item">
                    <button type="button" class="stepper-button wizard-step" data-step="{{ $num }}"
                        data-state="{{ $num === 1 ? 'active' : 'upcoming' }}"
                        @if ($num === 1) aria-current="step" @endif>
                        <span class="stepper-marker" aria-hidden="true">
                            <span class="stepper-number">{{ $num }}</span>
                            <span class="stepper-check material-symbols-outlined icon-sm">check</span>
                        </span>
                        <span class="stepper-text">
                            <span class="stepper-label">{{ $num }}. {{ $label }}</span>
                            <span class="stepper-desc">{{ $desc }}</span>
                        </span>
                        <span class="sr-only" data-step-status="{{ $num }}"></span>
                    </button>
                    @if ($num < count($steps))
                        <span class="stepper-line" data-stepper-line="{{ $num }}" aria-hidden="true"></span>
                    @endif
                </li>
            @endforeach
        </ol>
        <p class="sr-only" aria-live="polite" data-step-announcement></p>
    </div>

    <form method="POST" action="{{ route('logbook.store-revisi') }}" enctype="multipart/form-data" id="revisi-form"
        class="form-workspace-grid">
        @csrf

        <input type="hidden" name="program" value="{{ $ta?->jenis }}">

        <div class="form-workspace-column">
            {{-- ===== STEP 1: Pilih Umpan Balik ===== --}}
            <section class="card form-workspace-card wizard-panel p-6" data-panel="1">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">forum</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">1. Pilih Umpan Balik yang Dijawab</h2>
                        <p class="text-caption text-text-secondary">Tentukan entri umpan balik dan penerima revisi.</p>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-field-label" for="parent_entry_id">Umpan Balik yang dijawab (opsional)</label>
                    <div class="form-field-body">
                        <select name="parent_entry_id" id="parent_entry_id" class="form-control">
                            <option value="">Tidak ada — revisi mandiri</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}" data-dosen-id="{{ $parent->dosen_id }}"
                                    @selected(old('parent_entry_id', $selectedParentId) == $parent->id)>
                                    Entri #{{ $parent->id }} · {{ $parent->revision_round ? "Revisi ke-{$parent->revision_round}" : 'Logbook' }} · {{ $parent->reviewed_at?->format('d M Y') }}
                                </option>
                            @endforeach
                        </select>
                        <p class="form-field-hint">Kosongkan jika ingin membuat revisi tanpa menghubungkan ke entri logbook yang ada.</p>
                        @error('parent_entry_id')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Penerima perbaikan: pembimbing ATAU dosen penguji. --}}
                <div class="form-field">
                    <label class="form-field-label" for="addressed_dosen_id">Kirim kepada (penerima perbaikan)</label>
                    <div class="form-field-body">
                        <select name="addressed_dosen_id" id="addressed_dosen_id" required class="form-control">
                            @foreach ($dosenOptions as $dosenId => $label)
                                <option value="{{ $dosenId }}" @selected((string) old('addressed_dosen_id', $defaultRecipientId) === (string) $dosenId)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="form-field-hint">Revisi dapat ditujukan ke dosen pembimbing atau dosen penguji. Penerima akan menerima notifikasi dan dapat meninjau entri ini.</p>
                        @error('addressed_dosen_id')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Feedback & komentar parent --}}
                @foreach ($parents as $parent)
                    @php $openComments = $parent->comments->where('resolution_status', '!=', \App\Models\PdfComment::STATUS_RESOLVED); @endphp
                    <div data-parent-feedback="{{ $parent->id }}"
                        data-comments='@json($openComments->map(fn ($c) => ['page_number' => $c->page_number, 'comment' => $c->comment, 'reply' => $c->reply])->values())'
                        class="mt-4 hidden space-y-2 rounded-xl border border-border bg-bg-panel p-3">
                        <p class="text-xs font-semibold text-text-secondary">Umpan Balik entri #{{ $parent->id }}</p>
                        <p class="text-sm whitespace-pre-wrap">{{ $parent->feedback_dosen ?: "Tidak ada feedback teks." }}</p>
                        @if ($openComments->isNotEmpty())
                            <p class="pt-1 text-xs font-semibold text-text-secondary">Komentar PDF yang belum diselesaikan:</p>
                            @foreach ($openComments as $comment)
                                <label class="flex items-start gap-2 text-xs">
                                    <input type="checkbox" name="addressed_comment_ids[]" value="{{ $comment->id }}"
                                        @checked(in_array($comment->id, old("addressed_comment_ids", []))) class="mt-0.5">
                                    <span>Hal. {{ $comment->page_number ?: '—' }}: {{ $comment->comment }}</span>
                                </label>
                            @endforeach
                        @endif
                    </div>
                @endforeach

                <div class="form-actions">
                    <div class="form-actions-end">
                        <button type="button" class="wizard-next btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                            Lanjut
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- ===== STEP 2: Isi Perbaikan ===== --}}
            <section class="card form-workspace-card wizard-panel hidden p-6" data-panel="2">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">build</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">2. Isi Perbaikan</h2>
                        <p class="text-caption text-text-secondary">Isi kartu perbaikan sesuai komentar dosen. PDF catatan perbaikan dibuat otomatis oleh sistem.</p>
                    </div>
                </div>

                @if ($riwayatErrors->isNotEmpty())
                    <div class="mt-4 rounded-xl border border-status-danger/40 bg-status-danger/10 p-3" role="alert">
                        <p class="text-sm font-medium text-status-danger">Sebagian kartu perbaikan belum lengkap.</p>
                        <ul class="mt-1 list-disc pl-5 text-xs text-status-danger">
                            @foreach ($riwayatErrors as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div id="kartu-perbaikan" class="mt-4 space-y-4">
                    @foreach ($cardRows as $i => $row)
                        <div class="perbaikan-card" data-collapsed="0">
                            <div class="perbaikan-head">
                                <span class="icon-chip h-9 w-9" aria-hidden="true">
                                    <span class="material-symbols-outlined icon-md text-brand">build</span>
                                </span>
                                <div class="perbaikan-head-text">
                                    <span class="perbaikan-number" data-card-number>Perbaikan #{{ $i + 1 }}</span>
                                    <span class="perbaikan-summary" data-card-summary></span>
                                </div>
                                <button type="button" class="perbaikan-toggle" data-card-toggle aria-expanded="true">
                                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">expand_more</span>
                                    <span class="sr-only">Buka atau tutup kartu perbaikan #{{ $i + 1 }}</span>
                                </button>
                                <button type="button"
                                    class="hapus-kartu inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-status-danger hover:bg-status-danger/10">
                                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">delete</span> Hapus
                                </button>
                            </div>
                            <div class="perbaikan-body">
                                @include('logbook.partials.perbaikan-fields', ['i' => $i, 'row' => $row, 'statusOptions' => $statusOptions])
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <button type="button" id="tambah-kartu"
                        class="btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">add</span> Tambah Kartu
                    </button>
                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <p id="kartu-hint" class="hidden text-xs text-status-pending">
                            Lengkapi halaman/bagian, komentar dosen, dan perbaikan pada setiap kartu.
                        </p>
                        <button type="button" class="wizard-next btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                            Lanjut
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- ===== STEP 3: Upload File & Pesan ===== --}}
            <section class="card form-workspace-card wizard-panel hidden p-6" data-panel="3">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">upload_file</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">3. Upload File &amp; Pesan</h2>
                        <p class="text-caption text-text-secondary">Unggah file revisi, lalu tambahkan konteks bila diperlukan.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">File Revisi</p>
                        <div class="mt-3">
                            <div class="form-field">
                                <label class="form-field-label" for="tanggal_pengiriman">Tanggal Pengiriman Revisi</label>
                                <div class="form-field-body">
                                    <input type="date" name="tanggal_pengiriman" id="tanggal_pengiriman" required
                                        value="{{ old('tanggal_pengiriman', now()->format('Y-m-d')) }}" class="form-control">
                                    @error('tanggal_pengiriman')
                                        <p class="form-field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                            <div class="form-field form-field--stack">
                                <label class="form-field-label" for="lampiran">File Perbaikan/Draft</label>
                                <div class="form-field-body">
                                    <x-file-upload input="lampiran" title="Upload File Revisi" :hint="$fileHint"
                                        :accept="$accept" :max-mb="$maxMb" :types="$allowedTypes" :required="true" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-border pt-5">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Pesan untuk Dosen</p>
                        <div class="mt-3">
                            <div class="form-field form-field--stack">
                                <label class="form-field-label" for="progres_kendala">
                                    Pesan untuk Dosen <span class="font-normal">(opsional)</span>
                                </label>
                                <div class="form-field-body">
                                    <textarea name="progres_kendala" id="progres_kendala" rows="4" maxlength="500"
                                        placeholder="Tambahkan konteks untuk dosen, misalnya kendala, alasan, atau pertanyaan tertentu..."
                                        class="form-control">{{ old('progres_kendala') }}</textarea>
                                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs text-text-secondary">Gunakan untuk konteks yang tidak tertampung di kartu perbaikan.</p>
                                        <span class="text-xs text-text-secondary" id="pesan-counter">0/500</span>
                                    </div>
                                    @error('progres_kendala')
                                        <p class="form-field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <p id="upload-hint" class="hidden text-xs text-status-danger" role="alert">
                            Unggah file perbaikan terlebih dahulu sebelum lanjut ke Review.
                        </p>
                        <button type="button" id="lanjut-upload"
                            class="wizard-next btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                            Lanjut
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- ===== STEP 4: Review & Kirim ===== --}}
            <section class="card form-workspace-card wizard-panel hidden p-6" data-panel="4">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">fact_check</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">4. Review Ringkasan</h2>
                        <p class="text-caption text-text-secondary">Periksa ringkasan berikut sebelum revisi dikirim.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-4">
                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Tujuan Revisi</p>
                        <div class="mt-2 space-y-3 text-sm">
                            <div>
                                <p class="text-xs text-text-secondary">Umpan balik yang dijawab</p>
                                <p class="text-text-primary" id="review-parent">—</p>
                            </div>
                            <div>
                                <p class="text-xs text-text-secondary">Dikirim kepada</p>
                                <p class="text-text-primary" id="review-penerima">—</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Ringkasan Perbaikan</p>
                            <span class="text-xs text-text-secondary" id="review-jumlah">0 kartu</span>
                        </div>
                        <ul class="mt-2 space-y-2" id="review-kartu-list"></ul>
                        <p class="mt-2 text-sm text-text-secondary" id="review-kartu-empty">Belum ada kartu perbaikan.</p>
                    </div>

                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">File Revisi</p>
                        <div class="mt-2 space-y-3 text-sm">
                            <div>
                                <p class="text-xs text-text-secondary">File perbaikan</p>
                                <p class="break-all text-text-primary" id="review-file">Belum dipilih</p>
                            </div>
                            <div>
                                <p class="text-xs text-text-secondary">Tanggal pengiriman</p>
                                <p class="text-text-primary" id="review-tanggal">—</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Pesan Dosen</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-text-primary" id="review-pesan">Tidak ada pesan tambahan</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <button type="submit" class="btn-secondary px-4 py-2 text-sm font-medium">Simpan Draft</button>
                        <button type="submit" name="submit" value="1" id="btn-kirim"
                            class="btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                            <span data-kirim-label>Kirim ke Dosen</span>
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">send</span>
                        </button>
                    </div>
                </div>
            </section>
        </div>

        {{-- Panel konteks: tetap tampil di semua langkah. --}}
        <aside class="form-workspace-panel" aria-label="Ringkasan revisi">
            <section class="card form-workspace-card p-5">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">summarize</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">Ringkasan Revisi</h2>
                        <p class="text-caption text-text-secondary">Ringkasan informasi revisi Anda.</p>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs text-text-secondary">Kemajuan</span>
                        <span class="text-xs font-medium text-text-primary" data-progress-label>0 dari 4 langkah</span>
                    </div>
                    <div class="progress-segments mt-2" aria-hidden="true">
                        @foreach ($steps as $num => [$label, $icon, $desc])
                            <span class="progress-segment" data-progress-segment="{{ $num }}"></span>
                        @endforeach
                    </div>
                </div>

                <div class="mt-4">
                    <div class="summary-row">
                        <span class="summary-key">
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">forum</span> Umpan Balik
                        </span>
                        <span class="summary-value" data-revisi-parent>Tidak ada — revisi mandiri</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-key">
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">send</span> Penerima
                        </span>
                        <span class="summary-value" data-revisi-penerima>—</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-key">
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">build</span> Jumlah Kartu
                        </span>
                        <span class="summary-value" data-revisi-kartu>0 kartu</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-key">
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">attach_file</span> File Revisi
                        </span>
                        <span class="summary-value" data-revisi-file>Belum diunggah</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-key">
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">save</span> Status Draft
                        </span>
                        <span class="summary-value">
                            <span class="badge badge-neutral" data-autosave-mini="lb-revisi">Belum disimpan</span>
                        </span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-key">
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">event</span> Tanggal Revisi
                        </span>
                        <span class="summary-value" data-revisi-tanggal>—</span>
                    </div>
                </div>
            </section>

            <x-autosave-status panel="lb-revisi" />

        </aside>
    </form>
</div>
@endsection

@section('scripts')
@include('partials.form-workspace-script')
<script>
    // ===== Referensi elemen (id & nama field dipertahankan) =====
    var form = document.getElementById('revisi-form');
    var currentStep = 1;
    var totalSteps = 4;
    var stepButtons = document.querySelectorAll('.wizard-step');
    var panels = document.querySelectorAll('.wizard-panel');
    var fileInputEl = document.getElementById('lampiran');
    var uploadHintEl = document.getElementById('upload-hint');
    var tanggalEl = document.getElementById('tanggal_pengiriman');
    var pesanInput = document.getElementById('progres_kendala');
    var pesanCounter = document.getElementById('pesan-counter');
    var parentSelect = document.getElementById('parent_entry_id');
    var recipientSelect = document.getElementById('addressed_dosen_id');
    var kartuContainer = document.getElementById('kartu-perbaikan');
    var tambahBtn = document.getElementById('tambah-kartu');
    var statusOptions = @json($statusOptions);
    var defaultRecipientValue = '{{ $defaultRecipientId }}';
    var initialStep = {{ $errorStep }};
    var cardSeq = 0;

    function hasFile() {
        return !!(fileInputEl && fileInputEl.files && fileInputEl.files.length > 0);
    }

    function fileValid() {
        return window.LbUpload ? window.LbUpload.isValid('lampiran') : hasFile();
    }

    function escapeHtml(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    // Warna chip status mengikuti urutan LogbookEntry::PERBAIKAN_STATUSES
    // (Sudah = selesai, Sebagian = sebagian, sisanya netral).
    function statusBadgeClass(status) {
        var idx = statusOptions.indexOf(status);
        if (idx === 0) return 'badge-success';
        if (idx === 1) return 'badge-pending';
        return 'badge-neutral';
    }

    function formatDate(value) {
        if (!value) return '—';
        var d = new Date(value + 'T00:00:00');
        if (isNaN(d.getTime())) return value;
        try {
            return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
        } catch (e) { return value; }
    }

    // ===== Kartu perbaikan: baca nilai form yang sama (tanpa state duplikat) =====
    function cardField(card, field) {
        var el = card.querySelector('[name^="riwayat_perbaikan"][name$="[' + field + ']"]');
        return el ? el.value : '';
    }

    function cardData(card) {
        return {
            halaman: cardField(card, 'halaman'),
            status: cardField(card, 'status'),
            komentar_dosen: cardField(card, 'komentar_dosen'),
            perbaikan: cardField(card, 'perbaikan')
        };
    }

    function cardComplete(card) {
        var d = cardData(card);
        return !!(d.halaman.trim() && d.komentar_dosen.trim() && d.perbaikan.trim());
    }

    function cards() {
        return kartuContainer ? Array.prototype.slice.call(kartuContainer.querySelectorAll('.perbaikan-card')) : [];
    }

    function allCardsComplete() {
        var list = cards();
        return list.length > 0 && list.every(cardComplete);
    }

    function updateCardSummary(card) {
        var summaryEl = card.querySelector('[data-card-summary]');
        if (!summaryEl) return;
        var d = cardData(card);
        var parts = [d.halaman.trim() || 'Halaman belum diisi'];
        parts.push(d.status ? 'Status: ' + d.status : 'Status belum dipilih');
        summaryEl.textContent = parts.join(' • ');
    }

    // ===== Stepper: selesai / aktif / berikutnya =====
    function stepComplete(n) {
        if (n === 1) return !!(recipientSelect && recipientSelect.value);
        if (n === 2) return allCardsComplete();
        if (n === 3) return fileValid() && !!(tanggalEl && tanggalEl.value);
        return false;
    }

    function updateProgress() {
        var done = 0;
        for (var n = 1; n <= totalSteps; n++) {
            if (n !== currentStep && stepComplete(n)) done++;
        }
        var label = document.querySelector('[data-progress-label]');
        if (label) label.textContent = done + ' dari ' + totalSteps + ' langkah';
        document.querySelectorAll('[data-progress-segment]').forEach(function (seg) {
            var n = parseInt(seg.dataset.progressSegment);
            seg.dataset.state = (n === currentStep) ? 'active' : (stepComplete(n) ? 'completed' : 'upcoming');
        });
        var hint = document.getElementById('kartu-hint');
        if (hint) hint.classList.toggle('hidden', allCardsComplete());
    }

    function renderSteps() {
        panels.forEach(function (p) { p.classList.toggle('hidden', parseInt(p.dataset.panel) !== currentStep); });
        stepButtons.forEach(function (b) {
            var n = parseInt(b.dataset.step);
            var state = (n === currentStep) ? 'active' : (stepComplete(n) ? 'completed' : 'upcoming');
            b.dataset.state = state;
            if (state === 'active') b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
        });
        document.querySelectorAll('[data-stepper-line]').forEach(function (line) {
            var n = parseInt(line.dataset.stepperLine);
            line.dataset.state = stepComplete(n) ? 'completed' : 'upcoming';
        });
        var announcement = document.querySelector('[data-step-announcement]');
        if (announcement && announcement.dataset.last !== String(currentStep)) {
            var activeLabel = document.querySelector('.wizard-step[data-step="' + currentStep + '"] .stepper-label');
            announcement.textContent = 'Langkah ' + currentStep + ' dari ' + totalSteps + ': ' + (activeLabel ? activeLabel.textContent.trim() : '');
            announcement.dataset.last = String(currentStep);
        }
        // Status tiap langkah untuk pembaca layar (bukan hanya warna/ikon).
        document.querySelectorAll('[data-step-status]').forEach(function (el) {
            var n = parseInt(el.dataset.stepStatus);
            el.textContent = (n === currentStep) ? '(sedang dibuka)'
                : (stepComplete(n) ? '(selesai)' : '(belum dikerjakan)');
        });
        updateProgress();
        updateReview();
        syncSummaryPanel();
    }

    function showStep(n, options) {
        options = options || {};
        var focusUpload = false;
        if (n === 4 && !fileValid()) {
            n = 3;
            focusUpload = true;
            if (uploadHintEl) {
                uploadHintEl.classList.remove('hidden');
                setTimeout(function () { uploadHintEl.classList.add('hidden'); }, 4000);
            }
        }
        currentStep = Math.max(1, Math.min(totalSteps, n));
        renderSteps();
        if (focusUpload && fileInputEl) fileInputEl.focus();
        if (!options.silent) window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    document.querySelectorAll('.wizard-next').forEach(function (btn) {
        btn.addEventListener('click', function () { showStep(currentStep + 1); });
    });
    document.querySelectorAll('.wizard-prev').forEach(function (btn) {
        btn.addEventListener('click', function () { showStep(currentStep - 1); });
    });
    stepButtons.forEach(function (btn) {
        btn.addEventListener('click', function () { showStep(parseInt(btn.dataset.step)); });
    });

    // ===== Kartu perbaikan dinamis =====
    function reindex() {
        Array.prototype.forEach.call(kartuContainer.querySelectorAll('.perbaikan-card'), function (card, i) {
            card.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.name = el.name.replace(/riwayat_perbaikan\[\d+\]/, 'riwayat_perbaikan[' + i + ']');
            });
            var numberEl = card.querySelector('[data-card-number]');
            if (numberEl) numberEl.textContent = 'Perbaikan #' + (i + 1);
            updateCardSummary(card);
        });
    }

    function cardMarkup(data, seq) {
        var defaultStatus = statusOptions.length ? statusOptions[0] : '';
        var options = statusOptions.map(function (s) {
            var selected = (data.status === s) || (!data.status && s === defaultStatus);
            return '<option value="' + escapeAttr(s) + '"' + (selected ? ' selected' : '') + '>' + escapeHtml(s) + '</option>';
        }).join('');

        return '<div class="perbaikan-head">' +
                '<span class="icon-chip h-9 w-9" aria-hidden="true"><span class="material-symbols-outlined icon-md text-brand">build</span></span>' +
                '<div class="perbaikan-head-text">' +
                    '<span class="perbaikan-number" data-card-number>Perbaikan #</span>' +
                    '<span class="perbaikan-summary" data-card-summary></span>' +
                '</div>' +
                '<button type="button" class="perbaikan-toggle" data-card-toggle aria-expanded="true">' +
                    '<span class="material-symbols-outlined icon-sm" aria-hidden="true">expand_more</span>' +
                    '<span class="sr-only">Buka atau tutup kartu perbaikan</span>' +
                '</button>' +
                '<button type="button" class="hapus-kartu inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-status-danger hover:bg-status-danger/10">' +
                    '<span class="material-symbols-outlined icon-sm" aria-hidden="true">delete</span> Hapus' +
                '</button>' +
            '</div>' +
            '<div class="perbaikan-body">' +
                '<div class="grid gap-3 sm:grid-cols-2">' +
                    '<div><label class="mb-1 block text-xs text-text-secondary" for="riwayat-halaman-' + seq + '">Halaman/Bagian</label>' +
                    '<input type="text" id="riwayat-halaman-' + seq + '" name="riwayat_perbaikan[0][halaman]" value="' + escapeAttr(data.halaman || '') + '" placeholder="mis. Hal. 5, Bab 3" class="form-control"></div>' +
                    '<div><label class="mb-1 block text-xs text-text-secondary" for="riwayat-status-' + seq + '">Status</label>' +
                    '<select id="riwayat-status-' + seq + '" name="riwayat_perbaikan[0][status]" class="form-control">' + options + '</select></div>' +
                '</div>' +
                '<div><label class="mb-1 block text-xs text-text-secondary" for="riwayat-komentar-' + seq + '">Komentar Dosen</label>' +
                '<input type="text" id="riwayat-komentar-' + seq + '" name="riwayat_perbaikan[0][komentar_dosen]" value="' + escapeAttr(data.komentar_dosen || '') + '" placeholder="Komentar yang diperbaiki" class="form-control"></div>' +
                '<div><label class="mb-1 block text-xs text-text-secondary" for="riwayat-perbaikan-' + seq + '">Perbaikan yang Dilakukan</label>' +
                '<textarea id="riwayat-perbaikan-' + seq + '" name="riwayat_perbaikan[0][perbaikan]" rows="3" placeholder="Jelaskan perbaikan yang Anda lakukan" class="form-control">' + escapeHtml(data.perbaikan || '') + '</textarea></div>' +
            '</div>';
    }

    function addKartu(data) {
        data = data || {};
        var card = document.createElement('div');
        card.className = 'perbaikan-card';
        card.dataset.collapsed = '0';
        card.innerHTML = cardMarkup(data, ++cardSeq);
        kartuContainer.appendChild(card);
        reindex();
        var firstInput = card.querySelector('input');
        if (firstInput) firstInput.focus();
        renderSteps();
    }

    if (tambahBtn) {
        tambahBtn.addEventListener('click', function () { addKartu(); });
    }

    if (kartuContainer) {
        kartuContainer.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-card-toggle]');
            if (toggle) {
                var card = toggle.closest('.perbaikan-card');
                var collapsed = card.dataset.collapsed === '1';
                card.dataset.collapsed = collapsed ? '0' : '1';
                toggle.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
                return;
            }
            var hapus = event.target.closest('.hapus-kartu');
            if (hapus && cards().length > 1) {
                hapus.closest('.perbaikan-card').remove();
                reindex();
                renderSteps();
            }
        });

        kartuContainer.addEventListener('input', function (event) {
            var card = event.target.closest('.perbaikan-card');
            if (!card) return;
            updateCardSummary(card);
            updateProgress();
        });

        kartuContainer.addEventListener('change', function (event) {
            var card = event.target.closest('.perbaikan-card');
            if (!card) return;
            updateCardSummary(card);
            updateProgress();
        });
    }

    // ===== Upload wajib sebelum Review & Kirim =====
    function updateUploadGate() {
        var ok = fileValid();
        var lanjut = document.getElementById('lanjut-upload');
        if (lanjut) lanjut.classList.toggle('opacity-60', !ok);
        var kirim = document.getElementById('btn-kirim');
        if (kirim) {
            kirim.disabled = !ok;
            kirim.classList.toggle('opacity-60', !ok);
        }
    }

    if (fileInputEl) {
        fileInputEl.addEventListener('change', function () {
            updateUploadGate();
            if (uploadHintEl) uploadHintEl.classList.add('hidden');
            updateProgress();
            updateReview();
            syncSummaryPanel();
        });
    }

    // ===== Counter pesan =====
    function updateCounter() {
        if (!pesanInput || !pesanCounter) return;
        pesanCounter.textContent = pesanInput.value.length + '/500';
    }
    if (pesanInput) pesanInput.addEventListener('input', updateCounter);

    // ===== Feedback parent toggle =====
    var parentCards = document.querySelectorAll('[data-parent-feedback]');

    function fillCardsFromComments(comments) {
        if (!kartuContainer) return;
        kartuContainer.innerHTML = '';
        comments = comments || [];
        if (!comments.length) {
            addKartu();
            return;
        }
        comments.forEach(function (c) {
            addKartu({
                halaman: c.page_number ? 'Hal. ' + c.page_number : '',
                komentar_dosen: c.comment || '',
                perbaikan: c.reply || '',
                status: statusOptions.length ? statusOptions[0] : ''
            });
        });
    }

    var lastSyncedParent = parentSelect ? parentSelect.value : '';

    function syncParentFeedback(fill) {
        if (!parentSelect) return;
        parentCards.forEach(function (card) {
            var active = card.dataset.parentFeedback === parentSelect.value;
            card.classList.toggle('hidden', !active);
            card.querySelectorAll('input[name="addressed_comment_ids[]"]').forEach(function (input) {
                input.disabled = !active;
            });
        });
        if (fill && parentSelect.value !== lastSyncedParent) {
            var activeCard = null;
            parentCards.forEach(function (card) {
                if (card.dataset.parentFeedback === parentSelect.value) activeCard = card;
            });
            if (activeCard) {
                try {
                    fillCardsFromComments(JSON.parse(activeCard.dataset.comments || '[]'));
                } catch (e) {}
            }
            lastSyncedParent = parentSelect.value;
        }
    }

    if (parentSelect) {
        parentSelect.addEventListener('change', function () { syncParentFeedback(true); });
        syncParentFeedback(false);
        lastSyncedParent = parentSelect.value;
    }

    // ===== Penerima perbaikan (pembimbing / dosen penguji) =====
    if (parentSelect && recipientSelect) {
        // Saat entri induk berubah, default penerima mengikuti reviewer entri tsb.
        parentSelect.addEventListener('change', function () {
            var opt = parentSelect.selectedOptions[0];
            var dosenId = opt ? (opt.dataset.dosenId || '') : '';
            if (dosenId && recipientSelect.querySelector('option[value="' + dosenId + '"]')) {
                recipientSelect.value = dosenId;
            }
        });
    }
    if (recipientSelect) recipientSelect.addEventListener('change', function () { renderSteps(); });

    // ===== Ringkasan revisi (panel konteks) =====
    function setText(selector, value) {
        var el = document.querySelector(selector);
        if (el) el.textContent = value;
    }

    function parentLabel() {
        if (parentSelect && parentSelect.value && parentSelect.selectedOptions[0]) {
            return parentSelect.selectedOptions[0].textContent.trim();
        }
        return 'Tidak ada — revisi mandiri';
    }

    function recipientLabel() {
        if (recipientSelect && recipientSelect.value && recipientSelect.selectedOptions[0]) {
            return recipientSelect.selectedOptions[0].textContent.trim();
        }
        return '—';
    }

    function fileLabel() {
        if (!hasFile()) return 'Belum diunggah';
        return fileValid() ? fileInputEl.files[0].name : 'File tidak valid: ' + fileInputEl.files[0].name;
    }

    function syncSummaryPanel() {
        setText('[data-revisi-parent]', parentLabel());
        setText('[data-revisi-penerima]', recipientLabel());
        setText('[data-revisi-kartu]', cards().length + ' kartu');
        setText('[data-revisi-file]', fileLabel());
        setText('[data-revisi-tanggal]', formatDate(tanggalEl ? tanggalEl.value : ''));
    }

    // ===== Review ringkasan (step 4) =====
    function reviewCardRow(card, index) {
        var d = cardData(card);
        var halaman = d.halaman.trim() || 'Halaman belum diisi';
        var status = d.status || 'Status belum dipilih';
        return '<li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-bg-surface px-3 py-2">' +
                '<span class="min-w-0 text-sm">' +
                    '<span class="font-medium text-text-primary">Perbaikan #' + (index + 1) + '</span>' +
                    '<span class="block text-xs text-text-secondary">' + escapeHtml(halaman) + '</span>' +
                '</span>' +
                '<span class="badge ' + statusBadgeClass(d.status) + '">' + escapeHtml(status) + '</span>' +
            '</li>';
    }

    function updateReview() {
        setText('#review-parent', parentLabel());
        setText('#review-penerima', recipientLabel());

        var list = cards();
        setText('#review-jumlah', list.length + ' kartu');
        var listEl = document.getElementById('review-kartu-list');
        if (listEl) listEl.innerHTML = list.map(reviewCardRow).join('');
        var emptyEl = document.getElementById('review-kartu-empty');
        if (emptyEl) emptyEl.classList.toggle('hidden', list.length > 0);

        setText('#review-file', hasFile() ? fileLabel() : 'Belum dipilih');
        setText('#review-tanggal', formatDate(tanggalEl ? tanggalEl.value : ''));

        var pesan = pesanInput ? pesanInput.value.trim() : '';
        setText('#review-pesan', pesan || 'Tidak ada pesan tambahan');

        var label = recipientLabel();
        setText('[data-kirim-label]', label !== '—' ? 'Kirim ke ' + label.split(' — ')[0] : 'Kirim ke Dosen');
    }

    // ===== Auto-save draft ke localStorage =====
    (function () {
        var PANEL = 'lb-revisi';
        var KEY = 'lbta-draft-{{ auth()->id() }}-{{ $ta->id ?? 0 }}-revisi';

        function timeLabel(ts) {
            return new Date(ts || Date.now()).toLocaleTimeString();
        }

        function collect() {
            var data = {
                tanggal_pengiriman: tanggalEl ? tanggalEl.value : '',
                progres_kendala: pesanInput ? pesanInput.value : '',
                parent_entry_id: parentSelect ? parentSelect.value : '',
                addressed_dosen_id: recipientSelect ? recipientSelect.value : '',
                riwayat: []
            };
            cards().forEach(function (card) {
                var row = {};
                card.querySelectorAll('input, select, textarea').forEach(function (el) {
                    var m = el.name.match(/riwayat_perbaikan\[\d+\]\[(\w+)\]/);
                    if (m) row[m[1]] = el.value;
                });
                data.riwayat.push(row);
            });
            return data;
        }

        function save() {
            try {
                localStorage.setItem(KEY, JSON.stringify(collect()));
            } catch (e) {
                window.LbAutosave.set(PANEL, 'error');
                return;
            }
            window.LbAutosave.set(PANEL, 'saved', { time: 'Terakhir disimpan ' + timeLabel() });
        }

        function applyDraft(saved) {
            if (saved.tanggal_pengiriman && tanggalEl) tanggalEl.value = saved.tanggal_pengiriman;
            if (saved.parent_entry_id && parentSelect) parentSelect.value = saved.parent_entry_id;
            if (saved.addressed_dosen_id && recipientSelect) recipientSelect.value = saved.addressed_dosen_id;
            if (pesanInput) pesanInput.value = saved.progres_kendala || '';
            if (saved.riwayat && saved.riwayat.length) {
                kartuContainer.innerHTML = '';
                saved.riwayat.forEach(function (r) { addKartu(r); });
            }
            updateCounter();
            updateUploadGate();
            renderSteps();
        }

        function restoreDraft(saved) {
            applyDraft(saved);
            syncParentFeedback(false);
            window.LbAutosave.set(PANEL, 'restored', { time: 'Dipulihkan dari draf ' + timeLabel(saved.ts) });
        }

        function discardDraft() {
            try { localStorage.removeItem(KEY); } catch (e) {}
            if (tanggalEl) tanggalEl.value = '';
            if (parentSelect) parentSelect.value = '';
            if (pesanInput) pesanInput.value = '';
            if (recipientSelect && defaultRecipientValue) recipientSelect.value = defaultRecipientValue;
            kartuContainer.innerHTML = '';
            addKartu();
            updateCounter();
            updateUploadGate();
            renderSteps();
            window.LbAutosave.set(PANEL, 'discarded');
        }

        // Cek draft tersimpan: pesan non-kosong dipulihkan otomatis (perilaku lama),
        // selain itu tombol pulihkan/buang draf ditawarkan.
        try {
            var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (saved && saved.progres_kendala && pesanInput && !pesanInput.value) {
                applyDraft(saved);
                syncParentFeedback(false);
                window.LbAutosave.set(PANEL, 'restored', { time: 'Dipulihkan dari draf ' + timeLabel(saved.ts) });
            } else if (saved) {
                window.LbAutosave.set(PANEL, 'idle', { time: 'Draf tersimpan ' + timeLabel(saved.ts) });
                window.LbAutosave.draftAvailable(PANEL, true);
            }
        } catch (e) {}

        window.LbAutosave.on(PANEL, 'retry', save);
        window.LbAutosave.on(PANEL, 'restore', function () {
            try {
                var stored = JSON.parse(localStorage.getItem(KEY) || 'null');
                if (stored) restoreDraft(stored);
            } catch (e) {}
        });
        window.LbAutosave.on(PANEL, 'discard', discardDraft);

        if (form) {
            form.addEventListener('input', function () {
                window.LbAutosave.set(PANEL, 'pending');
            });
            form.addEventListener('submit', function () {
                try { localStorage.removeItem(KEY); } catch (e) {}
            });
        }

        setInterval(save, 5000);
    })();

    // ===== Inisialisasi =====
    updateCounter();
    updateUploadGate();
    renderSteps();
    if (initialStep > 1) showStep(initialStep, { silent: true });
</script>
@endsection
