@extends("layouts.app") @section("title", "Workspace") @section("content")
@php
    $user = auth()->user();
    // Anggota program (pemilik utama + anggota kelompok KP) bisa upload/edit/hapus.
    $canUpload = $mahasiswaTa->isMember($user);
    $isOwner = $mahasiswaTa->isMember($user);
    $allFiles = $grouped->flatten(1);
    $babs = $mahasiswaTa->workspaceFiles()->whereNotNull('bab')->distinct()->pluck('bab');
@endphp

<div class="space-y-6">
    <x-page-header subtitle="Bimbingan" title="Workspace">
        <x-slot:actions>
            <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
        </x-slot:actions>
    </x-page-header>
    {{-- Info: dapat dilihat dosen --}}
    <div class="flex items-start gap-3 rounded-xl border border-brand/20 bg-brand/5 px-4 py-3 text-sm text-text-secondary">
        <span class="material-symbols-outlined icon-md text-brand flex-shrink-0">visibility</span>
        <p>Workspace ini dapat <span class="font-medium text-text-primary">dilihat oleh dosen pembimbing</span> Anda ({{ $mahasiswaTa->pembimbing1?->name ?? '—' }}{{ $mahasiswaTa->pembimbing2 ? ', ' . $mahasiswaTa->pembimbing2->name : '' }}). Dosen dapat melihat, mengunggah, dan mengunduh file di sini sebagai bahan bimbingan.</p>
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('workspace.index', $mahasiswaTa) }}" class="card p-4 flex flex-wrap gap-3 items-end">
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Tipe</label>
            <select name="type" class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
                <option value="">Semua Tipe</option>
                <option value="pdf" @selected(request('type') === 'pdf')>PDF</option>
                <option value="doc" @selected(request('type') === 'doc')>DOCX</option>
                <option value="xls" @selected(request('type') === 'xls')>XLSX</option>
            </select>
        </div>
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Cari</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama file / catatan"
                class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
        </div>
        <div class="flex gap-2 w-full sm:w-auto">
            <button type="submit" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Cari</button>
            <a href="{{ route('workspace.index', $mahasiswaTa) }}" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border text-center">Reset</a>
        </div>
    </form>
    {{-- Upload --}}
    @if ($canUpload)
        <div class="card p-6">
            <h2 class="font-heading font-semibold text-text-primary mb-3">Upload File</h2>
            <form method="POST" action="{{ route('workspace.store', $mahasiswaTa) }}" enctype="multipart/form-data" id="upload-form">
                @csrf
                <div class="grid sm:grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-xs text-text-secondary mb-1">Label Bab (opsional)</label>
                        <input type="text" name="bab" placeholder="contoh: Bab 1, Bab 3 Revisi"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
                    </div>
                </div>
                <div id="drop-zone"
                    class="border-2 border-dashed border-border rounded-xl p-8 text-center cursor-pointer hover:border-brand/30 transition">
                    <p><span class="material-symbols-outlined" style="font-size:48px">folder_open</span></p>
                    <p class="text-sm mt-2">Tarik & lepas file di sini, atau <span class="text-brand font-medium">klik untuk memilih</span></p>
                    <input type="file" name="files[]" id="file-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx" class="hidden">
                </div>
                <div id="file-list" class="mt-3 space-y-1"></div>
                <div id="progress-wrap" class="hidden mt-3">
                    <div class="h-2 rounded-full bg-bg-panel overflow-hidden">
                        <div id="progress-bar" class="h-full rounded-full bg-brand" style="width:0%"></div>
                    </div>
                    <p id="progress-text" class="text-xs text-text-secondary mt-1">0%</p>
                </div>
                <button type="submit" id="upload-btn" disabled
                    class="mt-3 px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90 disabled:opacity-40">Upload</button>
                @error('files.*')
                    <p class="text-status-danger text-xs mt-1">{{ $message }}</p>
                @enderror
            </form>
        </div>
        @endif
    {{-- Daftar file --}}
    @if ($allFiles->isEmpty())
        <div class="px-4 py-10 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
            <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">folder_off</span>
            <p>Belum ada file di workspace.</p>
        </div>
    @else
        @foreach ($grouped as $bab => $files)
            <div class="card p-0 overflow-hidden">
                <div class="px-4 py-3 bg-bg-panel/50 border-b border-border">
                    <span class="font-semibold text-sm text-text-primary">{{ $bab }}</span>
                    @if ($bab === \App\Models\WorkspaceFile::BAB_ARSIP)
                        <p class="mt-0.5 text-xs text-text-secondary">
                            Menyimpan maksimal {{ \App\Models\WorkspaceFile::MAX_ARSIP_PER_FIELD }} berkas historis per jenis berkas —
                            berkas paling lama otomatis tertimpa. Hanya dosen yang dapat menghapus.
                        </p>
                    @endif
                </div>
                <div class="divide-y divide-border">
                        @foreach ($files as $file)
                            @php $canEdit = $file->canBeEditedBy($user); $canDelete = $file->canBeDeletedBy($user); $isArsip = $file->isArchived(); @endphp <div class="px-4 py-3 flex items-start gap-3"> <span
                                    class="text-2xl">{{ $file->icon() }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2"> <a
                                            href="{{ $file->isPdf() ? route("workspace.preview", $file) : route("workspace.download", $file) }}"
                                            class="font-medium hover:text-brand {{ $file->isPdf() ? "" : "" }}"
                                            @if ($file->isPdf()) target="_blank" @endif>
                                            {{ $file->original_name }} </a> <span
                                            class="text-xs text-text-secondary">{{ $file->sizeHuman() }}</span> <span
                                            class="text-xs text-text-secondary">{{ $file->created_at->format("d M") }}</span>
                                    </div>
                                    @if ($file->description)
                                        <p class="text-xs text-text-secondary mt-0.5">"{{ $file->description }}"</p>
                                    @endif
                                    @if ($isArsip)
                                        <p class="text-xs text-accent-orange mt-0.5">
                                            <span class="material-symbols-outlined" style="font-size:13px;vertical-align:-2px">archive</span>
                                            Arsip{{ $file->archive_note ? ' — '.$file->archive_note : '' }}
                                        </p>
                                    @endif
                                    <p class="text-xs text-text-secondary mt-0.5">Diupload
                                        @include("partials.user-link", ["user" => $file->uploader])</p>
                                </div>
                                <div class="flex items-center gap-1 flex-shrink-0">
                                    @if ($file->isPdf())
                                        <a href="{{ route("workspace.preview", $file) }}" target="_blank"
                                            title="Preview"
                                            class="p-1.5 rounded hover:bg-bg-hover hover:bg-bg-hover"><span class="material-symbols-outlined icon-sm text-status-info">visibility</span></a>
                                    @endif <a href="{{ route("workspace.download", $file) }}"
                                        title="Download" class="p-1.5 rounded hover:bg-bg-hover hover:bg-bg-hover"><span class="material-symbols-outlined icon-sm text-accent-orange">download</span></a>
                                    @if ($canEdit)
                                        <button type="button" data-edit="{{ $file->id }}"
                                            data-bab="{{ $file->bab }}" data-desc="{{ $file->description }}"
                                            class="edit-btn p-1.5 rounded hover:bg-bg-hover hover:bg-bg-hover"
                                            title="Edit"><span class="material-symbols-outlined icon-sm text-accent-blue">edit</span></button>
                                    @endif
                                    @if ($canDelete)
                                        <form method="POST" action="{{ route("workspace.destroy", $file) }}"
                                            onsubmit="return confirm({{ $isArsip ? "'Hapus permanen berkas arsip ini? Tindakan ini tidak bisa dibatalkan.'" : "'Hapus file ini?'" }})"> @csrf @method("DELETE")
                                            <button
                                                class="p-1.5 rounded hover:bg-status-danger/10 hover:bg-status-danger/15 text-status-danger"
                                                title="{{ $isArsip ? 'Hapus permanen (arsip)' : 'Hapus' }}"><span class="material-symbols-outlined icon-sm text-status-danger">delete</span></button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <div class="px-4 py-3 border-t border-border">
                @php
                    $usageSvc = app(\App\Services\StorageUsageService::class);
                    $usedLabel = $usageSvc->formatBytes($usedBytes ?? 0);
                    $quotaLabel = $quotaBytes > 0 ? $usageSvc->formatBytes($quotaBytes) : 'Tak terbatas';
                    $quotaText = $quotaBytes > 0 ? $usedLabel.' / '.$quotaLabel : $usedLabel.' — tidak ada kuota';
                    $pct = $quotaBytes > 0 ? min(100, round(($usedBytes ?? 0) / $quotaBytes * 100)) : 0;
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-text-secondary">
                    <span>File di workspace ini: <span class="font-medium text-text-primary">{{ number_format($totalBytes / 1048576, 1) }} MB</span></span>
                    <span>Pemakaian kuota dosen: <span class="font-medium text-text-primary">{{ $quotaText }}</span></span>
                </div>
                @if ($quotaBytes > 0)
                    <div class="mt-1 h-1.5 rounded-full bg-bg-panel overflow-hidden">
                        <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-status-danger' : ($pct >= 70 ? 'bg-status-pending' : 'bg-brand') }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <p class="text-[10px] text-text-secondary mt-0.5">{{ $pct }}% kuota terpakai</p>
                @endif
            </div>
        @endif
</div> {{-- Modal edit metadata --}}
<div id="edit-modal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-bg-surface rounded-lg border border-border p-4 w-full max-w-md">
        <h3 class="font-semibold mb-3">Edit Metadata File</h3>
        <form method="POST" action="" id="edit-form"> @csrf @method("PATCH") <div class="space-y-3">
                <div> <label class="block text-sm font-medium mb-1">Label Bab</label> <input type="text"
                        name="bab" id="edit-bab"
                        class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm"> </div>
                <div> <label class="block text-sm font-medium mb-1">Catatan</label>
                    <textarea name="description" id="edit-desc" rows="3"
                        class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-4"> <button type="button" id="edit-cancel"
                    class="px-3 py-2 rounded-xl bg-status-danger hover:bg-status-danger/90 text-white text-sm">Batal</button> <button
                    class="px-3 py-2 rounded-xl bg-brand text-[#0b1420] text-sm">Simpan</button> </div>
        </form>
    </div>
</div>
@endsection @section("scripts")
<script>
    // ---------- drag & drop upload ----------
    (function () {
        var zone = document.getElementById('drop-zone');
        var input = document.getElementById('file-input');
        var list = document.getElementById('file-list');
        var btn = document.getElementById('upload-btn');
        var selectedFiles = [];

        function render() {
            list.innerHTML = '';
            selectedFiles.forEach(function (f, i) {
                var div = document.createElement('div');
                div.className = 'flex items-center justify-between text-sm px-2 py-1 rounded bg-bg-panel';
                div.innerHTML = '<span class="truncate mr-2">' + f.name + ' (' + (f.size/1048576).toFixed(1) + ' MB)</span>' + '<button type="button" data-i="' + i + '" class="text-status-danger text-xs">hapus</button>';
                list.appendChild(div);
            });
            btn.disabled = selectedFiles.length === 0;
        }

        zone.addEventListener('click', function () {
            input.click();
        });
        input.addEventListener('change', function () {
            selectedFiles = Array.from(input.files);
            render();
        });
        ['dragover','dragenter'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                zone.classList.add('border-brand/30');
            });
        });
        ['dragleave','drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                zone.classList.remove('border-brand/30');
            });
        });
        zone.addEventListener('drop', function (e) {
            selectedFiles = Array.from(e.dataTransfer.files);
            render();
        });
        list.addEventListener('click', function (e) {
            if (e.target.tagName === 'BUTTON') {
                var i = parseInt(e.target.dataset.i, 10);
                selectedFiles.splice(i, 1);
                render();
            }
        });

        // upload dengan progress (FormData, tanpa library)
        document.getElementById('upload-form').addEventListener('submit', function (e) {
            if (selectedFiles.length === 0) {
                e.preventDefault();
                return;
            }
            e.preventDefault();
            // Cegah double-submit (double-click / tekan Enter berulang).
            if (this.dataset.uploading === '1') {
                return;
            }
            this.dataset.uploading = '1';
            var submitBtn = document.getElementById('upload-btn');
            if (submitBtn) submitBtn.disabled = true;

            // Bangun FormData manual agar file tidak dikirim dobel.
            // PENTING: jangan `new FormData(this)` lalu append files[] lagi,
            // karena <input type="file" name="files[]"> ikut ter-serialize
            // sehingga setiap file terkirim dua kali (double upload).
            var fd = new FormData();
            fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            fd.append('bab', this.querySelector('input[name="bab"]')?.value || '');
            selectedFiles.forEach(function (f) {
                fd.append('files[]', f);
            });
            var xhr = new XMLHttpRequest();
            document.getElementById('progress-wrap').classList.remove('hidden');
            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable) {
                    var pct = Math.round(ev.loaded / ev.total * 100);
                    document.getElementById('progress-bar').style.width = pct + '%';
                    document.getElementById('progress-text').textContent = pct + '%';
                }
            };
            xhr.onload = function () {
                window.location.reload();
            };
            xhr.onerror = function () {
                alert('Upload gagal.');
                document.getElementById('progress-wrap').classList.add('hidden');
                if (submitBtn) submitBtn.disabled = false;
                document.getElementById('upload-form').dataset.uploading = '0';
            };
            xhr.open('POST', this.action);
            xhr.send(fd);
        });
    })();

    // ---------- edit metadata modal ----------
    (function () {
        var modal = document.getElementById('edit-modal');
        var form = document.getElementById('edit-form');
        document.querySelectorAll('.edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.edit;
                form.action = '/workspace/files/' + id;
                document.getElementById('edit-bab').value = btn.dataset.bab || '';
                document.getElementById('edit-desc').value = btn.dataset.desc || '';
                modal.classList.remove('hidden');
            });
        });
        document.getElementById('edit-cancel').addEventListener('click', function () {
            modal.classList.add('hidden');
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.classList.add('hidden');
        });
    })();
</script>
@endsection