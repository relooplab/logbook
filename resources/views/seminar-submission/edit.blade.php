@extends('layouts.app')

@section('title', 'Edit Bahan '.$submission->jenisLabel())

@section('content')
@php
    $typesLabel = strtoupper(implode(', ', $allowedTypes));
    $fileHint = $typesLabel.' • Maks. '.$maxMb.' MB';
    $existingFiles = $submission->documents->where('type', 'file');
    $existingLinks = $submission->documents->where('type', 'link')->pluck('url')->all();
    $statusBadge = $submission->status === \App\Models\SeminarSubmission::STATUS_SUBMITTED
        ? '<span class="badge badge-pending">'.$submission->statusLabel().'</span>'
        : '<span class="badge badge-neutral">'.$submission->statusLabel().'</span>';
    $fromWorkspace = $submission->materiFromWorkspace();
@endphp
<div class="form-workspace">
    <x-page-header subtitle="Seminar" title="Edit Bahan {{ $submission->jenisLabel() }}"
        description="Perbarui bahan untuk {{ $submission->jenisLabel() }} Anda." class="mb-5">
        <x-slot:actions>
            <a href="{{ route('seminar-submission.show', $submission) }}"
                class="btn-ghost inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_back</span> Kembali
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('seminar-submission.update', $submission) }}"
        enctype="multipart/form-data" id="seminar-form" class="form-workspace-grid">
        @csrf
        @method('PUT')

        <div class="form-workspace-column">
            {{-- ===== Banner kelengkapan (presentasi state form, bukan validasi baru) ===== --}}
            <section class="card form-workspace-card p-5" aria-labelledby="seminar-info-title">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">info</span>
                    </span>
                    <div class="min-w-0">
                        <h2 id="seminar-info-title" class="font-heading font-semibold text-text-primary">Informasi Pengajuan</h2>
                        <p class="text-caption text-text-secondary">Pastikan semua data dan dokumen sudah lengkap sebelum menyimpan perubahan.</p>
                    </div>
                </div>
                <ul class="mt-4 space-y-1.5 text-sm" data-completeness aria-live="polite">
                    <li class="flex items-center gap-2 text-text-secondary" data-check="jadwal">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">radio_button_unchecked</span>
                        <span>Jadwal lengkap</span>
                    </li>
                    <li class="flex items-center gap-2 text-text-secondary" data-check="undangan">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">radio_button_unchecked</span>
                        <span>Surat undangan tersedia</span>
                    </li>
                    <li class="flex items-center gap-2 text-text-secondary" data-check="materi">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">radio_button_unchecked</span>
                        <span>Materi seminar tersedia</span>
                    </li>
                    <li class="flex items-center gap-2 text-text-secondary">
                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">radio_button_unchecked</span>
                        <span>Dokumen tambahan opsional</span>
                    </li>
                </ul>
            </section>

            {{-- ===== 1. Jadwal Seminar ===== --}}
            <section class="card form-workspace-card p-6" aria-labelledby="seminar-jadwal-title">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">calendar_month</span>
                    </span>
                    <div class="min-w-0">
                        <h2 id="seminar-jadwal-title" class="font-heading font-semibold text-text-primary">1. Jadwal Seminar</h2>
                        <p class="text-caption text-text-secondary">Tentukan waktu dan tempat pelaksanaan.</p>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="form-field">
                        <label class="form-field-label" for="tanggal">Tanggal <span class="text-status-danger" aria-hidden="true">*</span></label>
                        <div class="form-field-body">
                            <input type="date" name="tanggal" id="tanggal" required value="{{ old('tanggal', $submission->tanggal->format('Y-m-d')) }}" class="form-control">
                            @error('tanggal') <p class="form-field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="form-field-label" for="waktu">Waktu <span class="text-status-danger" aria-hidden="true">*</span></label>
                        <div class="form-field-body">
                            <input type="time" name="waktu" id="waktu" required value="{{ old('waktu', $submission->waktu?->format('H:i')) }}" class="form-control">
                            @error('waktu') <p class="form-field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                <div class="form-field mt-4">
                    <label class="form-field-label" for="lokasi">Lokasi</label>
                    <div class="form-field-body">
                        <input type="text" name="lokasi" id="lokasi" value="{{ old('lokasi', $submission->lokasi) }}" placeholder="Gedung A, Ruang Sidang 2" class="form-control">
                        @error('lokasi') <p class="form-field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="form-field mt-4">
                    <label class="form-field-label" for="meeting_link">Tautan video conference</label>
                    <div class="form-field-body">
                        <input type="url" name="meeting_link" id="meeting_link" value="{{ old('meeting_link', $submission->meeting_link) }}" placeholder="https://zoom.us/j/... / https://meet.google.com/..." class="form-control">
                        @error('meeting_link') <p class="form-field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- ===== 2. Surat Undangan ===== --}}
            <section class="card form-workspace-card p-6" aria-labelledby="seminar-undangan-title">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">mail</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 id="seminar-undangan-title" class="font-heading font-semibold text-text-primary">2. Surat Undangan <span class="text-status-danger" aria-hidden="true">*</span></h2>
                        <p class="text-caption text-text-secondary">Surat undangan resmi pelaksanaan seminar.</p>
                    </div>
                </div>

                <div class="mt-5 rounded-xl border border-border bg-bg-panel p-4">
                    <p class="text-xs text-text-secondary">File saat ini:</p>
                    <p class="mt-1 flex min-w-0 items-center gap-2 text-sm font-medium text-text-primary">
                        <span class="material-symbols-outlined icon-sm shrink-0 text-brand" aria-hidden="true">description</span>
                        <span class="min-w-0 truncate">{{ $submission->undangan_original_name }}</span>
                    </p>
                </div>

                <div class="form-field mt-4">
                    <span class="form-field-label" id="undangan-label">Ganti File Surat Undangan (opsional)</span>
                    <div class="form-field-body" role="group" aria-labelledby="undangan-label">
                        <x-file-upload input="undangan" title="Upload Surat Undangan" :hint="$fileHint"
                            :accept="$fileAccept" :max-mb="$maxMb" :types="$allowedTypes" />
                    </div>
                </div>

                <div class="form-field mt-5">
                    <span class="form-field-label" id="undangan-kepada-label">Diundang (penerima surat) <span class="text-status-danger" aria-hidden="true">*</span></span>
                    <div class="form-field-body" role="group" aria-labelledby="undangan-kepada-label">
                        <div class="space-y-2">
                            @foreach ($undanganOptions as $key => $label)
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-bg-panel px-3 py-2.5 transition hover:border-brand/40">
                                    <input type="checkbox" name="undangan_kepada[]" value="{{ $key }}"
                                        @checked(in_array($key, old('undangan_kepada', $submission->undangan_kepada ?? []))) class="mt-1 shrink-0 accent-brand">
                                    <span class="min-w-0 text-sm font-medium text-text-primary">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="form-field-hint">Pilih satu atau lebih dosen yang namanya tercantum di surat undangan.</p>
                        @error('undangan_kepada') <p class="form-field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- ===== 3. Dokumen Materi ===== --}}
            <section class="card form-workspace-card p-6" aria-labelledby="seminar-materi-title">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">picture_as_pdf</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 id="seminar-materi-title" class="font-heading font-semibold text-text-primary">3. Dokumen Materi {{ $submission->jenisLabel() }} <span class="text-status-danger" aria-hidden="true">*</span></h2>
                        <p class="text-caption text-text-secondary">Pilih salah satu sumber materi.</p>
                    </div>
                </div>

                @if ($submission->materi_path)
                    <div class="mt-5 rounded-xl border border-border bg-bg-panel p-4">
                        <p class="text-xs text-text-secondary">File saat ini:</p>
                        <p class="mt-1 flex min-w-0 items-center gap-2 text-sm font-medium text-text-primary">
                            <span class="material-symbols-outlined icon-sm shrink-0 text-brand" aria-hidden="true">description</span>
                            <span class="min-w-0 truncate">{{ $submission->materi_original_name }}</span>
                        </p>
                        <p class="mt-1 text-xs text-text-secondary">{{ $submission->materiFromWorkspace() ? 'Dari workspace' : 'Upload baru' }}</p>
                    </div>
                @endif

                <div class="mt-4 grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Sumber materi">
                    <label class="cursor-pointer">
                        <input type="radio" name="materi_source" value="upload" class="peer sr-only" @checked(!$fromWorkspace) onchange="toggleMateriSource()">
                        <span class="block h-full rounded-xl border border-border bg-bg-panel p-4 transition peer-checked:border-brand peer-checked:bg-brand/5 peer-checked:ring-1 peer-checked:ring-brand/40 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-brand">
                            <span class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                                <span class="material-symbols-outlined icon-sm text-brand" aria-hidden="true">upload_file</span> Upload file baru
                            </span>
                            <span class="mt-1 block text-xs text-text-secondary">Pilih file dari perangkat Anda.</span>
                        </span>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="materi_source" value="workspace" class="peer sr-only" @checked($fromWorkspace) onchange="toggleMateriSource()">
                        <span class="block h-full rounded-xl border border-border bg-bg-panel p-4 transition peer-checked:border-brand peer-checked:bg-brand/5 peer-checked:ring-1 peer-checked:ring-brand/40 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-brand">
                            <span class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                                <span class="material-symbols-outlined icon-sm text-brand" aria-hidden="true">folder_shared</span> Ambil dari Workspace
                            </span>
                            <span class="mt-1 block text-xs text-text-secondary">Gunakan file yang sudah tersimpan.</span>
                        </span>
                    </label>
                </div>

                <div class="form-field mt-4{{ $fromWorkspace ? ' hidden' : '' }}" data-materi-upload>
                    <span class="form-field-label" id="materi-upload-label">File dari perangkat</span>
                    <div class="form-field-body" role="group" aria-labelledby="materi-upload-label">
                        <x-file-upload input="materi_upload" title="Upload Materi" :hint="$fileHint"
                            :accept="$fileAccept" :max-mb="$maxMb" :types="$allowedTypes" />
                    </div>
                </div>

                <div class="form-field mt-4{{ !$fromWorkspace ? ' hidden' : '' }}" data-materi-workspace>
                    <label class="form-field-label" for="materi_workspace_id">File Workspace</label>
                    <div class="form-field-body">
                        <select name="materi_workspace_id" id="materi_workspace_id" class="form-control" @disabled(!$fromWorkspace)>
                            <option value="">— Pilih file workspace —</option>
                            @foreach ($workspaceFiles as $file)
                                <option value="{{ $file->id }}" @selected(old('materi_workspace_id', $submission->materi_workspace_file_id) == $file->id)>
                                    {{ $file->original_name }} ({{ $file->sizeHuman() }})
                                </option>
                            @endforeach
                        </select>
                        @if ($workspaceFiles->isEmpty())
                            <p class="form-field-hint">Belum ada file yang sesuai di Workspace.</p>
                        @endif
                    </div>
                </div>
                @error('materi_upload') <p class="form-field-error">{{ $message }}</p> @enderror
                @error('materi_workspace_id') <p class="form-field-error">{{ $message }}</p> @enderror
            </section>

            {{-- ===== 4. Dokumen Tambahan (opsional) ===== --}}
            <section class="card form-workspace-card p-6" aria-labelledby="seminar-tambahan-title">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">attach_file</span>
                    </span>
                    <div class="min-w-0 flex flex-wrap items-center gap-2">
                        <h2 id="seminar-tambahan-title" class="font-heading font-semibold text-text-primary">4. Dokumen Tambahan</h2>
                        <span class="badge badge-neutral">Opsional</span>
                    </div>
                </div>

                @if ($existingFiles->isNotEmpty())
                    <fieldset class="mt-5">
                        <legend class="form-field-label">File saat ini (maks {{ $tambahanMaxFiles }} file total, centang untuk hapus)</legend>
                        <div class="mt-2 space-y-2">
                            @foreach ($existingFiles as $doc)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-border bg-bg-panel px-3 py-2.5 transition hover:border-status-danger/40">
                                    <input type="checkbox" name="hapus_dokumen[]" value="{{ $doc->id }}" class="shrink-0 accent-brand">
                                    <span class="material-symbols-outlined icon-sm shrink-0 text-brand" aria-hidden="true">description</span>
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-text-primary">{{ $doc->original_name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="form-field mt-4">
                    <span class="form-field-label" id="tambahan-label">Tambah file</span>
                    <div class="form-field-body" role="group" aria-labelledby="tambahan-label">
                        @include('seminar-submission.partials.tambahan-picker', [
                            'typesLabel' => 'PDF, DOC, Excel',
                            'maxFiles' => $tambahanMaxFiles,
                            'maxTotalMb' => $tambahanMaxTotalMb,
                        ])
                    </div>
                </div>

                <div class="form-field mt-5">
                    <span class="form-field-label" id="tautan-label">Tautan</span>
                    <div class="form-field-body" role="group" aria-labelledby="tautan-label">
                        @include('seminar-submission.partials.tautan-rows', [
                            'values' => old('tautan', $existingLinks ?: ['']),
                            'maxLinks' => $tautanMaxLinks,
                        ])
                    </div>
                </div>
            </section>

            {{-- ===== 5. Catatan (opsional) ===== --}}
            <section class="card form-workspace-card p-6" aria-labelledby="seminar-catatan-title">
                <div class="form-card-head">
                    <span class="icon-chip h-10 w-10" aria-hidden="true">
                        <span class="material-symbols-outlined icon-md text-brand">notes</span>
                    </span>
                    <div class="min-w-0 flex flex-wrap items-center gap-2">
                        <h2 id="seminar-catatan-title" class="font-heading font-semibold text-text-primary">5. Catatan Keterangan</h2>
                        <span class="badge badge-neutral">Opsional</span>
                    </div>
                </div>
                <div class="form-field mt-5">
                    <label class="form-field-label sr-only" for="catatan_keterangan">Catatan Keterangan</label>
                    <div class="form-field-body">
                        <textarea name="catatan_keterangan" id="catatan_keterangan" rows="3" class="form-control"
                            placeholder="Tambahkan catatan atau informasi tambahan...">{{ old('catatan_keterangan', $submission->catatan_keterangan) }}</textarea>
                        @error('catatan_keterangan') <p class="form-field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- ===== Action bar ===== --}}
            <div class="card form-workspace-card flex flex-wrap items-center gap-2 p-5">
                <a href="{{ route('seminar-submission.show', $submission) }}" class="btn-ghost px-4 py-2 text-sm font-medium">Batal</a>
                <button type="submit" class="btn-primary ml-auto inline-flex items-center gap-2 px-5 py-2 text-sm font-medium">
                    Simpan Perubahan
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">save</span>
                </button>
            </div>
        </div>

        <aside class="form-workspace-panel" aria-label="Konteks pengajuan">
            @include('seminar-submission.partials.context-sidebar', [
                'statusBadge' => $statusBadge,
                'recipients' => array_values($undanganOptions),
                'mahasiswaTa' => $submission->mahasiswaTa,
                'allowedTypes' => $allowedTypes,
                'maxMb' => $maxMb,
            ])
        </aside>
    </form>
