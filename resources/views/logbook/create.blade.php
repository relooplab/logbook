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
        </p>
    @endif

    <form method="POST" action="{{ route('logbook.store') }}" enctype="multipart/form-data" id="logbook-form"
        class="form-workspace-grid">
        @csrf

        <input type="hidden" name="program" value="{{ $ta?->jenis }}">

        <div class="form-workspace-column">
            <section class="card form-workspace-card p-6">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">edit_note</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">Form Entri Logbook</h2>
                        <p class="text-caption text-text-secondary">Lengkapi informasi bimbingan di bawah ini.</p>
                    </div>
                </div>

                {{-- Sesi dibuat otomatis oleh sistem (bukan input yang bisa diubah). --}}
                <div class="form-field">
                    <span class="form-field-label">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">tag</span> Sesi
                    </span>
                    <div class="form-field-body">
                        <p class="form-static">Sesi {{ $nextSesi }}</p>
                        <p class="form-field-hint">Nomor sesi dibuat otomatis dari entri logbook terakhir.</p>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-field-label" for="addressed_dosen_id">Kirim kepada (penerima logbook)</label>
                    <div class="form-field-body">
                        <select name="addressed_dosen_id" id="addressed_dosen_id" required class="form-control">
                            @foreach ($dosenOptions as $dosenId => $label)
                                <option value="{{ $dosenId }}" @selected((string) $defaultRecipientId === (string) $dosenId)>{{ $label }}</option>
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
                            value="{{ old('tanggal_bimbingan', now()->format('Y-m-d')) }}" class="form-control">
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
                        <input type="text" name="topik" id="topik" required value="{{ old('topik', $lastTopik) }}"
                            placeholder="Masukkan topik bimbingan..." class="form-control">
                        @if ($lastTopik)
                            <p class="form-field-hint">Topik sebelumnya: {{ $lastTopik }}</p>
                        @endif
                        @error('topik')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Ringkasan Perbaikan: input utama halaman. --}}
                <div class="form-field form-field--stack">
                    <label class="form-field-label" for="progres_kendala">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">notes</span>
                        Ringkasan Perbaikan
                    </label>
                    <div class="form-field-body">
                        <div class="editor-shell">
                            <div class="editor-toolbar" id="tb-toolbar" role="toolbar"
                                aria-label="Sisipkan daftar pada ringkasan perbaikan">
                                <button type="button" data-insert="bullet">
                                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">format_list_bulleted</span> Bullet
                                </button>
                                <button type="button" data-insert="number">
                                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">format_list_numbered</span> Number
                                </button>
                                <button type="button" data-insert="dash">
                                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">horizontal_rule</span> Dash
                                </button>
                            </div>
                            <textarea name="progres_kendala" id="progres_kendala" rows="7" required class="editor-textarea"
                                placeholder="Tuliskan hasil bimbingan, masukan dosen, dan tindak lanjut yang perlu dilakukan...">{{ old('progres_kendala') }}</textarea>
                        </div>
                        <p class="form-field-hint">
                            Sisipkan daftar lewat toolbar; Enter pada baris bernomor melanjutkan penomoran otomatis.
                        </p>
                        @error('progres_kendala')
                            <p class="form-field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Lampiran opsional; input file asli tetap sumber state tunggal. --}}
                <div class="form-field form-field--stack">
                    <span class="form-field-label">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">attach_file</span>
                        Lampiran <span class="font-normal">(opsional)</span>
                    </span>
                    <div class="form-field-body">
                        <x-file-upload input="lampiran" title="Upload Lampiran" :hint="$fileHint" :accept="$accept"
                            :max-mb="$maxMb" :types="$allowedTypes" />
                    </div>
                </div>

            </section>
        </div>

        <aside class="form-workspace-panel" aria-label="Konteks entri logbook">
            <section class="card form-workspace-card p-5">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">description</span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-heading font-semibold text-text-primary">Ringkasan Entri</h2>
                        <p class="text-caption text-text-secondary">Tinjau informasi sebelum dikirim.</p>
                    </div>
                </div>
                <div class="mt-4">
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
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">flag</span> Status
                        </span>
                        <span class="summary-value">@include('partials.status-badge', ['status' => 'draft'])</span>
                    </div>
                </div>

                <div class="mt-5 border-t border-border pt-4">
                    <h3 class="mb-3 font-heading text-sm font-semibold text-text-primary">Lampiran</h3>
                    <x-file-upload-mirror input="lampiran" empty-text="Belum ada lampiran" :hint="$fileHint" :actions="false" />
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                    <a href="{{ route('logbook.index', array_filter(['program' => $ta?->jenis])) }}" class="btn-ghost px-4 py-2 text-sm font-medium">Batal</a>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn-secondary px-4 py-2 text-sm font-medium">Simpan Draft</button>
                        <button type="submit" name="submit" value="1"
                            class="btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                            Kirim ke Dosen
                            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </section>

            <x-autosave-status panel="lb-create" />
        </aside>
    </form>
