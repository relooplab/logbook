@extends('layouts.app')

@section('title', 'Tambah Entri Logbook')

@section('content')
@php
    $inst = \App\Models\Institution::current();
    $maxMb = $inst->maxUploadSizeMb();
    $accept = $inst->fileAccept();
    $allowedTypes = $inst->allowedFileTypes();
    $typesLabel = strtoupper(implode(', ', $allowedTypes));
    $fileHint = $typesLabel . ' • Maks. ' . $maxMb . ' MB';
    $userId = auth()->id();
    $programId = $ta->id ?? 0;
    $hasPendingRevision = ($pendingRevisions ?? collect())->isNotEmpty();
    // Step 3 memakai kartu jawaban seperti revisi (tanpa induk = tulis
    // manual): old() → draf → satu kartu kosong.
    $statusOptions = \App\Models\LogbookEntry::PERBAIKAN_STATUSES;
    $statusOptions3 = array_values(array_filter($statusOptions, fn ($s) => $s !== \App\Models\LogbookEntry::PERBAIKAN_DRAF));
    $oldRiwayat = old('riwayat_perbaikan', []);
    $initialRows = $oldRiwayat ?: ($draft?->riwayat_perbaikan ?: []);
    $cardRows = array_values($initialRows ?: [[]]);
    $commentOptions = [];
    $riwayatErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => str_starts_with($key, 'riwayat_perbaikan'))
        ->flatten();

    // Langkah yang perlu dibuka ulang saat validasi gagal (kesalahan tetap terlihat).
    $errorStep = 1;
    if ($errors->has('lampiran')) {
        $errorStep = 2;
    }
    if ($riwayatErrors->isNotEmpty() || $errors->has('progres_kendala')) {
        $errorStep = 3;
    }
    // Kembali dari viewer (?step=3&autopull=1): buka langsung langkah yang
    // diminta; validasi gagal tetap menang agar kesalahan terlihat.
    $initialStep = $errorStep;
    if (!empty($requestedStep) && !$errors->any()) {
        $initialStep = $requestedStep;
    }

    $steps = [
        1 => ['Info Bimbingan', 'forum', 'Penerima, tanggal & topik'],
        2 => ['Upload File', 'upload_file', 'Unggah file bimbingan'],
        3 => ['Hasil Tandaan', 'build', 'Tandaan jadi ringkasan'],
        4 => ['Review & Kirim', 'send', 'Periksa lalu kirim'],
    ];
