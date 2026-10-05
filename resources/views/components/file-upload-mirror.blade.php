{{--
    Cermin status lampiran untuk panel konteks.
    Tidak memiliki <input type="file"> sendiri — hanya membaca input milik
    kontrol unggah utama sehingga state file tetap tunggal.
--}}
@props([
    'input' => 'lampiran',
    'emptyText' => 'Belum ada lampiran',
    'hint' => '',
    'actions' => true,
])

<div class="form-workspace-card space-y-3" data-upload-mirror="{{ $input }}">
    <div class="flex items-center justify-between gap-3">
        <span class="badge badge-neutral" data-mirror-badge>Belum diunggah</span>
        @if ($actions)
            <button type="button" class="hidden text-xs text-status-danger hover:underline"
                data-upload-clear="{{ $input }}">Hapus</button>
        @endif
    </div>
    <div class="min-w-0">
        <p class="hidden break-all text-sm font-medium text-text-primary" data-mirror-name></p>
        <p class="hidden text-caption text-text-secondary" data-mirror-size></p>
        <p class="text-sm text-text-secondary" data-mirror-empty>{{ $emptyText }}</p>
        @if ($hint)
            <p class="text-caption text-text-secondary">{{ $hint }}</p>
        @endif
    </div>
    @if ($actions)
        <button type="button" class="btn-secondary inline-flex w-full items-center justify-center gap-2 px-3 py-2 text-sm"
            data-upload-pick="{{ $input }}">
            <span class="material-symbols-outlined icon-sm" aria-hidden="true">upload</span> Pilih File
        </button>
    @endif
</div>