</div>
@endsection

@section('scripts')
@include('partials.form-workspace-script')
@include('partials.tb-script')
<script>
    initTbToolbar('progres_kendala');
</script>
<script>
    // Auto-save draft ke localStorage (tiap 5 detik) + restore.
    // Key per-user & per-program agar draft TA/KP atau akun berbeda tidak tertukar.
    (function () {
        var PANEL = 'lb-create';
        var KEY = 'lbta-draft-{{ $userId }}-{{ $programId }}-logbook';
        var form = document.getElementById('logbook-form');
        var topik = document.getElementById('topik');
        var progres = document.getElementById('progres_kendala');
        var tanggal = document.getElementById('tanggal_bimbingan');
        var penerima = document.getElementById('addressed_dosen_id');

        function formatDate(value) {
            if (!value) return '—';
            var d = new Date(value + 'T00:00:00');
            if (isNaN(d.getTime())) return value;
            try {
                return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
            } catch (e) { return value; }
        }

        function timeLabel(ts) {
            return new Date(ts || Date.now()).toLocaleTimeString();
        }

        // Ringkasan entri membaca nilai form yang sama (tanpa state duplikat).
        function syncSummary() {
            var tanggalEl = document.querySelector('[data-summary-tanggal]');
            var topikEl = document.querySelector('[data-summary-topik]');
            var penerimaEl = document.querySelector('[data-summary-penerima]');
            if (tanggalEl) tanggalEl.textContent = formatDate(tanggal.value);
            if (topikEl) topikEl.textContent = topik.value.trim() || 'Belum diisi';
            if (penerimaEl) penerimaEl.textContent = penerima.selectedOptions[0]?.textContent || 'Belum dipilih';
        }

        function save() {
            try {
                localStorage.setItem(KEY, JSON.stringify({ topik: topik.value, progres: progres.value, tanggal: tanggal.value, penerima: penerima.value, ts: Date.now() }));
            } catch (e) {
                window.LbAutosave.set(PANEL, 'error');
                return;
            }
            window.LbAutosave.set(PANEL, 'saved', { time: 'Terakhir disimpan ' + timeLabel() });
        }

        function restoreDraft(saved) {
            if (saved.topik) topik.value = saved.topik;
            if (saved.progres) progres.value = saved.progres;
            if (saved.tanggal) tanggal.value = saved.tanggal;
            if (saved.penerima && Array.from(penerima.options).some(function (option) { return option.value === saved.penerima; })) penerima.value = saved.penerima;
            syncSummary();
            window.LbAutosave.set(PANEL, 'restored', { time: 'Dipulihkan dari draf ' + timeLabel(saved.ts) });
        }

        function discardDraft() {
            try { localStorage.removeItem(KEY); } catch (e) {}
            topik.value = '';
            progres.value = '';
            tanggal.value = '{{ now()->format('Y-m-d') }}';
            penerima.value = @json((string) ($ta->pembimbing_1_id ?: array_key_first($dosenOptions)));
            syncSummary();
            window.LbAutosave.set(PANEL, 'discarded');
        }

        // Cek draft tersimpan.
        try {
            var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (saved && saved.progres && !progres.value) {
                window.LbAutosave.set(PANEL, 'restored', { label: 'Draf lama ditemukan', time: 'Terakhir disimpan ' + timeLabel(saved.ts) });
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
            syncSummary();
        });
        form.addEventListener('change', syncSummary);

        setInterval(save, 5000);
        // Hapus draf saat berhasil submit.
        form.addEventListener('submit', function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
        });

        syncSummary();
    })();
</script>
@endsection
