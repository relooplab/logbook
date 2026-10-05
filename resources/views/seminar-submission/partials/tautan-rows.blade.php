{{--
    Daftar input tautan dinamis (dipakai create & edit).
    Aturan maks tautan berasal dari controller ($tautanMaxLinks).
--}}
@props(['values' => [''], 'maxLinks' => 10])

<div class="space-y-2" data-tautan-list>
    @foreach ($values as $tautan)
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined icon-sm shrink-0 text-text-secondary" aria-hidden="true">link</span>
            <input type="url" name="tautan[]" value="{{ $tautan }}" placeholder="https://..."
                aria-label="Tautan tambahan"
                class="min-w-0 flex-1 rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
            <button type="button" onclick="removeTautanRow(this)"
                class="btn-ghost shrink-0 px-3 py-2 text-sm hover:bg-border" aria-label="Hapus tautan">
                <span class="material-symbols-outlined icon-sm" aria-hidden="true">close</span>
            </button>
        </div>
    @endforeach
</div>
<button type="button" onclick="addTautanRow()"
    class="btn-secondary mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium">
    <span class="material-symbols-outlined icon-sm" aria-hidden="true">add</span> Tambah tautan
</button>
@error('tautan') <p class="mt-2 text-xs text-status-danger">{{ $message }}</p> @enderror
@error('tautan.*') <p class="mt-2 text-xs text-status-danger">{{ $message }}</p> @enderror

<script>
    function addTautanRow(value) {
        var max = {{ (int) $maxLinks }};
        var lists = document.querySelectorAll('[data-tautan-list]');
        var list = lists[lists.length - 1];
        if (list.querySelectorAll('input[name="tautan[]"]').length >= max) return;
        var row = document.createElement('div');
        row.className = 'flex items-center gap-2';
        row.innerHTML = '<span class="material-symbols-outlined icon-sm shrink-0 text-text-secondary" aria-hidden="true">link</span>'
            + '<input type="url" name="tautan[]" value="" placeholder="https://..." aria-label="Tautan tambahan"'
            + ' class="min-w-0 flex-1 rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">'
            + '<button type="button" onclick="removeTautanRow(this)" class="btn-ghost shrink-0 px-3 py-2 text-sm hover:bg-border" aria-label="Hapus tautan">'
            + '<span class="material-symbols-outlined icon-sm" aria-hidden="true">close</span></button>';
        if (value) row.querySelector('input').value = value;
        list.appendChild(row);
    }
    function removeTautanRow(btn) {
        var lists = document.querySelectorAll('[data-tautan-list]');
        var list = lists[lists.length - 1];
        if (list.querySelectorAll('input[name="tautan[]"]').length <= 1) {
            btn.closest('div').querySelector('input').value = '';
            return;
        }
        btn.closest('div').remove();
    }
</script>