</div>
@endsection

@section('scripts')
@include('partials.form-workspace-script')
<script>
    function toggleMateriSource() {
        var source = document.querySelector('input[name="materi_source"]:checked').value;
        var upload = document.getElementById('materi_upload');
        var workspace = document.getElementById('materi_workspace_id');
        var blockUpload = document.querySelector('[data-materi-upload]');
        var blockWorkspace = document.querySelector('[data-materi-workspace]');
        var isWorkspace = source === 'workspace';
        if (blockUpload) blockUpload.classList.toggle('hidden', isWorkspace);
        if (blockWorkspace) blockWorkspace.classList.toggle('hidden', !isWorkspace);
        if (upload) upload.disabled = isWorkspace;
        if (workspace) workspace.disabled = !isWorkspace;
    }

    // Banner kelengkapan: cerminan state form (bukan validasi baru).
    // Undangan & materi dianggap tersedia karena sudah ada file tersimpan;
    // file pengganti yang dipilih ikut dihitung.
    (function () {
        var form = document.getElementById('seminar-form');
        if (!form) return;

        var hasUndangan = {{ $submission->undangan_path ? 'true' : 'false' }};
        var hasMateri = {{ $submission->materi_path ? 'true' : 'false' }};

        function fileChosen(id) {
            var el = document.getElementById(id);
            return !!(el && el.files && el.files.length);
        }

        function setCheck(key, done) {
            var li = form.querySelector('[data-check="' + key + '"]');
            if (!li) return;
            var icon = li.querySelector('.material-symbols-outlined');
            li.classList.toggle('text-status-info', done);
            li.classList.toggle('text-text-secondary', !done);
            if (icon) icon.textContent = done ? 'check_circle' : 'radio_button_unchecked';
        }

        function sync() {
            var tanggal = document.getElementById('tanggal');
            var waktu = document.getElementById('waktu');
            var workspace = document.getElementById('materi_workspace_id');
            setCheck('jadwal', !!(tanggal && tanggal.value && waktu && waktu.value));
            setCheck('undangan', hasUndangan || fileChosen('undangan'));
            setCheck('materi', hasMateri || fileChosen('materi_upload') || !!(workspace && !workspace.disabled && workspace.value));
        }

        form.addEventListener('input', sync);
        form.addEventListener('change', sync);
        sync();
    })();
</script>
@endsection