@endphp
<div class="form-workspace">
    <x-page-header subtitle="Bimbingan" title="Tambah Entri Logbook"
        description="Catat hasil dan tindak lanjut sesi bimbingan" class="mb-5">
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
            @if (!empty($draft))
                <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-status-pending/10 text-status-pending">Melanjutkan draf #{{ $draft->id }}</span>
            @endif
        </p>
    @endif

    @if (($pendingRevisions ?? collect())->isNotEmpty())
        <div class="mb-5 rounded-xl border border-status-danger/40 bg-status-danger/10 p-4" role="alert">
            <p class="font-semibold text-text-primary">
                @if (($pendingRevisionThreads ?? collect())->count() > 1)
                    {{ $pendingRevisionThreads->count() }} sesi menunggu jawabanmu
                @else
                    {{ $pendingRevisionAction['entry']->shortTitle() ?? 'Revisi' }} menunggu jawabanmu
                @endif
            </p>
            @if (!empty($pendingRevisionAction['url']))
                <a href="{{ $pendingRevisionAction['url'] }}" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-[#0b1420] hover:opacity-90">Jawab Revisi →</a>
            @endif
            @if (($pendingRevisionThreads ?? collect())->count() > 1)
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($pendingRevisionThreads as $thread)
                        <li>
                            <a href="{{ route('logbook.show', $thread['root']) }}" class="text-brand hover:underline">{{ $thread['root']->shortTitle() }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @include('logbook.partials.wizard.stepper', ['steps' => $steps, 'ariaLabel' => 'Langkah pengisian entri logbook'])

    <form method="POST" action="{{ route('logbook.store') }}" enctype="multipart/form-data" id="logbook-form"
        class="form-workspace-grid">
        @csrf

        <input type="hidden" name="program" value="{{ $ta?->jenis }}">
        @if (!empty($draft))
            <input type="hidden" name="draft_id" value="{{ $draft->id }}" data-draft-id>
        @else
            <input type="hidden" name="draft_id" value="" data-draft-id>
        @endif

        <div class="form-workspace-column">
            {{-- ===== STEP 1: Info Bimbingan ===== --}}
            <section class="card form-workspace-card wizard-panel p-6" data-panel="1">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">forum</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">1. Info Bimbingan</h2>
                        <p class="text-caption text-text-secondary">Tentukan penerima, tanggal, dan topik. Sesi {{ $nextSesi }} dibuat otomatis.</p>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-field-label" for="addressed_dosen_id">Kirim kepada (penerima logbook)</label>
                    <div class="form-field-body">
                        <select name="addressed_dosen_id" id="addressed_dosen_id" required class="form-control">
                            @foreach ($dosenOptions as $dosenId => $label)
                                <option value="{{ $dosenId }}" @selected((string) ($defaultRecipient ?? $defaultRecipientId) === (string) $dosenId)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="form-field-hint">Pilih pembimbing atau penguji program. Penerima dapat mereview entri setelah dikirim; pembimbing juga diberi tahu.</p>
                        @error('addressed_dosen_id')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-field-label" for="tanggal_bimbingan">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">event</span> Tanggal Bimbingan
                    </label>
                    <div class="form-field-body">
                        <input type="date" name="tanggal_bimbingan" id="tanggal_bimbingan" required
                            value="{{ $defaultTanggal }}" class="form-control">
                        @error('tanggal_bimbingan')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-field-label" for="topik">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">sell</span> Topik Bimbingan
                    </label>
                    <div class="form-field-body">
                        <input type="text" name="topik" id="topik" required value="{{ $defaultTopik }}"
                            placeholder="Masukkan topik bimbingan..." class="form-control">
                        @if ($lastTopik)
                            <p class="form-field-hint">Topik sebelumnya: {{ $lastTopik }}</p>
                        @endif
                        @error('topik')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @if ($hasPendingRevision)
                    <label class="mt-2 flex cursor-pointer items-start gap-2 rounded-xl border border-border bg-bg-panel p-3 text-sm">
                        <input type="checkbox" name="confirm_new_despite_revision" value="1" class="mt-1" data-revision-confirm @checked(old('confirm_new_despite_revision'))>
                        <span>Ini topik baru, bukan jawaban revisi.</span>
                    </label>
                    @error('confirm_new_despite_revision')
                        <p class="form-field-error mt-1">{{ $message }}</p>
                    @enderror
                @endif

                <div class="form-actions">
                    <div class="form-actions-end">
                        <p id="step1-hint" class="hidden w-full text-right text-xs text-status-danger" role="alert">Lengkapi penerima, tanggal, dan topik untuk melanjutkan.</p>
                        <button type="button" class="wizard-next btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                            Lanjut
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- ===== STEP 2: Upload File (wajib) ===== --}}
            <section class="card form-workspace-card wizard-panel hidden p-6" data-panel="2">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">upload_file</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">2. Upload File</h2>
                        <p class="text-caption text-text-secondary">File wajib diunggah. Lanjut untuk menandai di PDF, atau lewati anotasi.</p>
                    </div>
                </div>

                <div class="form-field form-field--stack">
                    <span class="form-field-label">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">attach_file</span>
                        Lampiran <span class="font-normal">(wajib)</span>
                    </span>
                    <div class="form-field-body">
                        @if (!empty($draftLampiran))
                            <div class="mb-3 flex items-center gap-3 rounded-xl border border-border bg-bg-panel px-3 py-2">
                                <span class="material-symbols-outlined icon-md text-brand" aria-hidden="true">description</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-text-primary">{{ $draftLampiran['name'] }}</p>
                                    <p class="text-xs text-text-secondary">Sudah diunggah — biarkan bila tidak diganti.</p>
                                </div>
                                <a href="{{ $draftLampiran['url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-brand hover:underline">Lihat</a>
                            </div>
                        @endif
                        <x-file-upload input="lampiran" title="Upload Lampiran" :hint="$fileHint"
                            :accept="$accept" :max-mb="$maxMb" :types="$allowedTypes" :required="empty($draftLampiran)" />
                        @error('lampiran')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost min-h-11 px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <p id="upload-hint" class="hidden text-xs text-status-danger" role="alert">
                            Unggah file terlebih dahulu sebelum lanjut.
                        </p>
                        <p id="draft-hint" class="hidden w-full text-right text-xs text-status-danger" role="status"></p>
                        <button type="button" id="lanjut-tanpa-anotasi"
                            class="btn-secondary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium"
                            title="Lewati anotasi PDF, langsung isi kartu jawaban manual">
                            Lanjut tanpa anotasi
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">skip_next</span>
                        </button>
                        <button type="button" id="lanjut-anotasi"
                            class="btn-primary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium disabled:cursor-wait disabled:opacity-60"
                            data-draft-url="{{ route('logbook.store-draft') }}">
                            <span data-lanjut-label>Lanjut ke anotasi PDF</span>
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- ===== STEP 3: Hasil Tandaan ===== --}}
            <section class="card form-workspace-card wizard-panel hidden p-6" data-panel="3">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">build</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">3. Hasil Tandaan</h2>
                        <p class="text-caption text-text-secondary">Tandai di PDF lalu salin otomatis, atau tulis ringkasan manual.</p>
                    </div>
                </div>

                @include('logbook.partials.wizard.annotation-cta', [
                    'openLabel' => 'Buka PDF & tandai',
                    'copyLabel' => 'Salin ke Jawaban',
                    'hint' => 'Draf tersimpan otomatis saat klik Lanjut — PDF terbuka di tab baru.',
                    'copyAttr' => 'data-isi-otomatis',
                    'countAttr' => 'data-isi-count',
                    'msgAttr' => 'data-isi-msg',
                    'guide' => ['Tandai di PDF', '<a href="#kartu-perbaikan" class="text-brand hover:underline">Salin ke bawah ↓</a>', '<a href="#kartu-perbaikan" class="text-brand hover:underline">Lengkapi ↓</a>'],
                ])

                @if ($riwayatErrors->isNotEmpty())
                    <div class="mt-4 rounded-xl border border-status-danger/40 bg-status-danger/10 p-3" role="alert">
                        <p class="text-sm font-medium text-status-danger">Sebagian jawaban belum lengkap.</p>
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
                                    <span class="perbaikan-number" data-card-number>Jawaban #{{ $i + 1 }}</span><span class="perbaikan-needs-badge hidden" data-card-badge>Belum lengkap</span>
                                    <span class="perbaikan-summary" data-card-summary></span>
                                </div>
                                <button type="button" class="perbaikan-toggle" data-card-toggle aria-expanded="true">
                                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">expand_more</span>
                                    <span class="sr-only">Buka atau tutup jawaban #{{ $i + 1 }}</span>
                                </button>
                            </div>
                            <div class="perbaikan-body">
                                @include('logbook.partials.perbaikan-fields', ['i' => $i, 'row' => $row, 'statusOptions' => $statusOptions, 'statusList' => $statusOptions3, 'commentOptions' => $commentOptions])
                                <div class="flex items-center justify-between gap-2 pt-1">
                                    <p class="text-[11px] text-text-secondary">Isi status paling akhir.</p>
                                    <button type="button"
                                        class="hapus-kartu inline-flex min-h-9 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-status-danger hover:bg-status-danger/10">
                                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">delete</span> Hapus jawaban ini
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <button type="button" id="tambah-kartu"
                        class="btn-ghost inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">add</span> Tambah manual
                    </button>
                    <button type="button" class="btn-secondary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium" data-isi-otomatis-bawah disabled>
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">bolt</span> Salin <span data-isi-count-bawah></span>
                    </button>
                </div>
                <p class="mt-2 text-xs text-text-secondary">Alur utama: tandai di PDF → salin → lengkapi. Tulis sendiri bila perlu.</p>

                <div class="mt-4 border-t border-border pt-5">
                    <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Pesan untuk Dosen</p>
                    <div class="mt-3">
                        <div class="form-field form-field--stack">
                            <label class="form-field-label" for="progres_kendala">
                                Pesan untuk Dosen <span class="font-normal">(opsional)</span>
                            </label>
                            <div class="form-field-body">
                                <textarea name="progres_kendala" id="progres_kendala" rows="4" maxlength="500"
                                    placeholder="Tambahkan konteks untuk dosen, misalnya kendala atau pertanyaan tertentu..."
                                    class="form-control">{{ $defaultProgres }}</textarea>
                                <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-text-secondary">Tambahan bila perlu.</p>
                                    <span class="text-xs text-text-secondary" id="pesan-counter">0/500</span>
                                </div>
                                @error('progres_kendala')
                                    <p class="form-field-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <p id="kartu-hint" class="hidden text-xs text-status-pending" role="status">
                            <span data-kartu-count></span> atau tulis sendiri: halaman, komentar dosen, dan perbaikan Anda.
                        </p>
                        <p class="mt-2 text-xs text-text-secondary">Tips: unggah file di langkah 2, tandai di PDF, lalu salin.</p>
                        <button type="button" class="wizard-next btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
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
                        <p class="text-caption text-text-secondary">Periksa ringkasan berikut sebelum dikirim.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-4">
                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Info Bimbingan</p>
                        <div class="mt-2 space-y-3 text-sm">
                            <div>
                                <p class="text-xs text-text-secondary">Sesi</p>
                                <p class="text-text-primary">Sesi {{ $nextSesi }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-text-secondary">Dikirim kepada</p>
                                <p class="text-text-primary" id="review-penerima">—</p>
                            </div>
                            <div>
                                <p class="text-xs text-text-secondary">Tanggal bimbingan</p>
                                <p class="text-text-primary" id="review-tanggal">—</p>
                            </div>
                            <div>
                                <p class="text-xs text-text-secondary">Topik</p>
                                <p class="text-text-primary" id="review-topik">—</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Jawaban</p>
                        <p class="mt-2 text-sm text-text-primary" id="review-jumlah">0 jawaban</p>
                        <ul class="mt-2 space-y-2" id="review-kartu-list"></ul>
                        <p class="mt-2 text-sm text-text-secondary" id="review-kartu-empty">Belum ada jawaban.</p>
                    </div>

                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Pesan untuk Dosen</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-text-primary" id="review-pesan">Tidak ada pesan tambahan</p>
                    </div>

                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">File Lampiran</p>
                        <p class="mt-2 break-all text-sm text-text-primary" id="review-file">Belum dipilih</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost min-h-11 px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <button type="submit" class="btn-secondary min-h-11 px-4 py-2 text-sm font-medium">Simpan Draft</button>
                        <button type="submit" name="submit" value="1" id="btn-kirim"
                            class="btn-primary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium"
                            @if ($hasPendingRevision) data-require-revision-confirm @endif>
                            <span>Kirim ke Dosen</span>
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">send</span>
                        </button>
                    </div>
                </div>
                <p id="kirim-hint" class="mt-2 hidden text-right text-xs text-status-danger" role="status"></p>
                @if ($hasPendingRevision)
                    <p class="mt-2 text-right text-xs text-text-secondary">Centang "Ini topik baru" di langkah 1 untuk mengaktifkan tombol kirim.</p>
                @endif
            </section>
        </div>

        {{-- Panel konteks: tetap tampil di semua langkah (cangkang bersama, isi induk). --}}
        @component('logbook.partials.wizard.summary-shell', ['panelTitle' => 'Ringkasan Entri', 'panelDesc' => 'Tinjau informasi sebelum dikirim.', 'panelIcon' => 'description', 'ariaLabel' => 'Ringkasan entri logbook', 'autosavePanel' => 'lb-create', 'steps' => $steps])
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">tag</span> Sesi
                </span>
                <span class="summary-value">Sesi {{ $nextSesi }}</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">event</span> Tanggal Bimbingan
                </span>
                <span class="summary-value" data-summary-tanggal>—</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">Penerima</span>
                <span class="summary-value" data-summary-penerima>—</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">sell</span> Topik Bimbingan
                </span>
                <span class="summary-value" data-summary-topik>Belum diisi</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">build</span> Jawaban
                </span>
                <span class="summary-value" data-summary-kartu>0 jawaban</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">attach_file</span> File
                </span>
                <span class="summary-value" data-summary-file>Belum diunggah</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">flag</span> Status
                </span>
                <span class="summary-value">@include('partials.status-badge', ['status' => 'draft'])</span>
            </div>
            @slot('after')
                <div class="mt-5 border-t border-border pt-4">
                    <h3 class="mb-3 font-heading text-sm font-semibold text-text-primary">Lampiran</h3>
                    <x-file-upload-mirror input="lampiran" empty-text="Belum ada lampiran" :hint="$fileHint" :actions="false" />
                </div>
            @endslot
        @endcomponent
    </form>

    {{-- Bulk delete: draf / revisi sedang dikerjakan pada program ini. --}}
    <section class="card p-5 mt-6" aria-label="Hapus draf terpilih">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-0">
                <h2 class="font-heading font-semibold text-text-primary">Hapus Draf Terpilih</h2>
                <p class="text-caption text-text-secondary">Hanya draf / revisi sedang dikerjakan yang bisa dipilih.</p>
            </div>
            <div class="ml-auto flex items-center gap-2">
                @if (($deletableEntries ?? collect())->isNotEmpty())
                    <button type="submit" form="bulk-delete-form-create" id="bulk-delete-btn-create" disabled
                        class="px-4 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm font-medium hover:bg-status-danger/20 disabled:opacity-40 disabled:cursor-not-allowed">
                        Hapus terpilih (<span id="bulk-delete-count-create">0</span>)
                    </button>
                @endif
            </div>
        </div>
        @if (($deletableEntries ?? collect())->isEmpty())
            <p class="mt-3 text-sm text-text-secondary">Tidak ada draf yang bisa dihapus pada program ini.</p>
        @else
            <form id="bulk-delete-form-create" method="POST" action="{{ route('logbook.bulk-destroy') }}" class="mt-4 space-y-2">
                @csrf
                @if ($ta?->jenis) <input type="hidden" name="program" value="{{ $ta->jenis }}"> @endif
                <label class="flex items-center gap-2 text-xs text-text-secondary">
                    <input type="checkbox" id="bulk-select-all-create"> Pilih semua
                </label>
                @foreach ($deletableEntries as $entry)
                    <label class="flex items-start gap-3 rounded-xl border border-border px-3 py-2 text-sm hover:bg-bg-panel/50">
                        <input type="checkbox" name="ids[]" value="{{ $entry->id }}" class="bulk-checkbox-create mt-1">
                        <span class="min-w-0">
                            <span class="block font-medium text-text-primary">{{ $entry->jenis === 'revisi' ? 'Revisi' : 'Sesi '.$entry->sesi_ke }} · {{ $entry->topik ?? 'Tanpa topik' }}</span>
                            <span class="block text-xs text-text-secondary">{{ $entry->tanggal_tampil?->format('d M Y') ?? '—' }} · @include('partials.status-badge', ['status' => $entry->status, 'entry' => $entry])</span>
                        </span>
                    </label>
                @endforeach
            </form>
            <script>
                (function () {
                    var form = document.getElementById('bulk-delete-form-create');
                    if (!form) return;
                    var boxes = Array.from(form.querySelectorAll('.bulk-checkbox-create'));
                    var countEl = document.getElementById('bulk-delete-count-create');
                    var btn = document.getElementById('bulk-delete-btn-create');
                    var selectAll = document.getElementById('bulk-select-all-create');

                    function refresh() {
                        var n = boxes.filter(function (b) { return b.checked; }).length;
                        if (countEl) countEl.textContent = n;
                        if (btn) btn.disabled = n === 0;
                        if (selectAll) {
                            selectAll.checked = boxes.length > 0 && boxes.every(function (b) { return b.checked; });
                            selectAll.indeterminate = n > 0 && n < boxes.length;
                        }
                    }

                    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
                    if (selectAll) {
                        selectAll.addEventListener('change', function () {
                            boxes.forEach(function (b) { b.checked = selectAll.checked; });
                            refresh();
                        });
                    }
                    form.addEventListener('submit', function (e) {
                        var n = boxes.filter(function (b) { return b.checked; }).length;
                        if (n === 0) { e.preventDefault(); return; }
                        if (!confirm('Hapus ' + n + ' entri terpilih? Hanya draf / revisi sedang dikerjakan yang akan dihapus.')) {
                            e.preventDefault();
                        }
                    });
                    refresh();
                })();
            </script>
        @endif
    </section>
</div>
@endsection

@section('scripts')
@include('partials.form-workspace-script')
<script>
    // ===== Wizard entri logbook (4 langkah, pola create-revisi) =====
    var form = document.getElementById('logbook-form');
    var currentStep = 1;
    var totalSteps = 4;
    var stepButtons = document.querySelectorAll('.wizard-step');
    var panels = document.querySelectorAll('.wizard-panel');
    var fileInputEl = document.getElementById('lampiran');
    var uploadHintEl = document.getElementById('upload-hint');
    var tanggalEl = document.getElementById('tanggal_bimbingan');
    var topikEl = document.getElementById('topik');
    var pesanInput = document.getElementById('progres_kendala');
    var pesanCounter = document.getElementById('pesan-counter');
    var recipientSelect = document.getElementById('addressed_dosen_id');
    var kartuContainer = document.getElementById('kartu-perbaikan');
    var tambahBtn = document.getElementById('tambah-kartu');
    var statusOptions = @json($statusOptions);
    var cardStatusOptions = statusOptions.filter(function (s) { return s !== 'Draf'; });
    var cardSeq = 0;

    function escapeHtml(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }
    var confirmBox = document.querySelector('[data-revision-confirm]');
    var hasPendingRevision = @json($hasPendingRevision);
    var draftBoot = @json($draftBoot ?? null);
    var hasExistingFile = !!(draftBoot && draftBoot.has_file);
    var initialStep = {{ $initialStep ?? 1 }};
    var autoPullOnLoad = @json(!empty($autoPullOnLoad));

    function hasFile() {
        return !!(fileInputEl && fileInputEl.files && fileInputEl.files.length > 0);
    }

    function fileValid() {
        if (hasFile()) return window.LbUpload ? window.LbUpload.isValid('lampiran') : true;
        return hasExistingFile;
    }

    function formatDate(value) {
        if (!value) return '—';
        var d = new Date(value + 'T00:00:00');
        if (isNaN(d.getTime())) return value;
        try {
            return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
        } catch (e) { return value; }
    }

    // ===== Kartu jawaban: baca nilai form yang sama (tanpa state duplikat) =====
    // Tanpa induk = tulis manual (tidak ada select komentar dosen).
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
        return !!(d.halaman.trim() && d.komentar_dosen.trim() && d.perbaikan.trim() && d.status && d.status !== 'Draf');
    }

    function cardNeedsAttention(card) {
        return !cardComplete(card);
    }

    function incompleteCount() {
        return cards().filter(cardNeedsAttention).length;
    }

    function cardMissingFields(card) {
        var d = cardData(card);
        var missing = [];
        if (!d.halaman.trim()) missing.push('Halaman');
        if (!d.komentar_dosen.trim()) missing.push('Komentar dosen');
        if (!d.perbaikan.trim()) missing.push('Perbaikan Anda');
        if (!d.status || d.status === 'Draf') missing.push('Status');
        return missing;
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
        if (cardNeedsAttention(card)) parts.push('Belum lengkap');
        summaryEl.textContent = parts.join(' • ');
        card.classList.toggle('perbaikan-needs-attention', cardNeedsAttention(card));
        var badge = card.querySelector('[data-card-badge]');
        if (badge) badge.classList.toggle('hidden', !cardNeedsAttention(card));
    }

    function highlightNewCards(count) {
        var list = cards().slice(-Math.max(count, 0));
        list.forEach(function (card) {
            card.classList.add('perbaikan-flash');
            setTimeout(function () { card.classList.remove('perbaikan-flash'); }, 2400);
        });
        var first = list[0];
        if (first) {
            first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var input = first.querySelector('input, textarea, select');
            if (input) input.focus({ preventScroll: true });
        }
    }

    function stepComplete(n) {
        if (n === 1) {
            var base = !!(recipientSelect && recipientSelect.value && topikEl && topikEl.value.trim() && tanggalEl && tanggalEl.value);
            if (hasPendingRevision) return base && !!(confirmBox && confirmBox.checked);
            return base;
        }
        if (n === 2) return fileValid();
        if (n === 3) return allCardsComplete();
        return false;
    }

    function updateStepHint() {
        var hint = document.getElementById('kartu-hint');
        if (!hint) return;
        var fileOk = fileValid();
        var missing = incompleteCount();
        var total = cards().length;
        var countEl = hint.querySelector('[data-kartu-count]');
        var msg;
        if (!fileOk) {
            msg = 'Unggah file bimbingan pada langkah 2 — tanpa file, PDF tidak bisa ditandai.';
        } else if (total === 0) {
            msg = 'Belum ada isian — tandai di PDF lalu salin, atau tulis sendiri.';
        } else if (missing > 0) {
            msg = missing + ' dari ' + total + ' jawaban belum lengkap';
        } else {
            msg = total + ' jawaban siap dikirim';
        }
        if (countEl) countEl.textContent = msg;
        else hint.textContent = msg;
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
        updateStepHint();
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
        if (typeof n !== 'number' || n < 1 || n > totalSteps) n = 1;
        options = options || {};
        var focusUpload = false;
        if (n === 4 && !fileValid()) {
            n = 2;
            focusUpload = true;
            if (uploadHintEl) {
                uploadHintEl.classList.remove('hidden');
                setTimeout(function () { uploadHintEl.classList.add('hidden'); }, 4000);
            }
        }
        currentStep = Math.max(1, Math.min(totalSteps, n));
        renderSteps();
        if (currentStep === 3) maybeAutoPull();
        if (focusUpload && fileInputEl) fileInputEl.focus();
        if (!options.silent) window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function holdAtStepThree() {
        updateUploadGate();
        var hint = document.getElementById('kartu-hint');
        if (hint) hint.classList.remove('hidden');
        var bad = cards().filter(cardNeedsAttention)[0];
        if (bad) {
            bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var focusEl = null;
            bad.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (focusEl || !el.offsetParent) return;
                var v = el.value || '';
                if (!v.trim() || v === 'Draf') focusEl = el;
            });
            if (focusEl) setTimeout(function () { focusEl.focus({ preventScroll: true }); }, 400);
        }
    }

    // Gerbang kirim: file valid + semua kartu lengkap (+ konfirmasi topik
    // baru bila ada revisi pending). Simpan Draft tidak digate.
    var mergePulledRows = null;
    var isiOtomatisFrom = null;
    function updateUploadGate() {
        var fileOk = fileValid();
        var cardsOk = allCardsComplete();
        var lanjut = document.getElementById('lanjut-anotasi');
        if (lanjut) lanjut.classList.toggle('opacity-60', !fileOk);
        var kirim = document.getElementById('btn-kirim');
        var hint = document.getElementById('kirim-hint');
        var reasons = [];
        if (hasPendingRevision && !(confirmBox && confirmBox.checked)) reasons.push('centang "Ini topik baru" di langkah 1');
        if (!fileOk) reasons.push('unggah file bimbingan');
        if (!cardsOk) {
            var missing = incompleteCount();
            if (missing > 0) {
                var need = {};
                cards().filter(cardNeedsAttention).forEach(function (card) {
                    cardMissingFields(card).forEach(function (f) { need[f] = true; });
                });
                var needList = Object.keys(need).join(', ');
                reasons.push('lengkapi ' + missing + ' kartu' + (needList ? ' (' + needList + ')' : ''));
            } else {
                reasons.push('isi kartu jawaban');
            }
        }
        if (kirim) {
            kirim.disabled = reasons.length > 0;
            kirim.classList.toggle('opacity-60', reasons.length > 0);
        }
        if (hint) {
            if (reasons.length) {
                hint.textContent = 'Belum bisa dikirim — ' + reasons.join(' + ') + '.';
                hint.classList.remove('hidden');
            } else {
                hint.textContent = '';
                hint.classList.add('hidden');
            }
        }
    }

    function tryAdvance() {
        if (currentStep === 1 && !stepComplete(1)) {
            var warn = document.getElementById('step1-hint');
            if (warn) warn.classList.remove('hidden');
            var first = recipientSelect;
            if (first) first.focus();
            return;
        }
        if (currentStep === 2 && !fileValid()) {
            if (uploadHintEl) {
                uploadHintEl.classList.remove('hidden');
                setTimeout(function () { uploadHintEl.classList.add('hidden'); }, 4000);
            }
            if (fileInputEl) fileInputEl.focus();
            return;
        }
        if (currentStep === 3 && !stepComplete(3)) {
            holdAtStepThree();
            return;
        }
        showStep(currentStep + 1);
    }
    document.querySelectorAll('.wizard-next').forEach(function (btn) {
        btn.addEventListener('click', function () { tryAdvance(); });
    });
    document.querySelectorAll('.wizard-prev').forEach(function (btn) {
        btn.addEventListener('click', function () { showStep(currentStep - 1); });
    });
    stepButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = parseInt(btn.dataset.step);
            if (target > 1 && !stepComplete(1)) {
                showStep(1);
                var warn = document.getElementById('step1-hint');
                if (warn) warn.classList.remove('hidden');
                return;
            }
            if (target > 2 && !stepComplete(2)) {
                showStep(2);
                return;
            }
            if (target > 3 && !stepComplete(3)) {
                showStep(3);
                holdAtStepThree();
                return;
            }
            showStep(target);
        });
    });
    if (recipientSelect) recipientSelect.addEventListener('change', function () {
        var warn = document.getElementById('step1-hint');
        if (warn) warn.classList.add('hidden');
        renderSteps();
    });
    if (confirmBox) confirmBox.addEventListener('change', function () {
        renderSteps();
        updateUploadGate();
    });

    // ===== Lanjut ke anotasi: simpan draf di background, buka PDF tab baru =====
    var lanjutBtn = document.querySelector('#lanjut-anotasi');
    var lewatiBtn = document.getElementById('lanjut-tanpa-anotasi');
    var draftHint = document.getElementById('draft-hint');
    var draftEntryId = null;
    var draftViewerUrl = null;
    var draftPullUrl = null;
    var savingDraft = false;
    function setDraftHint(text) {
        if (!draftHint) return;
        if (!text) { draftHint.textContent = ''; draftHint.classList.add('hidden'); return; }
        draftHint.textContent = text;
        draftHint.classList.remove('hidden');
    }
    function withFrom(url) {
        try {
            var u = new URL(url, window.location.origin);
            u.searchParams.set('from', 'create-logbook');
            return u.toString();
        } catch (e) { return url; }
    }
    function activateAnnotationStep(entry) {
        draftEntryId = entry.entry_id;
        draftViewerUrl = entry.viewer_url;
        draftPullUrl = entry.pull_url;
        var draftIdEl = form.querySelector('[data-draft-id]');
        if (draftIdEl) draftIdEl.value = draftEntryId;
        var openBtn = document.querySelector('[data-anotasi-open]');
        var isiBtn = document.querySelector('[data-isi-otomatis]');
        if (openBtn) openBtn.disabled = false;
        if (isiBtn) { isiBtn.disabled = false; isiBtn.dataset.pullUrl = draftPullUrl; }
        syncIsiButtons();
        var hint = document.querySelector('[data-anotasi-hint]');
        if (hint) hint.textContent = 'Draf #' + draftEntryId + ' tersimpan — PDF terbuka di tab baru. Selesai menandai? Klik Salin.';
    }

    function syncIsiButtons() {
        var hasUrl = !!draftPullUrl;
        document.querySelectorAll('[data-isi-otomatis], [data-isi-otomatis-bawah]').forEach(function (b) {
            b.disabled = !hasUrl;
            if (hasUrl && !b.dataset.pullUrl) b.dataset.pullUrl = draftPullUrl;
        });
    }

    function cardsPristine() {
        return cards().length > 0 && cards().every(function (card) {
            var d = cardData(card);
            return !d.halaman.trim() && !d.komentar_dosen.trim() && !d.perbaikan.trim() && !d.status;
        });
    }
    function maybeAutoPull(force) {
        if (!draftPullUrl) return;
        if (!force && !cardsPristine()) return;
        isiOtomatisFrom(draftPullUrl, null);
    }
    function saveDraftAndOpenPdf() {
        setDraftHint('');
        if (!fileValid()) {
            if (uploadHintEl) {
                uploadHintEl.classList.remove('hidden');
                setTimeout(function () { uploadHintEl.classList.add('hidden'); }, 4000);
            }
            if (fileInputEl) fileInputEl.focus();
            return;
        }
        if (draftEntryId && draftViewerUrl) {
            window.open(withFrom(draftViewerUrl), '_blank');
            showStep(3);
            return;
        }
        if (!lanjutBtn || lanjutBtn.disabled || savingDraft) return;
        savingDraft = true;
        lanjutBtn.disabled = true;
        lanjutBtn.setAttribute('aria-busy', 'true');
        var label = lanjutBtn.querySelector('[data-lanjut-label]');
        if (label) label.textContent = 'Menyimpan draf…';
        var fd = new FormData(form);
        fetch(lanjutBtn.dataset.draftUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, status: res.status, d: d }; }); })
            .then(function (out) {
                if (!out.ok) {
                    var msg = (out.d && out.d.message) || 'Gagal menyimpan draf (kode ' + out.status + '). Periksa file lalu coba lagi.';
                    if (out.d && out.d.errors) {
                        var first = Object.values(out.d.errors)[0];
                        if (first && first[0]) msg = first[0];
                    }
                    throw new Error(msg);
                }
                activateAnnotationStep(out.d);
                window.open(withFrom(out.d.viewer_url), '_blank');
                showStep(3);
            })
            .catch(function (e) { setDraftHint(e.message || 'Gagal menyimpan draf. Coba lagi.'); })
            .finally(function () {
                savingDraft = false;
                lanjutBtn.disabled = false;
                lanjutBtn.removeAttribute('aria-busy');
                if (label) label.textContent = 'Lanjut ke anotasi PDF';
            });
    }
    if (lanjutBtn) lanjutBtn.addEventListener('click', saveDraftAndOpenPdf);
    if (lewatiBtn) lewatiBtn.addEventListener('click', function () {
        if (!fileValid()) {
            if (uploadHintEl) {
                uploadHintEl.classList.remove('hidden');
                setTimeout(function () { uploadHintEl.classList.add('hidden'); }, 4000);
            }
            if (fileInputEl) fileInputEl.focus();
            return;
        }
        showStep(3);
    });
    // Mode lanjutkan draf: URL anotasi milik draf langsung aktif.
    if (draftBoot && draftBoot.entry_id) {
        activateAnnotationStep(draftBoot);
        var draftHintEl = document.querySelector('[data-anotasi-hint]');
        if (draftHintEl) draftHintEl.textContent = 'Melanjutkan draf #' + draftBoot.entry_id + ' — PDF bisa dibuka & ditandai, lalu Salin.';
    }

    // ===== Salin: gabungkan baris pull ke kartu (tak menimpa isian) =====
    (function () {
        var isiBtn = document.querySelector('[data-isi-otomatis]');
        var isiBawah = document.querySelector('[data-isi-otomatis-bawah]');
        var openBtn = document.querySelector('[data-anotasi-open]');
        var isiMsg = document.querySelector('[data-isi-msg]');
        function setMsg(text, ok) {
            if (!isiMsg) return;
            isiMsg.classList.remove('hidden');
            isiMsg.className = 'mt-2 text-xs ' + (ok ? 'text-status-success' : 'text-status-danger');
            isiMsg.textContent = text;
        }
        if (openBtn) openBtn.addEventListener('click', function () {
            if (draftViewerUrl) window.open(withFrom(draftViewerUrl), '_blank');
        });
        if (isiBtn) isiBtn.addEventListener('click', function () { isiOtomatisFrom(isiBtn.dataset.pullUrl, isiBtn); });
        if (isiBawah) isiBawah.addEventListener('click', function () { isiOtomatisFrom(isiBawah.dataset.pullUrl || draftPullUrl, isiBawah); });
        function rowKey(r) {
            return [(r.halaman || '').trim(), (r.komentar_dosen || '').trim(), (r.perbaikan || '').trim()].join('￨');
        }
        mergePulledRows = function (rows) {
            var added = 0;
            rows.forEach(function (r) {
                var key = rowKey(r);
                var exists = cards().some(function (card) {
                    var d = cardData(card);
                    return [(d.halaman || '').trim(), (d.komentar_dosen || '').trim(), (d.perbaikan || '').trim()].join('￨') === key;
                });
                if (exists) return;
                var target = null;
                cards().forEach(function (card) {
                    if (target) return;
                    if (!cardData(card).perbaikan.trim()) target = card;
                });
                if (target) {
                    var d = cardData(target);
                    var halEl = target.querySelector('[name$="[halaman]"]');
                    var perEl = target.querySelector('[name$="[perbaikan]"]');
                    if (halEl && !d.halaman.trim() && r.halaman) halEl.value = r.halaman;
                    if (perEl) perEl.value = r.perbaikan || '';
                    updateCardSummary(target);
                    added++;
                } else {
                    addKartu({ halaman: r.halaman || '', komentar_dosen: r.komentar_dosen || '', perbaikan: r.perbaikan || '', status: '' }, { silentFocus: true });
                    added++;
                }
            });
            updateProgress();
            updateUploadGate();
            updateReview();
            syncSummaryPanel();
            return added;
        };
        isiOtomatisFrom = function (url, btn) {
            if (!url) return;
            if (typeof mergePulledRows !== 'function') return;
            if (btn && btn.disabled) return;
            if (btn) btn.disabled = true;
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value, 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, d: d }; }); })
                .then(function (out) {
                    if (!out.ok) throw new Error('pull gagal');
                    var rows = (out.d && out.d.rows) || [];
                    var merged = mergePulledRows(rows);
                    if (!rows.length && cards().length === 0) addKartu();
                    if (merged > 0) highlightNewCards(merged);
                    var countEl = document.querySelector('[data-isi-count]');
                    if (countEl && out.d.pulled > 0) countEl.textContent = '(' + out.d.pulled + ')';
                    var countBawah = document.querySelector('[data-isi-count-bawah]');
                    if (countBawah && out.d.pulled > 0) countBawah.textContent = '(' + out.d.pulled + ')';
                    var parts = [];
                    if (out.d.pulled > 0) parts.push(out.d.pulled + ' jawaban tersalin. Lengkapi yang kurang');
                    if (out.d.skipped_empty > 0) parts.push(out.d.skipped_empty + ' tandaan kosong dilewati');
                    setMsg(parts.length ? parts.join('. ') + '.' : 'Belum ada yang baru — tandai dulu di PDF, lalu klik lagi.', true);
                    updateUploadGate();
                })
                .catch(function () { setMsg('Gagal memasukkan ke form. Coba lagi.', false); })
                .finally(function () {
                    if (btn) btn.disabled = false;
                    syncIsiButtons();
                });
        };
        window.__lbSalin = isiOtomatisFrom;
    })();

    // Kembali dari tab viewer: lompat ke langkah 3 dan gabungkan yang ditandai.
    window.addEventListener('storage', function (e) {
        if (!draftEntryId || !e.key) return;
        var match = false;
        if (e.key === 'lbta-revisi-autopull:' + draftEntryId) {
            match = true;
        } else if (e.key === 'lbta-revisi-autopull') {
            try {
                var payload = JSON.parse(e.newValue || localStorage.getItem('lbta-revisi-autopull') || '{}');
                if (payload && String(payload.entryId) === String(draftEntryId)) match = true;
            } catch (err) {}
        }
        if (!match) return;
        try { localStorage.removeItem(e.key); } catch (err2) {}
        showStep(3);
        maybeAutoPull(true);
    });

    // ===== Kartu jawaban dinamis (tanpa induk: komentar tulis manual) =====
    function reindex() {
        Array.prototype.forEach.call(kartuContainer.querySelectorAll('.perbaikan-card'), function (card, i) {
            card.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.name = el.name.replace(/riwayat_perbaikan\[\d+\]/, 'riwayat_perbaikan[' + i + ']');
            });
            var numberEl = card.querySelector('[data-card-number]');
            if (numberEl) numberEl.textContent = 'Jawaban #' + (i + 1);
            updateCardSummary(card);
        });
    }

    function cardMarkup(data, seq) {
        var options = '<option value="">— Pilih —</option>' + cardStatusOptions.map(function (s) {
            return '<option value="' + escapeAttr(s) + '">' + escapeHtml(s) + '</option>';
        }).join('');
        if (data.status && cardStatusOptions.indexOf(data.status) === -1) {
            options += '<option value="' + escapeAttr(data.status) + '" selected>' + escapeHtml(data.status) + ' (pilih ulang)</option>';
        }

        return '<div class="perbaikan-head">' +
                '<span class="icon-chip h-9 w-9" aria-hidden="true"><span class="material-symbols-outlined icon-md text-brand">build</span></span>' +
                '<div class="perbaikan-head-text">' +
                    '<span class="perbaikan-number" data-card-number>Jawaban</span><span class="perbaikan-needs-badge hidden" data-card-badge>Belum lengkap</span>' +
                    '<span class="perbaikan-summary" data-card-summary></span>' +
                '</div>' +
                '<button type="button" class="perbaikan-toggle" data-card-toggle aria-expanded="true">' +
                    '<span class="material-symbols-outlined icon-sm" aria-hidden="true">expand_more</span>' +
                    '<span class="sr-only">Buka atau tutup jawaban</span>' +
                '</button>' +
            '</div>' +
            '<div class="perbaikan-body">' +
                '<div><label class="mb-1 block text-xs text-text-secondary" for="riwayat-komentar-' + seq + '">Komentar dosen <span class="text-status-pending">(wajib bila dari salinan)</span></label>' +
                '<input type="text" id="riwayat-komentar-' + seq + '" name="riwayat_perbaikan[0][komentar_dosen]" value="' + escapeAttr(data.komentar_dosen || '') + '" placeholder="mis. Jelaskan dasar pemilihan metode" class="form-control"></div>' +
                '<div class="flex gap-3">' +
                '<div class="w-[110px] shrink-0"><label class="mb-1 block text-xs text-text-secondary" for="riwayat-halaman-' + seq + '">Halaman</label>' +
                '<input type="text" id="riwayat-halaman-' + seq + '" name="riwayat_perbaikan[0][halaman]" value="' + escapeAttr(data.halaman || '') + '" placeholder="mis. Hal. 5" class="form-control"></div>' +
                '<div class="min-w-0 flex-1"><label class="mb-1 block text-xs text-text-secondary" for="riwayat-perbaikan-' + seq + '">Perbaikan Anda</label>' +
                '<textarea id="riwayat-perbaikan-' + seq + '" name="riwayat_perbaikan[0][perbaikan]" rows="3" placeholder="mis. Menambah penjelasan metode di Hal. 5 paragraf 2" class="form-control">' + escapeHtml(data.perbaikan || '') + '</textarea></div>' +
                '</div>' +
                '<div class="flex items-center justify-between gap-2 pt-1">' +
                    '<p class="text-[11px] text-text-secondary">Status diisi terakhir setelah perbaikan jelas.</p>' +
                    '<button type="button" class="hapus-kartu inline-flex min-h-9 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-status-danger hover:bg-status-danger/10">' +
                        '<span class="material-symbols-outlined icon-sm" aria-hidden="true">delete</span> Hapus jawaban ini' +
                    '</button>' +
                '</div>' +
                '<div><label class="mb-1 block text-xs text-text-secondary" for="riwayat-status-' + seq + '">Status</label>' +
                '<select id="riwayat-status-' + seq + '" name="riwayat_perbaikan[0][status]" data-card-status class="form-control">' + options + '</select></div>' +
            '</div>';
    }

    function addKartu(data, options) {
        data = data || {};
        options = options || {};
        var card = document.createElement('div');
        card.className = 'perbaikan-card';
        card.dataset.collapsed = '0';
        card.innerHTML = cardMarkup(data, ++cardSeq);
        kartuContainer.appendChild(card);
        if (data.status && cardStatusOptions.indexOf(data.status) !== -1) {
            var statusEl = card.querySelector('[data-card-status]');
            if (statusEl) statusEl.value = data.status;
        }
        reindex();
        updateCardSummary(card);
        updateProgress();
        updateUploadGate();
        updateReview();
        syncSummaryPanel();
        if (!options.silentFocus) {
            var firstInput = card.querySelector('input');
            if (firstInput) firstInput.focus();
        }
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
            if (hapus) {
                if (cards().length <= 1) { alert('Min. 1 jawaban harus ada.'); return; }
                var num = hapus.closest('.perbaikan-card').querySelector('[data-card-number]');
                if (!confirm('Hapus ' + (num ? num.textContent : 'kartu') + '? Tindakan tidak dapat dibatalkan.')) return;
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
            updateUploadGate();
            updateReview();
        });

        kartuContainer.addEventListener('change', function (event) {
            var card = event.target.closest('.perbaikan-card');
            if (!card) return;
            updateCardSummary(card);
            updateProgress();
            updateUploadGate();
            updateReview();
        });
    }

    // ===== Counter pesan =====
    function updateCounter() {
        if (!pesanInput || !pesanCounter) return;
        pesanCounter.textContent = pesanInput.value.length + '/500';
    }
    if (pesanInput) pesanInput.addEventListener('input', function () {
        updateCounter();
        renderSteps();
    });

    // ===== Ringkasan entri + review (satu sumber: nilai form) =====
    function setText(selector, value) {
        var el = document.querySelector(selector);
        if (el) el.textContent = value;
    }
    function recipientLabel() {
        if (recipientSelect && recipientSelect.value && recipientSelect.selectedOptions[0]) {
            return recipientSelect.selectedOptions[0].textContent.trim();
        }
        return '—';
    }
    function fileLabel() {
        if (hasFile()) return window.LbUpload && !window.LbUpload.isValid('lampiran') ? 'File tidak valid: ' + fileInputEl.files[0].name : fileInputEl.files[0].name;
        if (hasExistingFile) return (draftBoot.file_name || 'File draf') + ' (sudah diunggah)';
        return 'Belum diunggah';
    }
    function statusBadgeClass(status) {
        if (status === 'Draf') return 'badge-draft';
        var idx = statusOptions.indexOf(status);
        if (idx === 0) return 'badge-success';
        if (idx === 1) return 'badge-pending';
        return 'badge-neutral';
    }
    function syncSummaryPanel() {
        setText('[data-summary-tanggal]', formatDate(tanggalEl ? tanggalEl.value : ''));
        setText('[data-summary-topik]', topikEl && topikEl.value.trim() ? topikEl.value.trim() : 'Belum diisi');
        setText('[data-summary-penerima]', recipientLabel());
        setText('[data-summary-file]', fileLabel());
        var total = cards().length;
        var missing = incompleteCount();
        var ready = total - missing;
        setText('[data-summary-kartu]', total === 0 ? '0 jawaban' : ready + ' siap · ' + missing + ' belum lengkap');
    }
    function reviewCardRow(card, index) {
        var d = cardData(card);
        var halaman = d.halaman.trim() || 'Halaman belum diisi';
        var status = d.status || 'Status belum dipilih';
        return '<li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-bg-surface px-3 py-2">' +
                '<span class="min-w-0 text-sm">' +
                    '<span class="font-medium text-text-primary">Jawaban #' + (index + 1) + '</span>' +
                    '<span class="block text-xs text-text-secondary">' + escapeHtml(halaman) + '</span>' +
                '</span>' +
                '<span class="badge ' + statusBadgeClass(d.status) + '">' + escapeHtml(status) + '</span>' +
            '</li>';
    }
    function updateReview() {
        setText('#review-penerima', recipientLabel());
        setText('#review-tanggal', formatDate(tanggalEl ? tanggalEl.value : ''));
        setText('#review-topik', topikEl && topikEl.value.trim() ? topikEl.value.trim() : '—');
        var list = cards();
        var missingR = list.filter(cardNeedsAttention).length;
        var readyR = list.length - missingR;
        setText('#review-jumlah', list.length === 0 ? '0 jawaban' : readyR + ' siap · ' + missingR + ' belum lengkap');
        setText('#review-kartu-empty', list.length === 0 ? 'Belum ada kartu — tambahkan lewat langkah Hasil Tandaan.' : '');
        var listEl = document.getElementById('review-kartu-list');
        if (listEl) listEl.innerHTML = list.map(reviewCardRow).join('');
        var emptyEl = document.getElementById('review-kartu-empty');
        if (emptyEl) emptyEl.classList.toggle('hidden', list.length > 0);
        setText('#review-file', fileLabel());
        var pesan = pesanInput ? pesanInput.value.trim() : '';
        setText('#review-pesan', pesan || 'Tidak ada pesan tambahan');
    }
    if (fileInputEl) {
        fileInputEl.addEventListener('change', function () {
            updateUploadGate();
            if (uploadHintEl) uploadHintEl.classList.add('hidden');
            renderSteps();
        });
    }
