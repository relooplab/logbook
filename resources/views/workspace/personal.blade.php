@extends('layouts.app')

@section('title', 'Workspace Saya')

@section('content')
@php
    $user = auth()->user();
    $allFiles = $grouped->flatten(1);
    $babs = $allFiles->pluck('bab')->filter()->unique();
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">Workspace Saya</h1>
            <p class="text-sm text-text-secondary mt-0.5">File pribadi milik Anda</p>
        </div>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('workspace.personal') }}" class="card p-4 flex flex-wrap gap-3 items-end">
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Bab</label>
            <select name="bab" class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
                <option value="">Semua Bab</option>
                @foreach ($babs as $b)
                    <option value="{{ $b }}" @selected(request('bab') === $b)>{{ $b }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Cari</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama file / catatan"
                class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
        </div>
        <div class="flex gap-2 w-full sm:w-auto">
            <button type="submit" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Cari</button>
            <a href="{{ route('workspace.personal') }}" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border text-center">Reset</a>
        </div>
    </form>

    {{-- Upload --}}
    <div class="card p-6">
        <h2 class="font-heading font-semibold text-text-primary mb-3">Upload File</h2>
        <form method="POST" action="{{ route('workspace.personal-store') }}" enctype="multipart/form-data" id="upload-form">
            @csrf
            <div class="grid sm:grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs text-text-secondary mb-1">Label Bab (opsional)</label>
                    <input type="text" name="bab" placeholder="contoh: Materi, Jurnal, Arsip"
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

    {{-- Daftar file --}}
    @if ($allFiles->isEmpty())
        <div class="px-4 py-10 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
            <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">folder_off</span>
            <p>Belum ada file di workspace pribadi Anda.</p>
        </div>
    @else
        @foreach ($grouped as $bab => $files)
            <div class="card p-0 overflow-hidden">
                <div class="px-4 py-3 bg-bg-panel/50 border-b border-border font-semibold text-sm text-text-primary">
                    {{ $bab }}</div>
                <div class="divide-y divide-border">
                    @foreach ($files as $file)
                        <div class="px-4 py-3 flex items-start gap-3">
                            <span class="text-2xl">{{ $file->icon() }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ $file->isPdf() ? route('workspace.preview', $file) : route('workspace.download', $file) }}"
                                        class="font-medium hover:text-brand" @if ($file->isPdf()) target="_blank" @endif>
                                        {{ $file->original_name }}
                                    </a>
                                    <span class="text-xs text-text-secondary">{{ $file->sizeHuman() }}</span>
                                    <span class="text-xs text-text-secondary">{{ $file->created_at->format('d M') }}</span>
                                </div>
                                @if ($file->description)
                                    <p class="text-xs text-text-secondary mt-0.5">"{{ $file->description }}"</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-1 flex-shrink-0">
                                @if ($file->isPdf())
                                    <a href="{{ route('workspace.preview', $file) }}" target="_blank" title="Preview"
                                        class="p-1.5 rounded hover:bg-bg-hover"><span class="material-symbols-outlined icon-sm text-status-info">visibility</span></a>
                                @endif
                                <a href="{{ route('workspace.download', $file) }}" title="Download"
                                    class="p-1.5 rounded hover:bg-bg-hover"><span class="material-symbols-outlined icon-sm text-accent-orange">download</span></a>
                                <form method="POST" action="{{ route('workspace.destroy', $file) }}"
                                    onsubmit="return confirm('Hapus file ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="p-1.5 rounded hover:bg-status-danger/10 text-status-danger" title="Hapus">
                                        <span class="material-symbols-outlined icon-sm text-status-danger">delete</span>
                                    </button>
                                </form>
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
                <span>Pemakaian kuota: <span class="font-medium text-text-primary">{{ $quotaText }}</span></span>
            </div>
            @if ($quotaBytes > 0)
                <div class="mt-1 h-1.5 rounded-full bg-bg-panel overflow-hidden">
                    <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-status-danger' : ($pct >= 70 ? 'bg-status-pending' : 'bg-brand') }}" style="width: {{ $pct }}%"></div>
                </div>
                <p class="text-[10px] text-text-secondary mt-0.5">{{ $pct }}% kuota terpakai</p>
            @endif
        </div>
    @endif
</div>
@endsection

@section('scripts')
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

        zone.addEventListener('click', function () { input.click(); });
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

            // Bangun FormData manual agar file tidak terkirim dobel
            // (jangan pakai `new FormData(this)` lalu append files[] lagi).
            var fd = new FormData();
            fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            fd.append('bab', this.querySelector('input[name="bab"]')?.value || '');
            selectedFiles.forEach(function (f) { fd.append('files[]', f); });
            var xhr = new XMLHttpRequest();
            document.getElementById('progress-wrap').classList.remove('hidden');
            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable) {
                    var pct = Math.round(ev.loaded / ev.total * 100);
                    document.getElementById('progress-bar').style.width = pct + '%';
                    document.getElementById('progress-text').textContent = pct + '%';
                }
            };
            xhr.onload = function () { window.location.reload(); };
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
</script>
@endsection