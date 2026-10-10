{{-- Cangkang panel ringkasan konteks (aside) bersama. --}}
{{-- Props: $panelTitle, $panelDesc, $panelIcon ('description'|'summarize'), --}}
{{-- $ariaLabel, $autosavePanel ('lb-create'|'lb-revisi'), $steps (untuk progress segments). --}}
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

        <div class="mt-4">
            {{ $slot }}
        </div>

        {!! $after ?? '' !!}
    </section>

    <x-autosave-status :panel="$autosavePanel" />
</aside>
