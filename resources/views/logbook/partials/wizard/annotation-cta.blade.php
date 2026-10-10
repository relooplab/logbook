{{-- Blok CTA anotasi PDF bersama (langkah 3). --}}
{{-- Props: $openLabel ('Buka PDF & tandai' | 'Buka PDF'), $copyLabel ('Salin'), --}}
{{-- $hint ('Draf tersimpan otomatis ...' | 'Draf ... tersimpan ...'), --}}
{{-- $copyAttr ('data-salin' | 'data-isi-otomatis'), $countAttr, $msgAttr, --}}
{{-- $guide (array 3 string langkah cepat). --}}
{{-- Struktur/class identik; hanya copy + atribut hook yang beda per halaman. --}}
<div class="mt-4 rounded-xl border border-dashed border-brand/40 bg-brand/5 p-4" data-anotasi-cta>
    <ol class="flex flex-wrap items-center gap-2 text-xs text-text-secondary" aria-label="Cara cepat anotasi">
        <li><span class="font-semibold text-text-primary">1.</span> {!! $guide[0] !!}</li>
        <li aria-hidden="true">→</li>
        <li><span class="font-semibold text-text-primary">2.</span> {!! $guide[1] !!}</li>
        <li aria-hidden="true">→</li>
        <li><span class="font-semibold text-text-primary">3.</span> {!! $guide[2] !!}</li>
    </ol>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" class="btn-primary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium disabled:opacity-50" data-anotasi-open disabled>
            <span class="material-symbols-outlined icon-sm" aria-hidden="true">open_in_new</span> {{ $openLabel }}
        </button>
        <button type="button" class="btn-secondary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-medium disabled:opacity-50" {!! $copyAttr !!} disabled>
            <span class="material-symbols-outlined icon-sm" aria-hidden="true">bolt</span> {{ $copyLabel }} <span {!! $countAttr !!}></span>
        </button>
    </div>
    <p class="mt-2 text-xs text-text-secondary" data-anotasi-hint>{{ $hint }}</p>
    <p class="mt-2 hidden text-xs" role="status" aria-live="polite" {!! $msgAttr !!}></p>
</div>
