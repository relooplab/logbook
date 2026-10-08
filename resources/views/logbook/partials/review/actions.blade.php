@php $canManageActionItems = auth()->user()->can('manageActionItems', $logbook); @endphp
@if ($canManageActionItems)
    <section class="card p-5 space-y-3 detail-workspace-card" aria-label="Action items">
        <div class="flex items-center justify-between gap-2"><h2 class="font-heading font-semibold text-text-primary">Action Items</h2><button type="button" id="action-item-add-toggle" class="px-3 py-2 rounded-xl border border-border text-sm hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" aria-expanded="false" aria-controls="action-item-add-form">+ Tambah</button></div>
        <div id="action-items-list" class="space-y-3">
            @forelse ($logbook->actionItems as $item)
                <div class="flex items-start gap-2 action-item-row" data-item-id="{{ $item->id }}">
                    @if (auth()->user()->can('update', $logbook))
                        <input type="checkbox" class="action-item-toggle rounded bg-bg-surface mt-1" aria-label="Tandai selesai: {{ $item->text }}" @checked($item->is_done)>
                    @else
                        <span class="material-symbols-outlined icon-sm {{ $item->is_done ? '' : 'text-text-secondary' }}">{{ $item->is_done ? 'check_circle' : 'radio_button_unchecked' }}</span>
                    @endif
                    <span class="flex-1 min-w-0 text-sm {{ $item->is_done ? 'line-through text-text-secondary' : '' }}">{{ $item->text }}</span>
                    <button type="button" class="action-item-delete text-status-danger hover:underline text-xs shrink-0">Hapus</button>
                </div>
            @empty
                <p class="text-sm text-text-secondary">Belum ada action item.</p>
            @endempty
        </div>
        <form id="action-item-add-form" class="hidden space-y-2">
            @csrf
            <label for="action-item-text" class="sr-only">Tambah action item</label>
            <input id="action-item-text" type="text" name="text" placeholder="Tambah action item..." maxlength="500" required
                class="w-full min-w-0 rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
            <p id="action-item-error" class="hidden text-status-danger text-xs" role="alert"></p>
            <div class="flex gap-2"><button type="submit" class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Tambah</button><button type="button" id="action-item-add-cancel" class="px-4 py-2 rounded-xl bg-bg-hover text-sm hover:bg-border">Batal</button></div>
        </form>
    </section>
@endif