</script>

<script>
    // Auto-save draft ke localStorage (tiap 5 detik) + restore.
    // Key per-user & per-program agar draft TA/KP atau akun berbeda tidak tertukar.
    // Bentuk lama {topik,progres,...} tetap dipulihkan ke pesan (kompatibel mundur).
    (function () {
        var PANEL = 'lb-create';
        var KEY = 'lbta-draft-{{ $userId }}-{{ $programId }}-logbook';
        var topik = document.getElementById('topik');
        var pesan = document.getElementById('progres_kendala');
        var tanggal = document.getElementById('tanggal_bimbingan');
        var penerima = document.getElementById('addressed_dosen_id');

        function timeLabel(ts) {
            return new Date(ts || Date.now()).toLocaleTimeString();
        }

        function collect() {
            var data = {
                topik: topik ? topik.value : '',
                progres_kendala: pesan ? pesan.value : '',
                tanggal_bimbingan: tanggal ? tanggal.value : '',
                addressed_dosen_id: penerima ? penerima.value : '',
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

        function applyDraft(saved) {
            if (saved.topik && topik) topik.value = saved.topik;
            if (saved.tanggal_bimbingan && tanggal) tanggal.value = saved.tanggal_bimbingan;
            else if (saved.tanggal && tanggal) tanggal.value = saved.tanggal;
            if (saved.addressed_dosen_id && penerima) penerima.value = saved.addressed_dosen_id;
            else if (saved.penerima && penerima && Array.from(penerima.options).some(function (option) { return option.value === saved.penerima; })) penerima.value = saved.penerima;
            if (pesan) pesan.value = saved.progres_kendala || saved.progres || '';
            if (saved.riwayat && saved.riwayat.length && kartuContainer) {
                kartuContainer.innerHTML = '';
                saved.riwayat.forEach(function (r) { addKartu(r, { silentFocus: true }); });
            }
            updateCounter();
            updateUploadGate();
            if (typeof syncSummaryPanel === 'function') syncSummaryPanel();
            if (typeof renderSteps === 'function') renderSteps();
        }

        function save() {
            try {
                var data = collect();
                data.ts = Date.now();
                localStorage.setItem(KEY, JSON.stringify(data));
            } catch (e) {
                window.LbAutosave.set(PANEL, 'error');
                return;
            }
            window.LbAutosave.set(PANEL, 'saved', { time: 'Terakhir disimpan ' + timeLabel() });
        }

        function restoreDraft(saved) {
            applyDraft(saved);
            window.LbAutosave.set(PANEL, 'restored', { time: 'Dipulihkan dari draf ' + timeLabel(saved.ts) });
        }

        function discardDraft() {
            try { localStorage.removeItem(KEY); } catch (e) {}
            if (topik) topik.value = '';
            if (pesan) pesan.value = '';
            if (tanggal) tanggal.value = '{{ now()->format('Y-m-d') }}';
            if (penerima) penerima.value = @json((string) ($ta->pembimbing_1_id ?: array_key_first($dosenOptions)));
            if (kartuContainer) {
                kartuContainer.innerHTML = '';
                addKartu(null, { silentFocus: true });
            }
            updateCounter();
            updateUploadGate();
            if (typeof syncSummaryPanel === 'function') syncSummaryPanel();
            if (typeof renderSteps === 'function') renderSteps();
            window.LbAutosave.set(PANEL, 'discarded');
        }

        // Cek draft tersimpan.
        try {
            var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (saved && (saved.progres_kendala || saved.progres) && pesan && !pesan.value) {
                applyDraft(saved);
                window.LbAutosave.set(PANEL, 'restored', { time: 'Dipulihkan dari draf ' + timeLabel(saved.ts) });
            } else if (saved && saved.riwayat && saved.riwayat.length && cards().length <= 1 && cardsPristine()) {
                applyDraft(saved);
                window.LbAutosave.set(PANEL, 'restored', { time: 'Dipulihkan dari draf ' + timeLabel(saved.ts) });
            } else if (saved) {
                window.LbAutosave.set(PANEL, 'idle', { time: 'Draf tersimpan ' + timeLabel(saved.ts) });
                window.LbAutosave.draftAvailable(PANEL, true);
            }
        } catch (e) {}

        window.LbAutosave.on(PANEL, 'retry', save);
        window.LbAutosave.on(PANEL, 'restore', function () {
            try {
                var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
                if (saved) restoreDraft(saved);
            } catch (e) {}
        });
        window.LbAutosave.on(PANEL, 'discard', discardDraft);

        form.addEventListener('input', function () {
            window.LbAutosave.set(PANEL, 'pending');
            if (typeof syncSummaryPanel === 'function') syncSummaryPanel();
        });
        form.addEventListener('change', function () {
            if (typeof syncSummaryPanel === 'function') syncSummaryPanel();
        });

        setInterval(save, 5000);
        // Hapus draf saat berhasil submit.
        form.addEventListener('submit', function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
        });
    })();
</script>
<script>
    // ===== Inisialisasi =====
    cards().forEach(updateCardSummary);
    updateCounter();
    updateUploadGate();
    renderSteps();
    showStep(initialStep, { silent: true });
    // Kembali dari viewer tab-sama (?step=3&autopull=1): gabungkan yang ditandai.
    if (autoPullOnLoad && initialStep === 3 && draftPullUrl) {
        maybeAutoPull(true);
    }
</script>
@endsection
