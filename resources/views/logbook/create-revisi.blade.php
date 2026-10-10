@extends('layouts.app')

@section('title', 'Jawab Revisi')

@section('content')
@php
    $inst = \App\Models\Institution::current();
    $maxMb = $inst->maxUploadSizeMb();
    $accept = $inst->fileAccept();
    $allowedTypes = $inst->allowedFileTypes();
    $typesLabel = strtoupper(implode(', ', $allowedTypes));
    $fileHint = $typesLabel . ' • Maks. ' . $maxMb . ' MB';
    $statusOptions = \App\Models\LogbookEntry::PERBAIKAN_STATUSES;
    $statusOptions3 = array_values(array_filter($statusOptions, fn ($s) => $s !== \App\Models\LogbookEntry::PERBAIKAN_DRAF));
    $oldRiwayat = old("riwayat_perbaikan", []);
    $initialRows = $oldRiwayat;
    if (!$initialRows && !empty($draft?->riwayat_perbaikan)) {
        $initialRows = $draft->riwayat_perbaikan;
    }
    if (!$initialRows && $parentComments->isNotEmpty()) {
        $initialRows = $parentComments->map(fn ($c) => [
            'id' => $c->id,
            'halaman' => 'Hal. '.$c->page_number,
            'komentar_dosen' => $c->comment,
            'perbaikan' => $c->reply,
            'status' => old("addressed_comment_status.{$c->id}", ''),
        ])->toArray();
    }
    // Opsi komentar dosen: HANYA milik induk terpilih (mandiri = kosong,
    // tinggal tulis manual). Opsi lintas induk menyesatkan status prefill.
    $commentOptions = $parentComments
        ->where('resolution_status', '!=', \App\Models\PdfComment::STATUS_RESOLVED)
        ->map(fn ($c) => [
            'id' => $c->id,
            'text' => $c->comment,
            'label' => 'Hal. '.($c->page_number ?: '—').': '.\Illuminate\Support\Str::limit($c->comment, 70),
        ])->values()->all();

    // Langkah yang perlu dibuka ulang saat validasi gagal (kesalahan tetap terlihat).
    $riwayatErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => str_starts_with($key, 'riwayat_perbaikan'))
        ->flatten();
    $errorStep = 1;
    if ($errors->has('lampiran') || $errors->has('tanggal_pengiriman')) {
        $errorStep = 2;
    }
    if ($riwayatErrors->isNotEmpty() || $errors->has('progres_kendala')) {
        $errorStep = 3;
    }

    $steps = [
        1 => ['Pilih Sesi', 'forum', 'Pilih sesi yang dijawab'],
        2 => ['Upload File', 'upload_file', 'Unggah file revisi'],
        3 => ['Salin', 'build', 'Yang ditandai jadi jawaban'],
        4 => ['Kirim', 'send', 'Cek lalu kirim'],
    ];
    // Baris jawaban: hasil old()/prefill komentar, atau satu jawaban kosong.
    $cardRows = array_values($initialRows ?: [[]]);
    // Kembali dari viewer (?step=3&autopull=1): buka langsung langkah yang
    // diminta; validasi gagal tetap menang agar kesalahan terlihat.
    $initialStep = $errorStep;
    if (!empty($requestedStep) && $riwayatErrors->isEmpty() && !$errors->has('lampiran') && !$errors->has('tanggal_pengiriman') && !$errors->has('progres_kendala')) {
        $initialStep = $requestedStep;
    }
