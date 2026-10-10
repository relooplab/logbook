{{--
    Kartu status penyimpanan otomatis (dipakai halaman logbook & revisi).
    Presentasi saja: logika autosave tetap di masing-masing halaman, nilai
    yang ditampilkan berasal dari hasil operasi localStorage yang sama.
--}}
@props([
    'panel' => 'autosave',
])

<div class="card form-workspace-card p-5" data-autosave-panel="{{ $panel }}">
    <div class="mt-0" role="status" aria-live="polite">
        <p class="text-sm font-medium text-text-secondary" data-autosave-state>Tersimpan otomatis di browser ini</p>
        <p class="text-caption text-text-secondary" data-autosave-time></p>
        <p class="text-caption text-text-secondary">Hanya di perangkat ini — file tidak ikut.</p>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" class="btn-ghost hidden px-3 py-1.5 text-xs" data-autosave-retry>Coba lagi</button>
        <button type="button" class="btn-secondary hidden px-3 py-1.5 text-xs" data-autosave-restore>Pulihkan</button>
        <button type="button" class="hidden px-1 py-1.5 text-xs text-status-danger hover:underline" data-autosave-discard>Buang</button>
    </div>
</div>
