@extends('layouts.app')

@section('title', 'Workspace')

@section('content')
@php
    $isDosen = $user->isDosen();
    $isAdmin = $user->isAdmin();
    $allPersonalFiles = $personalFiles ?? collect();
    $personalBabs = $allPersonalFiles->pluck('bab')->filter()->unique();
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">Workspace</h1>
            <p class="text-sm text-text-secondary mt-0.5">
                @if ($isDosen)
                    File pribadi & workspace bimbingan Anda
                @elseif ($isAdmin)
                    Semua workspace TA/KP
                @else
                    Workspace program Anda
                @endif
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
    </div>

    {{-- ===== Workspace Pribadi (dosen) ===== --}}
    @if ($isDosen)
        <div class="card p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-heading font-semibold text-text-primary">Workspace Pribadi</h2>
                    <p class="text-sm text-text-secondary">File pribadi milik Anda — hanya Anda yang bisa melihat</p>
                </div>
                @php
                    $usageSvc = app(\App\Services\StorageUsageService::class);
                    $personalUsedLabel = $usageSvc->formatBytes($personalTotalBytes);
                    $personalQuotaLabel = $personalQuotaBytes > 0 ? $usageSvc->formatBytes($personalQuotaBytes) : 'Tak terbatas';
                    $personalQuotaText = $personalQuotaBytes > 0 ? $personalUsedLabel.' / '.$personalQuotaLabel : $personalUsedLabel.' — tidak ada kuota terdefinisi';
                @endphp
                <span class="text-xs text-text-secondary">Pemakaian: <span class="font-semibold text-text-primary">{{ $personalQuotaText }}</span></span>
            </div>

            @if ($personalQuotaBytes > 0)
                <div class="mb-4">
                    <div class="h-1.5 rounded-full bg-bg-panel overflow-hidden">
                        <div class="h-full rounded-full {{ $personalPct >= 90 ? 'bg-status-danger' : ($personalPct >= 70 ? 'bg-status-pending' : 'bg-brand') }}" style="width: {{ $personalPct }}%"></div>
                    </div>
                    <p class="text-[10px] text-text-secondary mt-0.5">{{ $personalPct }}% kuota workspace pribadi terpakai</p>
                </div>
            @endif

            {{-- Upload (drag & drop, sama seperti mahasiswa) --}}
            <form method="POST" action="{{ route('workspace.personal-store') }}" enctype="multipart/form-data" id="personal-upload-form" class="mb-4">
                @csrf
                <div class="grid sm:grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-xs text-text-secondary mb-1">Label Bab (opsional)</label>
                        <input type="text" name="bab" id="personal-bab" placeholder="contoh: Materi, Jurnal, Arsip"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
                    </div>
                </div>
                <div id="personal-drop-zone"
                    class="border-2 border-dashed border-border rounded-xl p-8 text-center cursor-pointer hover:border-brand/30 transition">
                    <p><span class="material-symbols-outlined" style="font-size:48px">folder_open</span></p>
                    <p class="text-sm mt-2">Tarik & lepas file di sini, atau <span class="text-brand font-medium">klik untuk memilih</span></p>
                    <input type="file" name="files[]" id="personal-file-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx" class="hidden">
                </div>
                <div id="personal-file-list" class="mt-3 space-y-1"></div>
                <div id="personal-progress-wrap" class="hidden mt-3">
                    <div class="h-2 rounded-full bg-bg-panel overflow-hidden">
                        <div id="personal-progress-bar" class="h-full rounded-full bg-brand" style="width:0%"></div>
                    </div>
                    <p id="personal-progress-text" class="text-xs text-text-secondary mt-1">0%</p>
                </div>
                <button type="submit" id="personal-upload-btn" disabled
                    class="mt-3 px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90 disabled:opacity-40">Upload</button>
                @error('files.*')
                    <p class="text-status-danger text-xs mt-1">{{ $message }}</p>
                @enderror
            </form>

            {{-- Daftar file pribadi --}}
            <div class="flex items-center justify-between mb-2">
                <h3 class="font-heading font-semibold text-text-primary">File Pribadi</h3>
                @if ($allPersonalFiles->isNotEmpty())
                    <label class="flex items-center gap-2 text-xs text-text-secondary">
                        Urutkan
                        <select id="personal-sort" class="rounded-lg border border-border bg-bg-surface px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-brand/40">
                            <option value="time-desc">Terbaru</option>
                            <option value="time-asc">Terlama</option>
                            <option value="type">Tipe file</option>
                        </select>
                    </label>
                @endif
            </div>
            @if ($allPersonalFiles->isEmpty())
                <div class="px-4 py-8 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
                    <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">folder_off</span>
                    <p>Belum ada file di workspace pribadi Anda.</p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($personalGrouped as $bab => $files)
                        <div class="card p-0 overflow-hidden">
                            <div class="px-4 py-2.5 bg-bg-panel/50 border-b border-border font-semibold text-sm text-text-primary">
                                {{ $bab }}</div>
                            <div class="divide-y divide-border file-group-list">
                                @foreach ($files as $file)
                                    <div class="px-4 py-2.5 flex items-start gap-3" data-time="{{ $file->created_at->timestamp }}" data-type="{{ strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)) }}">
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
                </div>
            @endif
        </div>
    @endif

    {{-- ===== Direktori bimbingan (dosen); daftar admin tetap seperti semula ===== --}}
    @if ($isDosen)
        @include('workspace.partials.supervision-directory')
    @else
    {{-- ===== Daftar Workspace TA/KP (admin) ===== --}}
    <div class="card p-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <h2 class="font-heading font-semibold text-text-primary">
                    {{ $isDosen ? 'Workspace Bimbingan' : 'Semua Workspace' }}
                </h2>
                <p class="text-sm text-text-secondary">
                    {{ $isDosen ? 'TA/KP di mana Anda menjadi pembimbing atau penguji' : 'Semua program TA/KP di sistem' }}
                </p>
            </div>
            <span class="text-xs text-text-secondary">{{ $tas->count() }} program</span>
        </div>

        @if ($tas->isEmpty())
            <div class="px-4 py-8 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
                <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">folder_off</span>
                <p>{{ $isDosen ? 'Belum ada mahasiswa bimbingan.' : 'Belum ada program TA/KP.' }}</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($tas as $ta)
                    <a href="{{ route('workspace.index', $ta) }}" class="flex items-center gap-3 p-3 rounded-xl bg-bg-panel border border-border hover:border-brand/30 transition-colors">
                        <span class="icon-circle w-10 h-10 bg-brand-light text-brand">
                            <span class="material-symbols-outlined icon-md text-accent-teal">folder</span>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-text-primary">{{ $ta->mahasiswa?->name }} <span class="text-xs text-text-secondary">({{ $ta->jenisLabel() }})</span></p>
                            <p class="text-xs text-text-secondary truncate">{{ $ta->isKp() ? ($ta->tempat_kp ?: '—') : $ta->judul_ta }}</p>
                        </div>
                        <span class="text-brand text-xs font-medium">Buka →</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
    // ---------- drag & drop upload (workspace pribadi dosen) ----------
    (function () {
        var zone = document.getElementById('personal-drop-zone');
        var input = document.getElementById('personal-file-input');
        var list = document.getElementById('personal-file-list');
        var btn = document.getElementById('personal-upload-btn');
        var form = document.getElementById('personal-upload-form');
        var babInput = document.getElementById('personal-bab');
        var selectedFiles = [];

        if (!zone || !input || !list || !btn || !form) return;

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

        form.addEventListener('submit', function (e) {
            if (selectedFiles.length === 0) {
                e.preventDefault();
                return;
            }
            e.preventDefault();
            var fd = new FormData();
            fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            if (babInput) fd.append('bab', babInput.value);
            selectedFiles.forEach(function (f) { fd.append('files[]', f); });
            var xhr = new XMLHttpRequest();
            document.getElementById('personal-progress-wrap').classList.remove('hidden');
            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable) {
                    var pct = Math.round(ev.loaded / ev.total * 100);
                    document.getElementById('personal-progress-bar').style.width = pct + '%';
                    document.getElementById('personal-progress-text').textContent = pct + '%';
                }
            };
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    window.location.reload();
                } else {
                    alert('Upload gagal. Periksa ukuran/format file atau kuota Anda.');
                    document.getElementById('personal-progress-wrap').classList.add('hidden');
                }
            };
            xhr.onerror = function () {
                alert('Upload gagal.');
                document.getElementById('personal-progress-wrap').classList.add('hidden');
            };
            xhr.open('POST', form.action);
            xhr.send(fd);
        });
    })();
    // ---- Sortir file pribadi (waktu / tipe) ----
    (function () {
        var sel = document.getElementById('personal-sort');
        if (!sel) return;
        sel.addEventListener('change', function () {
            var sort = sel.value;
            document.querySelectorAll('.file-group-list').forEach(function (list) {
                var rows = Array.prototype.slice.call(list.children);
                rows.sort(function (a, b) {
                    var ta = Number(a.dataset.time) || 0;
                    var tb = Number(b.dataset.time) || 0;
                    if (sort === 'time-asc') return ta - tb;
                    if (sort === 'time-desc') return tb - ta;
                    var xa = (a.dataset.type || '').toLowerCase();
                    var xb = (b.dataset.type || '').toLowerCase();
                    return xa.localeCompare(xb) || (tb - ta);
                });
                rows.forEach(function (r) { list.appendChild(r); });
            });
        });
    })();
</script>
@endsection