@endphp
<div class="form-workspace">
    <x-page-header title="Jawab Revisi" description="Jawab komentar dosen" class="mb-5">
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
            @if ($draft)
                <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-status-pending/10 text-status-pending">Melanjutkan draf #{{ $draft->id }}</span>
            @endif
        </p>
    @endif

    @if (!($hasRevisionTarget ?? true))
        <div class="card mx-auto max-w-xl p-8 text-center sm:p-10">
            <span class="material-symbols-outlined icon-lg text-status-success" aria-hidden="true">check_circle</span>
            <h2 class="mt-3 font-heading text-xl font-bold text-text-primary">Tidak ada yang perlu direvisi</h2>
            <p class="mt-2 text-sm text-text-secondary">Semua sesi sudah beres. Bila dosen minta revisi nanti, halaman ini bisa dipakai menjawab.</p>
            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                <a href="{{ route('logbook.create', array_filter(['program' => $ta?->jenis])) }}" class="btn-primary inline-flex px-5 py-2.5 text-sm font-semibold">+ Logbook</a>
                <a href="{{ route('logbook.index', array_filter(['program' => $ta?->jenis])) }}" class="btn-ghost inline-flex px-5 py-2.5 text-sm font-medium">Lihat semua entri</a>
            </div>
        </div>
    @else
    @if ($draft && !$draft->parent_entry_id)
        <div class="mb-5 rounded-xl border border-status-pending/40 bg-status-pending/10 p-4" role="alert">
            <p class="font-semibold text-text-primary">Draf ini belum menjawab entri mana pun</p>
            <p class="mt-1 text-sm text-text-secondary">Pilih entri yang dijawab di langkah 1 di bawah — revisi wajib menempel ke satu entri sebelum dikirim ke dosen.</p>
        </div>
    @endif

    @include('logbook.partials.wizard.stepper', ['steps' => $steps, 'ariaLabel' => 'Langkah pengisian entri revisi'])

    <form method="POST" action="{{ route('logbook.store-revisi') }}" enctype="multipart/form-data" id="revisi-form"
        class="form-workspace-grid">
        @csrf

        <input type="hidden" name="program" value="{{ $ta?->jenis }}">
        @if ($draft)
            <input type="hidden" name="draft_id" value="{{ $draft->id }}">
        @endif

        <div class="form-workspace-column">
            {{-- ===== STEP 1: Pilih Sesi ===== --}}
            <section class="card form-workspace-card wizard-panel p-6" data-panel="1">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">forum</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">1. Pilih Sesi yang Dijawab</h2>
                        <p class="text-caption text-text-secondary">Revisi menjawab komentar dosen pada satu entri — pilih entri yang dijawab, lalu tentukan penerima.</p>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-field-label" for="parent_entry_id">Entri yang dijawab</label>
                    <div class="form-field-body">
                        <select name="parent_entry_id" id="parent_entry_id" class="form-control">
                            <option value="">Draf pribadi (belum dikirim ke dosen)</option>
                            @foreach ($parents as $parent)
                                @php
                                    $parentSesiLabel = $parent->jenis === 'revisi'
                                        ? 'Jawaban '.($parent->parentEntry?->sesi_ke ? 'Sesi '.$parent->parentEntry->sesi_ke : 'baru').($parent->revision_round ? ' (ronde '.$parent->revision_round.')' : '')
                                        : 'Sesi '.($parent->sesi_ke ?? '—');
                                @endphp
                                <option value="{{ $parent->id }}" data-dosen-id="{{ $parent->dosen_id }}"
                                    @selected(old('parent_entry_id', $selectedParentId) == $parent->id)>
                                    #{{ $parent->id }} · {{ $parentSesiLabel }} · {{ \Illuminate\Support\Str::limit($parent->topik ?: 'Tanpa topik', 50) }} · {{ $parent->tanggal_tampil?->format('d M Y') ?? '—' }} · {{ \App\Models\LogbookEntry::STATUS_LABELS[$parent->status] ?? $parent->status }}
                                </option>
                            @endforeach
                        </select>
                        <p class="form-field-hint">Pilih entri yang dijawab — wajib diisi sebelum revisi dikirim ke dosen. Draf pribadi boleh disimpan dulu tanpa memilih.</p>
                        @error('parent_entry_id')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                        @if (! empty($activeRevision))
                            <div class="mt-3 rounded-xl border border-status-pending/40 bg-status-pending/10 p-3" role="alert">
                                <p class="text-sm font-medium text-text-primary">Induk ini sudah punya revisi aktif: draf #{{ $activeRevision['id'] }} ({{ $activeRevision['status_label'] }}, {{ $activeRevision['tanggal'] }}).</p>
                                @if ($activeRevision['editable'])
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <a href="{{ $activeRevision['edit_url'] }}" class="btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">Lanjutkan draf #{{ $activeRevision['id'] }}</a>
                                        <form method="POST" action="{{ route('logbook.destroy', $activeRevision['id']) }}" onsubmit="return confirm('Hapus draf #{{ $activeRevision['id'] }}? File draf ikut terhapus.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-ghost px-4 py-2 text-sm font-medium text-status-danger">Hapus draf</button>
                                        </form>
                                    </div>
                                @else
                                    <p class="mt-1 text-xs text-text-secondary">Draf sudah dikirim dan menunggu review dosen — selesaikan/review dulu sebelum membuat revisi baru dari induk yang sama.</p>
                                @endif
                            </div>
                        @endif
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
                    @php
                        $openComments = $parent->comments->where('resolution_status', '!=', \App\Models\PdfComment::STATUS_RESOLVED);
                        $commentPayload = $openComments->map(fn ($c) => ['id' => $c->id, 'page_number' => $c->page_number, 'comment' => $c->comment, 'reply' => $c->reply])->values()->all();
                    @endphp
                    <div data-parent-feedback="{{ $parent->id }}"
                        data-comments='@json($commentPayload)'
                        class="mt-4 hidden space-y-2 rounded-xl border border-border bg-bg-panel p-3">
                        <p class="text-xs font-semibold text-text-secondary">Pesan Sesi #{{ $parent->id }}</p>
                        <p class="text-sm whitespace-pre-wrap">{{ $parent->feedback_dosen ?: "Tidak ada feedback teks." }}</p>
                        @if ($openComments->isNotEmpty())
                            <p class="pt-1 text-xs font-semibold text-text-secondary">Beri status tiap komentar yang dijawab (kosongkan bila dilewati):</p>
                            @foreach ($openComments as $comment)
                                <div class="rounded-lg bg-bg-surface p-2">
                                    <p class="text-xs">Hal. {{ $comment->page_number ?: '—' }}: {{ $comment->comment }}</p>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Status komentar Hal. {{ $comment->page_number ?: '—' }}">
                                        @foreach (['Sudah', 'Sebagian', 'Belum'] as $s)
                                            <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-medium hover:bg-bg-hover has-checked:border-brand has-checked:bg-brand/10">
                                                <input type="radio" name="addressed_comment_status[{{ $comment->id }}]" value="{{ $s }}"
                                                    @checked(old("addressed_comment_status.{$comment->id}") === $s) class="accent-brand">
                                                {{ $s }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endforeach

                <div class="form-actions">
                    <div class="form-actions-end">
                        <p id="step1-hint" class="hidden w-full text-right text-xs text-status-danger" role="alert">Pilih sesi dan penerima untuk lanjut.</p>
                        <button type="button" class="wizard-next btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                            Lanjut
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- ===== STEP 2: Upload File & Pesan ===== --}}
            <section class="card form-workspace-card wizard-panel hidden p-6" data-panel="2">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">upload_file</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">2. Upload File</h2>
                        <p class="text-caption text-text-secondary">Unggah file jawaban, lalu tandai di PDF.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">File Revisi</p>
                        <div class="mt-3">
                            <div class="form-field">
                                <label class="form-field-label" for="tanggal_pengiriman">Tanggal revisi</label>
                                <div class="form-field-body">
                                    <input type="date" name="tanggal_pengiriman" id="tanggal_pengiriman" required
                                        value="{{ $defaultTanggal }}" class="form-control">
                                    <p class="form-field-hint">Dihitung sejak draf dibuat; dapat diubah sebelum dikirim ke dosen.</p>
                                    @error('tanggal_pengiriman')
                                        <p class="form-field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                            <div class="form-field form-field--stack">
                                <label class="form-field-label" for="lampiran">File Perbaikan/Draft</label>
                                <div class="form-field-body">
                                    @if ($draftLampiran)
                                        <div class="mb-3 flex items-center gap-3 rounded-xl border border-border bg-bg-panel px-3 py-2">
                                            <span class="material-symbols-outlined icon-md text-brand" aria-hidden="true">description</span>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-text-primary">{{ $draftLampiran['name'] }}</p>
                                                <p class="text-xs text-text-secondary">Sudah diunggah — biarkan bila tidak diganti.</p>
                                            </div>
                                            <a href="{{ $draftLampiran['url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-brand hover:underline">Lihat</a>
                                        </div>
                                    @endif
                                    <x-file-upload input="lampiran" title="Upload File Revisi" :hint="$fileHint"
                                        :accept="$accept" :max-mb="$maxMb" :types="$allowedTypes" :required="empty($draftLampiran)" />
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost min-h-11 px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <p id="upload-hint" class="hidden text-xs text-status-danger" role="alert">
                            Unggah file jawaban dulu sebelum menandai.
                        </p>
                        <p id="draft-hint" class="hidden w-full text-right text-xs text-status-danger" role="status"></p>
                        <button type="button" id="lanjut-tanpa-anotasi"
                            class="btn-secondary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium"
                            >
                            Lanjut tanpa menandai
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">skip_next</span>
                        </button>
                        <button type="button" id="lanjut-anotasi"
                            class="btn-primary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium disabled:cursor-wait disabled:opacity-60"
                            data-draft-url="{{ route('logbook.store-revisi-draft') }}">
                            <span data-lanjut-label>Lanjut menandai</span>
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            {{-- ===== STEP 3: Tandai Perbaikan ===== --}}
            <section class="card form-workspace-card wizard-panel hidden p-6" data-panel="3">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">build</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">3. Salin</h2>
                        <p class="text-caption text-text-secondary">Yang kamu tandai tampil di bawah — lengkapi.</p>
                    </div>
                </div>

                @include('logbook.partials.wizard.annotation-cta', [
                    'openLabel' => 'Buka PDF',
                    'copyLabel' => 'Salin ke Jawaban',
                    'hint' => 'Draf tersimpan sendiri. PDF terbuka di tab baru.',
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
                        <div class="perbaikan-card" @if ($i === 0) id="kartu-perbaikan" @endif data-collapsed="0">
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
                                    placeholder="Tambahkan konteks untuk dosen, misalnya kendala, alasan, atau pertanyaan tertentu..."
                                    class="form-control">{{ $defaultPesan }}</textarea>
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
                        <p class="text-caption text-text-secondary">Periksa ringkasan berikut sebelum revisi dikirim.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-4">
                    <div class="rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Tujuan Revisi</p>
                        <div class="mt-2 space-y-3 text-sm">
                            <div>
                                <p class="text-xs text-text-secondary">Sesi yang dijawab</p>
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
                            <span class="text-xs text-text-secondary" id="review-jumlah" role="status">0 jawaban</span>
                        </div>
                        <ul class="mt-2 space-y-2" id="review-kartu-list"></ul>
                        <p class="mt-2 text-sm text-text-secondary" id="review-kartu-empty">Belum ada jawaban.</p>
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
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-widest text-text-secondary">Pesan untuk Dosen</p>
                            <button type="button" class="text-xs font-semibold text-brand hover:underline" data-ubah-pesan>Ubah →</button>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-text-primary" id="review-pesan">Tidak ada pesan tambahan</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="wizard-prev btn-ghost min-h-11 px-4 py-2 text-sm font-medium">← Kembali</button>
                    <div class="form-actions-end">
                        <p id="kirim-hint" class="hidden w-full text-right text-xs text-status-pending" role="status"></p>
                        <button type="submit" class="btn-secondary min-h-11 px-4 py-2 text-sm font-medium">Simpan Draft</button>
                        <button type="submit" name="submit" value="1" id="btn-kirim"
                            class="btn-primary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50" aria-describedby="kirim-hint">
                            <span data-kirim-label>Kirim ke Dosen</span>
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">send</span>
                        </button>
                    </div>
                </div>
            </section>
        </div>

        {{-- Panel konteks: tetap tampil di semua langkah (cangkang bersama, isi follow-up). --}}
        @component('logbook.partials.wizard.summary-shell', ['panelTitle' => 'Ringkasan Revisi', 'panelDesc' => 'Ringkasan informasi revisi Anda.', 'panelIcon' => 'summarize', 'ariaLabel' => 'Ringkasan revisi', 'autosavePanel' => 'lb-revisi', 'steps' => $steps])
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">forum</span> Sesi
                </span>
                <span class="summary-value" data-revisi-parent>Belum dipilih</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">send</span> Penerima
                </span>
                <span class="summary-value" data-revisi-penerima>—</span>
            </div>
            <div class="summary-row">
                <span class="summary-key">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">build</span> Jawaban
                </span>
                <span class="summary-value" data-revisi-kartu>0 jawaban</span>
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
        @endcomponent
    </form>
    @endif
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
    // Status jawaban: 3 opsi (tanpa Draf) + default kosong.
    var cardStatusOptions = statusOptions.filter(function (s) { return s !== 'Draf'; });
    // Opsi komentar selalu milik induk terpilih (mandiri = kosong + manual).
    function currentParentId() { return parentSelect ? parentSelect.value : ''; }
    function parentCommentsOf(parentId) {
        if (!parentId) return [];
        var div = document.querySelector('[data-parent-feedback="' + parentId + '"]');
        if (!div) return [];
        try { return JSON.parse(div.dataset.comments || '[]'); } catch (e) { return []; }
    }
    function komentarOptionsHTML(parentId) {
        var html = '<option value="">— Pilih komentar —</option>';
        parentCommentsOf(parentId == null ? currentParentId() : parentId).forEach(function (c) {
            var label = 'Hal. ' + (c.page_number || '—') + ': ' + String(c.comment || '').substring(0, 70);
            html += '<option value="' + escapeAttr(c.comment || '') + '" data-comment-id="' + escapeAttr(c.id) + '">' + escapeHtml(label) + '</option>';
        });
        return html + '<option value="__manual">Tulis manual…</option>';
    }
    var defaultRecipientValue = '{{ $defaultRecipientId }}';
    var draftBoot = @json($draftBoot);
    var hasExistingFile = !!(draftBoot && draftBoot.has_file);
    var initialStep = {{ $initialStep ?? $errorStep }};
    var autoPullOnLoad = @json(!empty($autoPullOnLoad));
    var cardSeq = 0;

    function hasFile() {
        return !!(fileInputEl && fileInputEl.files && fileInputEl.files.length > 0);
    }

    function fileValid() {
        if (hasFile()) return window.LbUpload ? window.LbUpload.isValid('lampiran') : true;
        return hasExistingFile;
    }

    function escapeHtml(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    // Warna chip status mengikuti urutan LogbookEntry::PERBAIKAN_STATUSES
    // (Sudah = selesai, Sebagian = sebagian, Draf = belum lengkap, sisanya netral).
    // R2: Draf memakai badge khusus (abu dashed) — bukan kuning "Sebagian" yang menipu.
    function statusBadgeClass(status) {
        if (status === 'Draf') return 'badge-draft';
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

    // ===== Jawaban: baca nilai form yang sama (tanpa state duplikat) =====
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

    // FIX 2: definisi "lengkap" tunggal, sejajar LogbookEntry::isPerbaikanRowComplete().
    function cardComplete(card) {
        var d = cardData(card);
        return !!(d.halaman.trim() && d.komentar_dosen.trim() && d.perbaikan.trim() && d.status && d.status !== 'Draf');
    }

    function cardNeedsAttention(card) {
        var d = cardData(card);
        return !cardComplete(card);
    }

    function incompleteCount() {
        return cards().filter(cardNeedsAttention).length;
    }

    // Nama field yang masih kosong — dipakai pesan
    // gate kirim agar mahasiswa tahu persis apa yang harus dilengkapi.
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

    // P5: sorot jawaban yang baru disalin + kembalikan fokus untuk keyboard/SR.
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

    // ===== Stepper: selesai / aktif / berikutnya =====
    function stepComplete(n) {
        if (n === 1) return !!(recipientSelect && recipientSelect.value);
        if (n === 2) return fileValid() && !!(tanggalEl && tanggalEl.value);
        if (n === 3) return allCardsComplete();
        return false;
    }

    // R7: hint langkah 2 — menyebut sebabnya (file vs jawaban).
    function updateStepHint() {
        var hint = document.getElementById('kartu-hint');
        if (!hint) return;
        var fileOk = fileValid();
        var missing = incompleteCount();
        var total = cards().length;
        var countEl = hint.querySelector('[data-kartu-count]');
        var msg;
        if (!fileOk) {
            msg = 'Unggah file revisi pada langkah 2 — tanpa file, PDF tidak bisa ditandai.';
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

    // Salin jalan sendiri saat masuk langkah 3 (tanpa klik tombol)
    // bila jawaban masih kosong — tinggal pilih komentar dosen.
    // Kepulangan dari viewer selalu pull-merge paksa (server idempoten via flag).
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
    // Sinyal kembali dari viewer bisa spesifik draf, spesifik induk, atau
    // generik JSON — semuanya berarti: buka langkah 3 + gabungkan yang ditandai.
    function handleViewerReturnSignal(key, rawValue) {
        if (!key || key.indexOf('lbta-revisi-autopull') !== 0) return false;
        if (key === 'lbta-revisi-autopull') {
            try {
                var payload = JSON.parse(rawValue || localStorage.getItem('lbta-revisi-autopull') || '{}');
                var relevant = false;
                if (draftEntryId && (String(payload.entryId) === String(draftEntryId) || String(payload.wizardDraftId) === String(draftEntryId))) relevant = true;
                if (!relevant && payload.wizardParentId && String(payload.wizardParentId) === String(currentParentId())) relevant = true;
                if (!relevant && payload.entryId && String(payload.entryId) === String(currentParentId())) relevant = true;
                if (!relevant) return false;
            } catch (e) { /* payload rusak = tetap lanjut */ }
        } else if (draftEntryId && key === 'lbta-revisi-autopull:' + draftEntryId) {
            // cocok draf aktif — lanjut
        } else if (key.indexOf('lbta-revisi-autopull:parent:') === 0) {
            var parentKey = key.substring('lbta-revisi-autopull:parent:'.length);
            if (String(parentKey) !== String(currentParentId())) return false;
        } else {
            return false;
        }
        try { localStorage.removeItem(key); } catch (err) {}
        if (key === 'lbta-revisi-autopull') { try { localStorage.removeItem('lbta-revisi-autopull'); } catch (err2) {} }
        showStep(3);
        maybeAutoPull(true);
        return true;
    }

    // R9: nomor langkah di luar 1-4 diabaikan — tidak pernah render panel kosong.
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

    // ===== Lanjut menandai: simpan draf di background, buka PDF tab baru =====
    var lanjutBtn = document.getElementById('lanjut-anotasi');
    var draftHint = document.getElementById('draft-hint');
    var draftEntryId = null;
    var draftViewerUrl = null;
    var draftPullUrl = null;
    var savingDraft = false;
    // Handler gabung-pull didefinisikan di IIFE bawah tapi dipakai juga oleh
    // maybeAutoPull()/storage handler di scope luar — tampung di var luar.
    var mergePulledRows = null;
    var isiOtomatisFrom = null;
    function setDraftHint(text) {
        if (!draftHint) return;
        if (!text) { draftHint.textContent = ''; draftHint.classList.add('hidden'); return; }
        draftHint.textContent = text;
        draftHint.classList.remove('hidden');
    }
    function activateAnnotationStep(entry) {
        draftEntryId = entry.entry_id;
        draftViewerUrl = entry.viewer_url;
        draftPullUrl = entry.pull_url;
        var openBtn = document.querySelector('[data-anotasi-open]');
        var isiBtn = document.querySelector('[data-isi-otomatis]');
        if (openBtn) openBtn.disabled = false;
        if (isiBtn) { isiBtn.disabled = false; isiBtn.dataset.pullUrl = draftPullUrl; }
        syncIsiButtons();
        var hint = document.querySelector('[data-anotasi-hint]');
        if (hint) hint.textContent = 'Draf #' + draftEntryId + ' tersimpan — PDF terbuka di tab baru. Selesai menandai? Klik Salin.';
    }
    // Tandai URL viewer agar kembali ke alur revisi (dipakai saveDraftAndOpenPdf
    // di scope luar maupun handler di dalam IIFE di bawah).
    function withFrom(url) {
        try {
            var u = new URL(url, window.location.origin);
            u.searchParams.set('from', 'create-revisi');
            return u.toString();
        } catch (e) { return url; }
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
        // Klik ulang memakai draf yang sama (idempoten, tidak membuat ganda).
        if (draftEntryId && draftViewerUrl) {
            window.open(withFrom(draftViewerUrl), '_blank');
            showStep(3);
            return;
        }
        // R1: kunci sinkron sebelum request — klik ganda tidak membuat draf ganda.
        if (!lanjutBtn || lanjutBtn.disabled || savingDraft) return;
        savingDraft = true;
        lanjutBtn.disabled = true;
        lanjutBtn.setAttribute('aria-busy', 'true');
        var label = lanjutBtn.querySelector('[data-lanjut-label]');
        var icon = lanjutBtn.querySelector('.material-symbols-outlined');
        if (label) label.textContent = 'Menyimpan draf…';
        if (icon) icon.textContent = 'progress_activity';
        var fd = new FormData(form);
        fd.delete('riwayat_perbaikan');
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
                if (label) label.textContent = 'Lanjut menandai';
                var iconEl = lanjutBtn.querySelector('.material-symbols-outlined');
                if (iconEl) iconEl.textContent = 'arrow_forward';
            });
    }
    if (lanjutBtn) lanjutBtn.addEventListener('click', saveDraftAndOpenPdf);
    // Lewati menandai: langsung ke langkah 3 tanpa simpan draf / buka PDF.
    // Syarat sama (file + tanggal); salin tetap bisa dipakai nanti
    // lewat langkah 2 bila berubah pikiran.
    var lewatiBtn = document.getElementById('lanjut-tanpa-anotasi');
    if (lewatiBtn) lewatiBtn.addEventListener('click', function () {
        if (!fileValid() || !(tanggalEl && tanggalEl.value)) {
            if (uploadHintEl) {
                uploadHintEl.classList.remove('hidden');
                setTimeout(function () { uploadHintEl.classList.add('hidden'); }, 4000);
            }
            if (!fileValid() && fileInputEl) fileInputEl.focus();
            else if (tanggalEl) tanggalEl.focus();
            return;
        }
        showStep(3);
    });
    // Lanjutkan draf: PDF milik draf langsung aktif.
    if (draftBoot && draftBoot.entry_id) {
        activateAnnotationStep(draftBoot);
        var draftHintEl = document.querySelector('[data-anotasi-hint]');
        if (draftHintEl) draftHintEl.textContent = 'Melanjutkan draf #' + draftBoot.entry_id + ' — PDF bisa dibuka & ditandai, lalu Salin.';
    }
    // Salin di langkah 3 memakai endpoint pull draf yang tersimpan.
    // F5: tombol atas dan bawah berbagi handler isiOtomatisFrom.
    function syncIsiButtons() {
        var hasUrl = !!draftPullUrl;
        document.querySelectorAll('[data-isi-otomatis], [data-isi-otomatis-bawah]').forEach(function (b) {
            b.disabled = !hasUrl;
            if (hasUrl && !b.dataset.pullUrl) b.dataset.pullUrl = draftPullUrl;
        });
    }
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
        // Gabungkan baris pull ke kartu: lewati yang sudah ada, isi kartu
        // kosong dulu (hanya field kosong, tak menimpa), sisa jadi kartu baru.
        // Tak pernah menghapus isian mahasiswa.
        // Diekspos ke var luar (bukan function lokal) agar maybeAutoPull +
        // storage handler di scope luar bisa memanggilnya.
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
    })();
    document.querySelectorAll('.wizard-prev').forEach(function (btn) {
        btn.addEventListener('click', function () { showStep(currentStep - 1); });
    });
    // Tombol Lanjut tiap langkah (semua memakai class .wizard-next).
    document.querySelectorAll('.wizard-next').forEach(function (btn) {
        btn.addEventListener('click', function () { tryAdvance(); });
    });
    // Pesan + penerima wajib dipilih sebelum keluar langkah 1.
    function tryAdvance() {
        if (currentStep === 1 && !stepComplete(1)) {
            var warn = document.getElementById('step1-hint');
            if (warn) warn.classList.remove('hidden');
            var first = parentSelect || recipientSelect;
            if (first) first.focus();
            return;
        }
        if (currentStep === 2 && (!fileValid() || !(tanggalEl && tanggalEl.value))) {
            if (uploadHintEl) {
                uploadHintEl.classList.remove('hidden');
                setTimeout(function () { uploadHintEl.classList.add('hidden'); }, 4000);
            }
            if (!fileValid() && fileInputEl) fileInputEl.focus();
            else if (tanggalEl) tanggalEl.focus();
            return;
        }
        // Langkah 3 harus lengkap sebelum boleh intip langkah 4.
        if (currentStep === 3 && !stepComplete(3)) {
            holdAtStepThree();
            return;
        }
        showStep(currentStep + 1);
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
    stepButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            // Stepper tidak bisa melompati langkah yang belum valid.
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
            // Langsung ke langkah 4 tetap wajib lolos kelengkapan kartu.
            if (target > 3 && !stepComplete(3)) {
                showStep(3);
                holdAtStepThree();
                return;
            }
            showStep(target);
        });
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
        // Status kartu: 3 opsi + default kosong agar menjadi pilihan sadar.
        var options = '<option value="">— Pilih —</option>' + cardStatusOptions.map(function (s) {
            return '<option value="' + escapeAttr(s) + '">' + escapeHtml(s) + '</option>';
        }).join('');
        if (data.status && cardStatusOptions.indexOf(data.status) === -1) {
            options += '<option value="' + escapeAttr(data.status) + '" selected>' + escapeHtml(data.status) + ' (pilih ulang)</option>';
        }

        return '<div class="perbaikan-head">' +
                '<span class="icon-chip h-9 w-9" aria-hidden="true"><span class="material-symbols-outlined icon-md text-brand">build</span></span>' +
                '<div class="perbaikan-head-text">' +
                    '<span class="perbaikan-number" data-card-number>Perbaikan #</span><span class="perbaikan-needs-badge hidden" data-card-badge>Belum lengkap</span>' +
                    '<span class="perbaikan-summary" data-card-summary></span>' +
                '</div>' +
                '<button type="button" class="perbaikan-toggle" data-card-toggle aria-expanded="true">' +
                    '<span class="material-symbols-outlined icon-sm" aria-hidden="true">expand_more</span>' +
                    '<span class="sr-only">Buka atau tutup jawaban</span>' +
                '</button>' +
            '</div>' +
            '<div class="perbaikan-body">' +
                '<div><label class="mb-1 block text-xs text-text-secondary" for="riwayat-komentar-' + seq + '">Komentar dosen</label>' +
                '<select id="riwayat-komentar-' + seq + '" data-komentar-select name="riwayat_perbaikan[0][komentar_dosen]" class="form-control">' + komentarOptionsHTML() + '</select>' +
                '<input type="text" data-komentar-manual placeholder="Tulis komentar dosen…" class="form-control mt-2 hidden"></div>' +
                '<div class="flex gap-3">' +
                '<div class="w-[110px] shrink-0"><label class="mb-1 block text-xs text-text-secondary" for="riwayat-halaman-' + seq + '">Halaman</label>' +
                '<input type="text" id="riwayat-halaman-' + seq + '" name="riwayat_perbaikan[0][halaman]" value="' + escapeAttr(data.halaman || '') + '" placeholder="mis. Hal. 5" class="form-control"></div>' +
                '<div class="min-w-0 flex-1"><label class="mb-1 block text-xs text-text-secondary" for="riwayat-perbaikan-' + seq + '">Perbaikan yang Dilakukan</label>' +
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

    // ===== Komentar dosen: pilih dari daftar (prefill status) atau tulis manual =====
    function radioFor(commentId) {
        if (commentId == null || commentId === '') return null;
        return form.querySelector('input[name="addressed_comment_status[' + commentId + ']"]:checked');
    }

    function setKomentarMode(card, mode) {
        var sel = card.querySelector('[data-komentar-select]');
        var manual = card.querySelector('[data-komentar-manual]');
        if (!sel || !manual) return;
        if (mode === 'manual') {
            if (sel.name) { manual.name = sel.name; sel.removeAttribute('name'); }
            manual.classList.remove('hidden');
        } else {
            if (manual.name) { sel.name = manual.name; manual.removeAttribute('name'); }
            manual.classList.add('hidden');
        }
    }

    function applyCommentStatus(card, commentId) {
        var statusEl = card.querySelector('[data-card-status]');
        if (!statusEl) return;
        var radio = radioFor(commentId);
        if (radio && radio.value) statusEl.value = radio.value;
    }

    function applyCardKomentar(card, data) {
        var sel = card.querySelector('[data-komentar-select]');
        var manual = card.querySelector('[data-komentar-manual]');
        if (!sel || !manual) return;
        var text = data.komentar_dosen || '';
        var targetId = data.komentar_comment_id != null ? String(data.komentar_comment_id) : null;
        var matched = null;
        if (targetId) {
            matched = Array.prototype.find.call(sel.options, function (o) { return o.dataset.commentId === targetId; });
        }
        if (!matched && text) {
            matched = Array.prototype.find.call(sel.options, function (o) { return o.value !== '' && o.value !== '__manual' && o.value === text; });
        }
        if (data.komentar_mode === 'manual' || (text && !matched)) {
            sel.value = '__manual';
            setKomentarMode(card, 'manual');
            manual.value = text;
        } else if (matched) {
            sel.value = matched.value;
            setKomentarMode(card, 'select');
        } else {
            sel.value = '';
            setKomentarMode(card, 'select');
        }
    }

    function wireKomentar(card) {
        var sel = card.querySelector('[data-komentar-select]');
        if (!sel || sel.dataset.wired) return;
        sel.dataset.wired = '1';
        sel.addEventListener('change', function () {
            if (sel.value === '__manual') {
                setKomentarMode(card, 'manual');
                var manual = card.querySelector('[data-komentar-manual]');
                if (manual) manual.focus();
            } else {
                setKomentarMode(card, 'select');
                var opt = sel.selectedOptions[0];
                if (opt && opt.dataset.commentId) applyCommentStatus(card, opt.dataset.commentId);
            }
            updateCardSummary(card);
            updateProgress();
            updateUploadGate();
            updateReview();
            syncSummaryPanel();
        });
    }

    function addKartu(data, options) {
        data = data || {};
        options = options || {};
        var card = document.createElement('div');
        card.className = 'perbaikan-card';
        card.dataset.collapsed = '0';
        card.innerHTML = cardMarkup(data, ++cardSeq);
        kartuContainer.appendChild(card);
        applyCardKomentar(card, data);
        if (data.status && cardStatusOptions.indexOf(data.status) !== -1) {
            var statusEl = card.querySelector('[data-card-status]');
            if (statusEl) statusEl.value = data.status;
        }
        wireKomentar(card);
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

    // ===== Gerbang kirim: induk + file valid + semua kartu lengkap + status bukan Draf =====
    // Induk wajib saat kirim (server: parent_entry_id required bila submit),
    // tapi boleh kosong saat Simpan Draft (draf mandiri untuk alur anotasi-dulu).
    function updateUploadGate() {
        var fileOk = fileValid();
        var cardsOk = allCardsComplete();
        var parentOk = !!(parentSelect && parentSelect.value);
        var lanjut = document.getElementById('lanjut-anotasi');
        if (lanjut) lanjut.classList.toggle('opacity-60', !fileOk);
        var kirim = document.getElementById('btn-kirim');
        var hint = document.getElementById('kirim-hint');
        var reasons = [];
        if (!parentOk) reasons.push('pilih sesi yang dijawab di langkah 1');
        if (!fileOk) reasons.push('unggah file revisi');
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
                reasons.push('isi kartu perbaikan');
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
        // Kartu prefill menaut ke komentar induk + status radio langkah 1.
        comments.forEach(function (c) {
            var radio = c.id != null ? radioFor(c.id) : null;
            addKartu({
                halaman: c.page_number ? 'Hal. ' + c.page_number : '',
                komentar_dosen: c.comment || '',
                komentar_comment_id: c.id != null ? c.id : null,
                perbaikan: c.reply || '',
                status: radio ? radio.value : ''
            }, { silentFocus: true });
        });
    }

    var lastSyncedParent = parentSelect ? parentSelect.value : '';

    function syncParentFeedback(fill) {
        if (!parentSelect) return;
        parentCards.forEach(function (card) {
            var active = card.dataset.parentFeedback === parentSelect.value;
            card.classList.toggle('hidden', !active);
            card.querySelectorAll('input[name^="addressed_comment_status"]').forEach(function (input) {
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
        parentSelect.addEventListener('change', function () {
            var warn = document.getElementById('step1-hint');
            if (warn) warn.classList.add('hidden');
            syncParentFeedback(true);
            updateUploadGate();
            renderSteps();
        });
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
    if (recipientSelect) recipientSelect.addEventListener('change', function () {
        var warn = document.getElementById('step1-hint');
        if (warn) warn.classList.add('hidden');
        renderSteps();
    });

    // Perubahan radio status langkah 1 mengisi kartu yang statusnya masih kosong.
    if (form) form.addEventListener('change', function (e) {
        var m = e.target && e.target.name ? e.target.name.match(/^addressed_comment_status\[(\d+)\]$/) : null;
        if (!m) return;
        cards().forEach(function (card) {
            var sel = card.querySelector('[data-komentar-select]');
            var statusEl = card.querySelector('[data-card-status]');
            if (!sel || !statusEl || statusEl.value) return;
            var opt = sel.selectedOptions[0];
            if (opt && opt.dataset.commentId === m[1]) {
                statusEl.value = e.target.value;
                updateCardSummary(card);
            }
        });
        updateProgress();
        updateUploadGate();
        updateReview();
        syncSummaryPanel();
    });

    // Kembali dari tab viewer PDF (?from=create-revisi): tab viewer menutup
    // diri setelah menulis localStorage; tab wizard ini lompat ke langkah 3
    // dan menggabungkan yang ditandai. Terima semua bentuk sinyal viewer.
    window.addEventListener('storage', function (e) {
        if (!e.key) return;
        try {
            handleViewerReturnSignal(e.key, e.newValue || e.url);
        } catch (err) {}
    });
    var ubahPesan = document.querySelector('[data-ubah-pesan]');
    if (ubahPesan) ubahPesan.addEventListener('click', function () {
        showStep(3);
        setTimeout(function () { if (pesanInput) pesanInput.focus({ preventScroll: true }); }, 150);
    });

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
        if (hasFile()) return fileValid() ? fileInputEl.files[0].name : 'File tidak valid: ' + fileInputEl.files[0].name;
        if (hasExistingFile) return (draftBoot.file_name || 'File draf') + ' (sudah diunggah)';
        return 'Belum diunggah';
    }

    function syncSummaryPanel() {
        setText('[data-revisi-parent]', parentLabel());
        setText('[data-revisi-penerima]', recipientLabel());
        // R13: panel konteks menampilkan kesiapan, bukan sekadar jumlah.
        var total = cards().length;
        var missing = incompleteCount();
        var ready = total - missing;
        setText('[data-revisi-kartu]', total === 0 ? '0 jawaban' : ready + ' siap · ' + missing + ' belum lengkap');
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
        // R12: review menampilkan kesiapan tiap kartu, bukan sekadar jumlah.
        var missingR = list.filter(cardNeedsAttention).length;
        var readyR = list.length - missingR;
        setText('#review-jumlah', list.length === 0 ? '0 jawaban' : readyR + ' siap · ' + missingR + ' belum lengkap');
        // R10: daftar review menjadi pengingat kondisional agar tidak menumpuk pesan kosong.
        setText('#review-kartu-empty', list.length === 0 ? 'Belum ada kartu — tambahkan lewat langkah Hasil Penandaan.' : '');
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
        var KEY = 'lbta-draft-{{ auth()->id() }}-{{ $ta->id ?? 0 }}-revisi' + '{{ $draft?->id ? '-d'.$draft->id : '' }}';

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
                row.komentar_mode = card.querySelector('[data-komentar-manual][name]') ? 'manual' : 'select';
                data.riwayat.push(row);
            });
            data.comment_status = {};
            form.querySelectorAll('input[name^="addressed_comment_status"][type="radio"]:checked').forEach(function (r) {
                var m = r.name.match(/^addressed_comment_status\[(\d+)\]$/);
                if (m) data.comment_status[m[1]] = r.value;
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
            if (saved.comment_status) {
                Object.keys(saved.comment_status).forEach(function (id) {
                    var radio = form.querySelector('input[name="addressed_comment_status[' + id + ']"][value="' + saved.comment_status[id] + '"]');
                    if (radio) radio.checked = true;
                });
            }
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

    // FIX 1: halaman create belum punya draf tersimpan - CTA hanya informatif.
    // Alur anotasi (buka PDF + isi otomatis) tersedia di halaman Edit
    // setelah Simpan Draft. Tidak ada tombol disabled yang menipu.

    // ===== Inisialisasi =====
    // FIX 5: gate kirim + badge dihitung sejak halaman dimuat (termasuk kartu
    // prefill statis dari server), bukan menunggu ketikan pertama.
    cards().forEach(updateCardSummary);
    cards().forEach(wireKomentar);
    updateCounter();
    updateProgress();
    updateUploadGate();
    updateReview();
    syncSummaryPanel();
    renderSteps();
    if (initialStep > 1) showStep(initialStep, { silent: true });
    // Kembali dari viewer tab-sama (?step=3&autopull=1): gabungkan yang
    // ditandai jadi isian langkah 3 tanpa menunggu sinyal storage.
    if (autoPullOnLoad && initialStep === 3 && draftPullUrl) {
        maybeAutoPull(true);
    }
</script>
@endsection
