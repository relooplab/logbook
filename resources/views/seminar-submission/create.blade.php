@extends('layouts.app')

@section('title', 'Pemberian Bahan '.$jenisLabel)

@section('content')
<div class="max-w-2xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">Pemberian Bahan {{ $jenisLabel }}</h1>
            <p class="text-sm text-text-secondary mt-0.5">Lengkapi bahan untuk {{ $jenisLabel }} Anda</p>
        </div>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
    </div>

    <form method="POST" action="{{ route('seminar-submission.store', $mahasiswaTa) }}" enctype="multipart/form-data" class="space-y-4">
        @csrf

        {{-- ===== Rencana Jadwal ===== --}}
        <div class="card p-6">
            <h2 class="font-heading font-semibold text-text-primary mb-4">Rencana Jadwal {{ $jenisLabel }}</h2>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-text-secondary mb-1">Tanggal <span class="text-status-danger">*</span></label>
                    <input type="date" name="tanggal" required value="{{ old('tanggal') }}" class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm">
                    @error('tanggal') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-text-secondary mb-1">Waktu <span class="text-status-danger">*</span></label>
                    <input type="time" name="waktu" required value="{{ old('waktu') }}" class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm">
                    @error('waktu') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-xs text-text-secondary mb-1">Lokasi/Link (jika ada)</label>
                <input type="text" name="lokasi" value="{{ old('lokasi') }}" placeholder="Ruang sidang / link meeting" class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm">
                @error('lokasi') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ===== Surat Undangan ===== --}}
        <div class="card p-6">
            <h2 class="font-heading font-semibold text-text-primary mb-4">Surat Undangan</h2>
            <div>
                <label class="block text-xs text-text-secondary mb-1">File Surat Undangan <span class="text-status-danger">*</span></label>
                <input type="file" name="undangan" required accept="{{ $fileAccept }}" class="w-full text-sm">
                <p class="text-xs text-text-secondary mt-1">Format: {{ implode(', ', $allowedTypes) }} · Maks {{ $maxMb }} MB</p>
                @error('undangan') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mt-4">
                <label class="block text-xs text-text-secondary mb-1">Diundang (penerima surat) <span class="text-status-danger">*</span></label>
                <div class="space-y-2">
                    @foreach ($undanganOptions as $key => $label)
                        <label class="flex items-start gap-2 rounded-xl border border-border bg-bg-surface px-3 py-2 cursor-pointer">
                            <input type="checkbox" name="undangan_kepada[]" value="{{ $key }}"
                                @checked(in_array($key, old('undangan_kepada', []))) class="mt-0.5">
                            <span class="text-sm text-text-primary">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-text-secondary mt-1">Pilih satu atau lebih dosen yang namanya tercantum di surat undangan.</p>
                @error('undangan_kepada') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ===== Dokumen Materi ===== --}}
        <div class="card p-6">
            <h2 class="font-heading font-semibold text-text-primary mb-4">Dokumen Materi {{ $jenisLabel }} <span class="text-status-danger">*</span></h2>
            <p class="text-sm text-text-secondary mb-3">Pilih salah satu: upload file baru ATAU ambil dari workspace.</p>

            <div class="space-y-3">
                <label class="flex items-start gap-3 p-3 rounded-xl bg-bg-panel border border-border cursor-pointer">
                    <input type="radio" name="materi_source" value="upload" class="mt-1" checked onchange="toggleMateriSource(this.value)">
                    <div class="flex-1">
                        <span class="text-sm font-medium">Upload file baru</span>
                        <input type="file" name="materi_upload" id="materi_upload" accept="{{ $fileAccept }}" class="w-full text-sm mt-2" onchange="toggleMateriSource('upload')">
                        <p class="text-xs text-text-secondary mt-1">Format: {{ implode(', ', $allowedTypes) }} · Maks {{ $maxMb }} MB</p>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-3 rounded-xl bg-bg-panel border border-border cursor-pointer">
                    <input type="radio" name="materi_source" value="workspace" class="mt-1" onchange="toggleMateriSource(this.value)">
                    <div class="flex-1">
                        <span class="text-sm font-medium">Ambil dari workspace</span>
                        <select name="materi_workspace_id" id="materi_workspace_id" class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm mt-2" onchange="toggleMateriSource('workspace')">
                            <option value="">— Pilih file workspace —</option>
                            @foreach ($workspaceFiles as $file)
                                <option value="{{ $file->id }}" @selected(old('materi_workspace_id') == $file->id)>
                                    {{ $file->original_name }} ({{ $file->sizeHuman() }})
                                </option>
                            @endforeach
                        </select>
                        @if ($workspaceFiles->isEmpty())
                            <p class="text-xs text-text-secondary mt-1">Belum ada file di workspace.</p>
                        @endif
                    </div>
                </label>
            </div>
            @error('materi_upload') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
            @error('materi_workspace_id') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
        </div>

        {{-- ===== Dokumen Tambahan (opsional) ===== --}}
        <div class="card p-6">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <h2 class="font-heading font-semibold text-text-primary">Dokumen Tambahan</h2>
                <span class="badge badge-neutral">Opsional</span>
            </div>
            <div>
                <label class="block text-xs text-text-secondary mb-1">File tambahan (maks 3 file)</label>
                <input type="file" name="dokumen_tambahan[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx" class="w-full text-sm">
                <p class="text-xs text-text-secondary mt-1">Format: PDF, DOC, Excel · Total maks 10 MB untuk semua file tambahan</p>
                @error('dokumen_tambahan') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
                @error('dokumen_tambahan.*') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mt-4">
                <label class="block text-xs text-text-secondary mb-1">Tautan (mis. Google Drive)</label>
                <div id="tautan-list" class="space-y-2">
                    @foreach (old('tautan', ['']) as $tautan)
                        <div class="flex items-center gap-2">
                            <input type="url" name="tautan[]" value="{{ $tautan }}" placeholder="https://..." class="flex-1 rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm">
                            <button type="button" onclick="removeTautanRow(this)" class="px-3 py-2 rounded-xl bg-bg-hover text-text-primary text-sm hover:bg-border" aria-label="Hapus tautan">✕</button>
                        </div>
                    @endforeach
                </div>
                <button type="button" onclick="addTautanRow()" class="mt-2 px-3 py-1.5 rounded-xl bg-bg-hover text-text-primary text-xs font-medium hover:bg-border">＋ Tambah tautan</button>
                @error('tautan') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
                @error('tautan.*') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ===== Catatan Hardcopy ===== --}}
        @if ($defaultCatatan)
            <div class="card p-6">
                <h2 class="font-heading font-semibold text-text-primary mb-2">Catatan Penting</h2>
                <p class="text-sm text-text-secondary whitespace-pre-line">{{ $defaultCatatan }}</p>
            </div>
        @endif

        {{-- ===== Catatan Keterangan ===== --}}
        <div class="card p-6">
            <h2 class="font-heading font-semibold text-text-primary mb-4">Catatan Keterangan (jika ada)</h2>
            <textarea name="catatan_keterangan" rows="3" class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm" placeholder="Informasi tambahan untuk dosen...">{{ old('catatan_keterangan') }}</textarea>
            @error('catatan_keterangan') <p class="text-xs text-status-danger mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Kirim Bahan {{ $jenisLabel }}</button>
            <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Batal</a>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
    function addTautanRow(value) {
        var list = document.getElementById('tautan-list');
        if (list.querySelectorAll('input[name="tautan[]"]').length >= 10) return;
        var row = document.createElement('div');
        row.className = 'flex items-center gap-2';
        row.innerHTML = '<input type="url" name="tautan[]" value="" placeholder="https://..." class="flex-1 rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm">'
            + '<button type="button" onclick="removeTautanRow(this)" class="px-3 py-2 rounded-xl bg-bg-hover text-text-primary text-sm hover:bg-border" aria-label="Hapus tautan">✕</button>';
        if (value) row.querySelector('input').value = value;
        list.appendChild(row);
    }
    function removeTautanRow(btn) {
        var list = document.getElementById('tautan-list');
        if (list.querySelectorAll('input[name="tautan[]"]').length <= 1) {
            btn.closest('div').querySelector('input').value = '';
            return;
        }
        btn.closest('div').remove();
    }
    function toggleMateriSource(source) {        var upload = document.getElementById('materi_upload');
        var workspace = document.getElementById('materi_workspace_id');
        var radioWorkspace = document.querySelector('input[name="materi_source"][value="workspace"]');
        var radioUpload = document.querySelector('input[name="materi_source"][value="upload"]');
        if (source === 'workspace') {
            if (radioWorkspace) radioWorkspace.checked = true;
            upload.disabled = true;
            workspace.disabled = false;
        } else if (source === 'upload') {
            if (radioUpload) radioUpload.checked = true;
            upload.disabled = false;
            workspace.disabled = true;
        }
    }
</script>
@endsection