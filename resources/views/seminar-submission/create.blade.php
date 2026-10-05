@extends('layouts.app')

@section('title', 'Pengajuan '.$jenisLabel)

@section('content')
@php
    $typesLabel = strtoupper(implode(', ', $allowedTypes));
    $fileHint = $typesLabel.' • Maks. '.$maxMb.' MB';
@endphp
<div class="form-workspace">
    <x-page-header subtitle="Seminar" title="Pengajuan {{ $jenisLabel }}"
        description="Lengkapi informasi dan dokumen untuk pengajuan {{ $jenisLabel }} Anda." class="mb-5">
        <x-slot:actions>
            <a href="{{ route('dashboard') }}"
                class="btn-ghost inline-flex items-center gap-2 px-4 py-2 text-sm font-medium">
                <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_back</span> Dashboard
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('seminar-submission.store', $mahasiswaTa) }}"
        enctype="multipart/form-data" id="seminar-form" class="form-workspace-grid">
        @csrf

        <div class="form-workspace-column">
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
                    <div class="form-field form-field--stack">
                        <label class="form-field-label" for="tanggal">Tanggal <span class="text-status-danger" aria-hidden="true">*</span></label>
                        <div class="form-field-body">
                            <input type="date" name="tanggal" id="tanggal" required value="{{ old('tanggal') }}" class="form-control">
                            @error('tanggal') <p class="form-field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="form-field form-field--stack">
                        <label class="form-field-label" for="waktu">Waktu <span class="text-status-danger" aria-hidden="true">*</span></label>
                        <div class="form-field-body">
                            <input type="time" name="waktu" id="waktu" required value="{{ old('waktu') }}" class="form-control">
                            @error('waktu') <p class="form-field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                <div class="form-field mt-4">
                    <label class="form-field-label" for="lokasi">Lokasi</label>
                    <div class="form-field-body">
                        <input type="text" name="lokasi" id="lokasi" value="{{ old('lokasi') }}" placeholder="Gedung A, Ruang Sidang 2" class="form-control">
                        @error('lokasi') <p class="form-field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="form-field mt-4">
                    <label class="form-field-label" for="meeting_link">Tautan video conference</label>
                    <div class="form-field-body">
                        <input type="url" name="meeting_link" id="meeting_link" value="{{ old('meeting_link') }}" placeholder="https://zoom.us/j/... / https://meet.google.com/..." class="form-control">
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

                <div class="form-field mt-5">
                    <span class="form-field-label" id="undangan-label">File Surat Undangan</span>
                    <div class="form-field-body" role="group" aria-labelledby="undangan-label">
                        <x-file-upload input="undangan" title="Upload Surat Undangan" :hint="$fileHint"
                            :accept="$fileAccept" :max-mb="$maxMb" :types="$allowedTypes" :required="true" />
                    </div>
                </div>

                <div class="form-field mt-5">
                    <span class="form-field-label" id="undangan-kepada-label">Diundang (penerima surat) <span class="text-status-danger" aria-hidden="true">*</span></span>
                    <div class="form-field-body" role="group" aria-labelledby="undangan-kepada-label">
                        <div class="space-y-2">
                            @foreach ($undanganOptions as $key => $label)
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-bg-panel px-3 py-2.5 transition hover:border-brand/40">
                                    <input type="checkbox" name="undangan_kepada[]" value="{{ $key }}"
                                        @checked(in_array($key, old('undangan_kepada', []))) class="mt-1 shrink-0 accent-brand">
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
                        <h2 id="seminar-materi-title" class="font-heading font-semibold text-text-primary">3. Dokumen Materi {{ $jenisLabel }} <span class="text-status-danger" aria-hidden="true">*</span></h2>
                        <p class="text-caption text-text-secondary">Pilih salah satu sumber materi.</p>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Sumber materi">
                    <label class="cursor-pointer">
                        <input type="radio" name="materi_source" value="upload" class="peer sr-only" checked onchange="toggleMateriSource(this.value)">
                        <span class="block h-full rounded-xl border border-border bg-bg-panel p-4 transition peer-checked:border-brand peer-checked:bg-brand/5 peer-checked:ring-1 peer-checked:ring-brand/40 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-brand">
                            <span class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                                <span class="material-symbols-outlined icon-sm text-brand" aria-hidden="true">upload_file</span> Upload file baru
                            </span>
                            <span class="mt-1 block text-xs text-text-secondary">Pilih file dari perangkat Anda.</span>
                        </span>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="materi_source" value="workspace" class="peer sr-only" onchange="toggleMateriSource(this.value)">
                        <span class="block h-full rounded-xl border border-border bg-bg-panel p-4 transition peer-checked:border-brand peer-checked:bg-brand/5 peer-checked:ring-1 peer-checked:ring-brand/40 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-brand">
                            <span class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                                <span class="material-symbols-outlined icon-sm text-brand" aria-hidden="true">folder_shared</span> Ambil dari Workspace
                            </span>
                            <span class="mt-1 block text-xs text-text-secondary">Gunakan file yang sudah tersimpan.</span>
                        </span>
                    </label>
                </div>

                <div class="form-field mt-4" data-materi-upload>
                    <span class="form-field-label" id="materi-upload-label">File dari perangkat</span>
                    <div class="form-field-body" role="group" aria-labelledby="materi-upload-label">
                        <x-file-upload input="materi_upload" title="Upload Materi" :hint="$fileHint"
                            :accept="$fileAccept" :max-mb="$maxMb" :types="$allowedTypes" />
                    </div>
                </div>

                <div class="form-field mt-4 hidden" data-materi-workspace>
                    <label class="form-field-label" for="materi_workspace_id">File Workspace</label>
                    <div class="form-field-body">
                        <select name="materi_workspace_id" id="materi_workspace_id" class="form-control" disabled onchange="toggleMateriSource('workspace')">
                            <option value="">— Pilih file workspace —</option>
                            @foreach ($workspaceFiles as $file)
                                <option value="{{ $file->id }}" @selected(old('materi_workspace_id') == $file->id)>
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

                <div class="form-field mt-5">
                    <span class="form-field-label" id="tambahan-label">File tambahan</span>
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
                            'values' => old('tautan', ['']),
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
                <div class="form-field form-field--stack mt-5">
                    <label class="form-field-label sr-only" for="catatan_keterangan">Catatan Keterangan</label>
                    <div class="form-field-body">
                        <textarea name="catatan_keterangan" id="catatan_keterangan" rows="3" class="form-control"
                            placeholder="Tambahkan catatan atau informasi tambahan...">{{ old('catatan_keterangan') }}</textarea>
                        @error('catatan_keterangan') <p class="form-field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            @if ($defaultCatatan)
                <section class="card form-workspace-card p-5" aria-labelledby="seminar-hardcopy-title">
                    <div class="form-card-head">
                        <span class="icon-chip h-10 w-10" aria-hidden="true">
                            <span class="material-symbols-outlined icon-md text-brand">info</span>
                        </span>
                        <div class="min-w-0">
                            <h2 id="seminar-hardcopy-title" class="font-heading font-semibold text-text-primary">Catatan Penting</h2>
                        </div>
                    </div>
                    <p class="mt-4 whitespace-pre-line text-sm text-text-secondary">{{ $defaultCatatan }}</p>
                </section>
            @endif

            {{-- ===== Action bar ===== --}}
            <div class="card form-workspace-card flex flex-wrap items-center gap-2 p-5">
                <a href="{{ route('dashboard') }}" class="btn-ghost px-4 py-2 text-sm font-medium">Batal</a>
                <button type="submit" class="btn-primary ml-auto inline-flex items-center gap-2 px-5 py-2 text-sm font-medium">
                    Kirim Pengajuan
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">send</span>
                </button>
            </div>
        </div>

        <aside class="form-workspace-panel" aria-label="Konteks pengajuan">
            @include('seminar-submission.partials.context-sidebar', [
                'recipients' => array_values($undanganOptions),
                'mahasiswaTa' => $mahasiswaTa,
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
    function toggleMateriSource(source) {
        var upload = document.getElementById('materi_upload');
        var workspace = document.getElementById('materi_workspace_id');
        var blockUpload = document.querySelector('[data-materi-upload]');
        var blockWorkspace = document.querySelector('[data-materi-workspace]');
        var radioWorkspace = document.querySelector('input[name="materi_source"][value="workspace"]');
        var radioUpload = document.querySelector('input[name="materi_source"][value="upload"]');
        var isWorkspace = source === 'workspace';
        if (radioWorkspace) radioWorkspace.checked = isWorkspace;
        if (radioUpload) radioUpload.checked = !isWorkspace;
        if (blockUpload) blockUpload.classList.toggle('hidden', isWorkspace);
        if (blockWorkspace) blockWorkspace.classList.toggle('hidden', !isWorkspace);
        if (upload) upload.disabled = isWorkspace;
        if (workspace) workspace.disabled = !isWorkspace;
    }

</script>
@endsection
