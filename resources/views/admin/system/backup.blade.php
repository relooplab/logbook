@extends('layouts.app')

@section('title', 'Backup & Restore Sistem')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">Backup & Restore Sistem</h1>
            <p class="text-sm text-text-secondary mt-0.5">Backup seluruh sistem (database + file) atau sebagian modul saja, dan restore dari backup sebelumnya.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
    </div>

    {{-- ===== Backup ===== --}}
    <div class="card p-6">
        <h2 class="font-heading font-semibold text-text-primary mb-1">Backup Sekarang</h2>
        <p class="text-sm text-text-secondary mb-4">
            Centang modul dan/atau institusi tertentu untuk backup parsial (untuk keperluan arsip/migrasi), atau
            kosongkan semua checkbox untuk backup penuh seluruh sistem. Kalau sebuah modul punya ketergantungan ke
            modul lain (mis. Logbook &amp; Bimbingan butuh Data Mahasiswa/TA + Pengguna), modul dependensinya
            otomatis ikut tercentang &amp; terkunci — supaya data yang ter-backup tidak kehilangan rujukannya.
            Kalau memfilter institusi, user lain yang direferensikan (mis. dosen pembimbing/penguji lintas
            institusi) otomatis ikut ter-backup juga — dicatat di manifest hasil backup.
        </p>

        <div class="mb-4 px-4 py-3 rounded-xl bg-status-warning/10 text-status-warning border border-status-warning/20 flex items-start gap-2.5 text-sm">
            <span class="material-symbols-outlined icon-md mt-0.5 text-status-danger">warning</span>
            <span>
                File ZIP hasil backup berisi kredensial SMTP institusi dalam bentuk plaintext (password email).
                Simpan &amp; kirim file ini hanya lewat jalur yang aman.
            </span>
        </div>

        <form method="POST" action="{{ route('admin.system.backup.store') }}" id="backup-form">
            @csrf
            <h3 class="text-sm font-semibold text-text-primary mb-2">Modul</h3>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($modules as $key => $module)
                    <label class="flex items-start gap-2 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                        <input type="checkbox"
                            name="modules[]"
                            value="{{ $key }}"
                            class="module-checkbox rounded bg-bg-surface border-border mt-0.5"
                            data-depends-on='@json($module['depends_on'])'>
                        <span>
                            <span class="block font-medium text-text-primary">{{ $module['label'] }}</span>
                            <span class="block text-text-secondary text-xs mt-0.5">{{ $module['description'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($institutions->isNotEmpty())
                <h3 class="text-sm font-semibold text-text-primary mt-5 mb-2">Institusi (opsional)</h3>
                <p class="text-xs text-text-secondary mb-2">Kosongkan semua = semua institusi ikut (tidak difilter).</p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($institutions as $institution)
                        <label class="flex items-start gap-2 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                            <input type="checkbox" name="institutions[]" value="{{ $institution->id }}"
                                class="rounded bg-bg-surface border-border mt-0.5">
                            <span class="font-medium text-text-primary">{{ $institution->institution_name }}</span>
                        </label>
                    @endforeach
                    <label class="flex items-start gap-2 rounded-xl border border-border bg-bg-panel p-3 text-sm cursor-pointer">
                        <input type="checkbox" name="include_individual" value="1"
                            class="rounded bg-bg-surface border-border mt-0.5">
                        <span class="font-medium text-text-primary">Sertakan data individual (tanpa institusi)</span>
                    </label>
                </div>
            @endif

            <div class="mt-4">
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Backup Sekarang</button>
            </div>
        </form>
    </div>

    {{-- ===== Restore ===== --}}
    <div class="card p-6">
        <h2 class="font-heading font-semibold text-text-primary mb-1">Restore dari Backup</h2>
        <p class="text-sm text-text-secondary mb-4">
            Mendukung restore dari backup <strong>penuh</strong> maupun backup <strong>parsial</strong> (per-modul
            dan/atau per-institusi). Restore selalu menerapkan <strong>tepat</strong> scope yang tercatat di dalam
            file backup itu sendiri — kalau mau restore yang lebih sempit, buat backup yang lebih sempit dulu
            (bukan menyeleksi ulang saat restore).
        </p>

        <div class="mb-4 px-4 py-3 rounded-xl bg-status-danger/10 text-status-danger border border-status-danger/20 flex items-start gap-2.5 text-sm">
            <span class="material-symbols-outlined icon-md mt-0.5 text-status-danger">dangerous</span>
            <span>
                Restore <strong>penuh</strong> menghapus SELURUH data saat ini lalu menggantinya dengan isi backup.
                Restore <strong>parsial</strong> hanya mengganti baris-baris yang persis ada di dalam backup
                (baris lain yang tidak ada di backup, termasuk yang baru dibuat setelah backup diambil, tidak
                disentuh). Kedua jenis restore destruktif &amp; tidak bisa dibatalkan untuk data yang diganti.
                Sebuah safety-backup penuh otomatis akan dibuat lebih dulu sebelum proses restore berjalan —
                path-nya akan dicatat di log &amp; ditampilkan setelah selesai (tidak ditawarkan untuk didownload
                di versi ini).
            </span>
        </div>

        <form method="POST" action="{{ route('admin.system.backup.restore') }}" enctype="multipart/form-data" id="restore-form">
            @csrf
            <div class="space-y-3 max-w-lg">
                <div>
                    <label class="block text-xs text-text-secondary mb-1">File Backup (.zip)</label>
                    <input type="file" name="backup_file" accept=".zip" required
                        class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-text-secondary mb-1">
                        Ketik ulang <code class="px-1 py-0.5 rounded bg-bg-hover">{{ \App\Http\Controllers\Admin\SystemBackupController::CONFIRMATION_PHRASE }}</code> untuk konfirmasi
                    </label>
                    <input type="text" name="confirmation" id="restore-confirmation" autocomplete="off"
                        class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" id="restore-submit" disabled
                    class="px-4 py-2 rounded-xl bg-status-danger text-white text-sm font-medium opacity-50 cursor-not-allowed">
                    Restore &amp; Hapus Semua Data Saat Ini
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ---- Dependency lock untuk checklist modul backup ----
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.module-checkbox'));
    var byKey = {};
    checkboxes.forEach(function (cb) { byKey[cb.value] = cb; });

    function recomputeLocks() {
        checkboxes.forEach(function (cb) { cb.disabled = false; });

        var required = {};
        function addDeps(key) {
            var cb = byKey[key];
            if (!cb) return;
            var deps = JSON.parse(cb.dataset.dependsOn || '[]');
            deps.forEach(function (dep) {
                if (!required[dep]) {
                    required[dep] = true;
                    addDeps(dep);
                }
            });
        }
        checkboxes.forEach(function (cb) { if (cb.checked) addDeps(cb.value); });

        Object.keys(required).forEach(function (key) {
            var cb = byKey[key];
            if (cb) {
                cb.checked = true;
                cb.disabled = true;
            }
        });
    }

    checkboxes.forEach(function (cb) { cb.addEventListener('change', recomputeLocks); });
    recomputeLocks();

    // ---- Ketik-ulang konfirmasi untuk restore ----
    var CONFIRMATION_PHRASE = @json(\App\Http\Controllers\Admin\SystemBackupController::CONFIRMATION_PHRASE);
    var confirmationInput = document.getElementById('restore-confirmation');
    var restoreSubmit = document.getElementById('restore-submit');

    function refreshRestoreButton() {
        var match = confirmationInput.value === CONFIRMATION_PHRASE;
        restoreSubmit.disabled = !match;
        restoreSubmit.classList.toggle('opacity-50', !match);
        restoreSubmit.classList.toggle('cursor-not-allowed', !match);
    }

    confirmationInput.addEventListener('input', refreshRestoreButton);
    refreshRestoreButton();
})();
</script>
@endsection
