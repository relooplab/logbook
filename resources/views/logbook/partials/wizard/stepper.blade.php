{{-- Stepper wizard bersama (create induk & create-revisi follow-up). --}}
{{-- Props: $steps ([num => [label, icon, desc]]), $ariaLabel. --}}
{{-- Markup/class disamakan agar kedua halaman identik; hanya isi $steps yang beda. --}}
<div class="mb-5">
    <ol class="stepper" aria-label="{{ $ariaLabel }}">
        @foreach ($steps as $num => [$label, $icon, $desc])
            <li class="stepper-item">
                <button type="button" class="stepper-button wizard-step" data-step="{{ $num }}"
                    data-state="{{ $num === 1 ? 'active' : 'upcoming' }}"
                    @if ($num === 1) aria-current="step" @endif>
                    <span class="stepper-marker" aria-hidden="true">
                        <span class="stepper-number">{{ $num }}</span>
                        <span class="stepper-check material-symbols-outlined icon-sm">check</span>
                    </span>
                    <span class="stepper-text">
                        <span class="stepper-label">{{ $num }}. {{ $label }}</span>
                        <span class="stepper-desc">{{ $desc }}</span>
                    </span>
                    <span class="sr-only" data-step-status="{{ $num }}"></span>
                </button>
                @if ($num < count($steps))
                    <span class="stepper-line" data-stepper-line="{{ $num }}" aria-hidden="true"></span>
                @endif
            </li>
        @endforeach
    </ol>
    <p class="sr-only" aria-live="polite" data-step-announcement></p>
</div>
