@extends('layouts.app')

@section('title', 'Riwayat Umpan Balik')

@section('content')
<div class="space-y-5">
    <x-page-header subtitle="Bimbingan" title="Riwayat Umpan Balik"
        description="Umpan balik dosen dan alur revisi dalam satu rangkaian." class="mb-1">
        <x-slot:actions>
            <a href="{{ route('logbook.index', array_filter(['program' => $ta?->jenis])) }}"
                class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Kembali ke Logbook</a>
        </x-slot:actions>
    </x-page-header>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="card px-4 py-3">
            <p class="text-2xl font-bold text-text-primary">{{ $summary['total'] }}</p>
            <p class="text-xs text-text-secondary mt-0.5">Total Rangkaian</p>
        </div>
        <div class="card px-4 py-3">
            <p class="text-2xl font-bold text-text-primary">{{ $summary['menunggu'] }}</p>
            <p class="text-xs text-text-secondary mt-0.5">Menunggu Review</p>
        </div>
        <div class="card px-4 py-3">
            <p class="text-2xl font-bold text-text-primary">{{ $summary['diminta'] }}</p>
            <p class="text-xs text-text-secondary mt-0.5">Revisi Diminta</p>
        </div>
        <div class="card px-4 py-3">
            <p class="text-2xl font-bold text-text-primary">{{ $summary['selesai'] }}</p>
            <p class="text-xs text-text-secondary mt-0.5">Selesai</p>
        </div>
    </div>

    @if ($threads->isEmpty())
        <div class="px-4 py-10 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
            <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">forum</span>
            <p>Belum ada riwayat umpan balik.</p>
        </div>
    @else
        {{-- Cari & filter (client-side) --}}
        <div class="card p-4 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-52">
                <label for="thread-search" class="block text-xs text-text-secondary mb-1">Cari topik, feedback, atau dosen</label>
                <input id="thread-search" type="search" placeholder="Ketik kata kunci…"
                    class="w-full rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm text-text-primary focus:outline-none focus:ring-2 focus:ring-brand/40">
            </div>
            <div class="w-full sm:w-auto">
                <label for="thread-status" class="block text-xs text-text-secondary mb-1">Status</label>
                <select id="thread-status"
                    class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm text-text-primary focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="">Semua</option>
                    @foreach (\App\Models\LogbookEntry::STATUS_LABELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-full sm:w-auto">
                <label for="thread-sort" class="block text-xs text-text-secondary mb-1">Urutan</label>
                <select id="thread-sort"
                    class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm text-text-primary focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="desc">Terbaru</option>
                    <option value="asc">Terlama</option>
                </select>
            </div>
        </div>

        <div id="thread-list" class="space-y-4">
            @foreach ($threads as $thread)
                @include('logbook.partials.feedback-thread', ['thread' => $thread + ['expanded' => $thread['actionable'] || $loop->first]])
            @endforeach
        </div>
        <div id="thread-empty" class="hidden px-4 py-10 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
            <p>Tidak ada rangkaian yang cocok dengan pencarian.</p>
        </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
    // ---- Expand/collapse thread ----
    document.querySelectorAll('[data-thread-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = document.getElementById(btn.getAttribute('aria-controls'));
            var chevron = btn.querySelector('[data-thread-chevron]');
            var open = btn.getAttribute('aria-expanded') !== 'true';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (body) body.classList.toggle('hidden', !open);
            if (chevron) chevron.style.transform = open ? '' : 'rotate(-90deg)';
        });
    });

    // ---- Selengkapnya (feedback panjang) ----
    document.querySelectorAll('[data-feedback-more]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap = btn.closest('div');
            var short = wrap.querySelector('[data-feedback-text]');
            var full = wrap.querySelector('[data-feedback-full]');
            var expanded = btn.dataset.expanded === '1';
            if (short) short.classList.toggle('hidden', !expanded);
            if (full) full.classList.toggle('hidden', expanded);
            btn.dataset.expanded = expanded ? '0' : '1';
            btn.textContent = expanded ? 'Selengkapnya' : 'Sembunyikan';
        });
    });

    // ---- Toggle action items ----
    document.querySelectorAll('.action-item-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var entryId = checkbox.dataset.entryId;
            var itemId = checkbox.dataset.itemId;
            fetch('/logbook/' + entryId + '/action-items/' + itemId + '/toggle', {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json'},
            }).then(r => r.json()).then(data => {
                checkbox.checked = data.is_done;
                var textEl = checkbox.nextElementSibling;
                textEl.classList.toggle('line-through', data.is_done);
                textEl.classList.toggle('text-text-secondary', data.is_done);
            });
        });
    });

    // ---- Filter & urut client-side ----
    var searchInput = document.getElementById('thread-search');
    var statusSelect = document.getElementById('thread-status');
    var sortSelect = document.getElementById('thread-sort');
    var list = document.getElementById('thread-list');
    var emptyNote = document.getElementById('thread-empty');

    function applyThreadFilter() {
        if (!list) return;
        var q = (searchInput ? searchInput.value : '').toLowerCase().trim();
        var status = statusSelect ? statusSelect.value : '';
        var cards = Array.from(list.querySelectorAll('.thread-card'));
        var visible = 0;
        cards.forEach(function (card) {
            var okSearch = !q || (card.dataset.search || '').indexOf(q) !== -1;
            var okStatus = !status || card.dataset.status === status;
            var show = okSearch && okStatus;
            card.classList.toggle('hidden', !show);
            if (show) visible++;
        });
        if (sortSelect) {
            var asc = sortSelect.value === 'asc';
            cards.sort(function (a, b) {
                var diff = (parseInt(a.dataset.activity || '0', 10) - parseInt(b.dataset.activity || '0', 10));
                return asc ? diff : -diff;
            }).forEach(function (card) { list.appendChild(card); });
        }
        if (emptyNote) emptyNote.classList.toggle('hidden', visible > 0);
    }

    [searchInput, statusSelect, sortSelect].forEach(function (el) {
        if (!el) return;
        el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', applyThreadFilter);
    });
</script>
@endsection
