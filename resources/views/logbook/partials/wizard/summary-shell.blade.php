{{-- Cangkang panel ringkasan konteks (aside) bersama. --}}
{{-- Props: $panelTitle, $panelDesc, $panelIcon ('description'|'summarize'), --}}
{{-- $ariaLabel, $steps (untuk progress segments), $draftSave (bool: tampilkan --}}
{{-- tombol Simpan Draf ke server + baris status), $draftSavedAt (?string: --}}
{{-- stempel simpanan server terakhir, mis. diffForHumans), $combinedStatus --}}
{{-- (?string: kalimat status gabungan), $workflowLabel (?string: label alur --}}
{{-- untuk kalimat gabungan live). Autosave browser senyap dan tidak mengisi --}}
{{-- stempel ini. --}}
{{-- Isi baris summary di-supply via $slot agar hook data-* per halaman tetap utuh. --}}
<aside class="form-workspace-panel" aria-label="{{ $ariaLabel }}">
    <section class="card form-workspace-card p-5">
        <div class="form-card-head">
            <span class="icon-chip h-10 w-10" aria-hidden="true">
                <span class="material-symbols-outlined icon-md text-brand">{{ $panelIcon }}</span>
            </span>
            <div class="min-w-0">
                <h2 class="font-heading font-semibold text-text-primary">{{ $panelTitle }}</h2>
                <p class="text-caption text-text-secondary">{{ $panelDesc }}</p>
            </div>
        </div>

        <div class="mt-4">
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs text-text-secondary">Kemajuan</span>
                <span class="text-xs font-medium text-text-primary" data-progress-label>0 dari 4 langkah</span>
            </div>
            <div class="progress-segments mt-2" aria-hidden="true">
                @foreach ($steps as $num => [$label, $icon, $desc])
                    <span class="progress-segment" data-progress-segment="{{ $num }}"></span>
                @endforeach
            </div>
        </div>

        @if (!empty($combinedStatus))
            <p class="mt-3 rounded-lg bg-bg-panel px-3 py-2 text-xs text-text-secondary" data-status-combined
                data-workflow="{{ $workflowLabel ?? 'Draf' }}" role="status" aria-live="polite">{{ $combinedStatus }}</p>
        @endif

        <div class="mt-4">
            {{ $slot }}
        </div>

        {!! $after ?? '' !!}

        @if (!empty($draftSave))
            <div class="mt-4 border-t border-border pt-4">
                <button type="submit" data-draft-save
                    class="btn-secondary inline-flex min-h-11 w-full items-center justify-center gap-2 px-4 py-2 text-sm font-medium">
                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">save</span> Simpan Draf
                </button>
                @if (!empty($draftSavedAt))
                    <p class="mt-1 text-[11px] text-text-secondary" data-draf-saved-at>Terakhir disimpan {{ $draftSavedAt }}</p>
                @else
                    <p class="mt-1 hidden text-[11px] text-text-secondary" data-draf-saved-at></p>
                @endif
                <p class="mt-2 hidden text-xs" data-draft-status role="status" aria-live="polite"></p>
            </div>
        @endif
    </section>
</aside>
