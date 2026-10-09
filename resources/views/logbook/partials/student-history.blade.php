<div class="space-y-6">
    @php $actionLinks = '';
    if (auth()->user()?->isMahasiswa()) {
        $actionLinks = '
        <a href="' . route('logbook.create', array_filter(['program' => request('program')])) . '" class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90 inline-flex items-center gap-1.5">
            <span class="material-symbols-outlined icon-sm text-accent-orange">add</span> + Logbook
        </a>
        <a href="' . route('logbook.create-revisi', array_filter(['program' => request('program')])) . '" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">+ Entri Revisi</a>';
    } @endphp
<x-page-header subtitle="Bimbingan" title="Logbook Bimbingan">
    <x-slot:actions>{!! $actionLinks !!}</x-slot:actions>
</x-page-header>

    @if (($pendingRevisions ?? collect())->isNotEmpty())
        <div class="rounded-xl border border-status-danger/40 bg-status-danger/10 p-4" role="alert">
            <p class="font-semibold text-text-primary">Revisi perlu ditanggapi dulu</p>
            <p class="mt-1 text-sm text-text-secondary">Jawaban revisi harus dikirim lewat jalur revisi — bukan lewat sesi logbook baru — agar tidak terputus dari komentar dosen.</p>
            @if (!empty($pendingRevisionAction['url']))
                <a href="{{ $pendingRevisionAction['url'] }}" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-[#0b1420] hover:opacity-90">{{ $pendingRevisionAction['label'] ?? 'Lanjutkan Revisi' }} →</a>
            @endif
        </div>
    @endif

    {{-- Filter kombinasi --}}
    <form method="GET" action="{{ route('logbook.index', array_filter(['program' => request('program')])) }}" class="card p-4 flex flex-wrap gap-3 items-end">
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Status</label>
            <select name="status" class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
                <option value="">Semua</option>
                @foreach (\App\Models\LogbookEntry::STATUS_LABELS as $v => $l)
                    <option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Jenis</label>
            <select name="jenis" class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
                <option value="">Semua</option>
                <option value="logbook" @selected(($filters['jenis'] ?? '') === 'logbook')>Logbook</option>
                <option value="revisi" @selected(($filters['jenis'] ?? '') === 'revisi')>Revisi</option>
            </select>
        </div>
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Dari tanggal</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
        </div>
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Sampai tanggal</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
        </div>
        <div class="w-full sm:w-auto">
            <label class="block text-xs text-text-secondary mb-1">Kata kunci</label>
            <input type="text" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="Topik / nama / isi"
                class="w-full sm:w-auto rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
        </div>
        <div class="flex gap-2 w-full sm:w-auto">
            <button type="submit" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Cari</button>
            <a href="{{ route('logbook.index', array_filter(['program' => request('program')])) }}" class="flex-1 sm:flex-none px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border text-center">Reset</a>
        </div>
        @if (auth()->user()->isMahasiswa())
            <div class="w-full flex flex-wrap items-center gap-2 border-t border-border pt-3">
                <button type="submit" form="bulk-delete-form" id="bulk-delete-btn" disabled
                    class="px-4 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm font-medium hover:bg-status-danger/20 disabled:opacity-40 disabled:cursor-not-allowed">
                    Hapus terpilih (<span id="bulk-delete-count">0</span>)
                </button>
                <span class="text-xs text-text-secondary">Hanya draf / revisi sedang dikerjakan yang bisa dipilih.</span>
            </div>
        @endif
    </form>

    @if ($entries->isEmpty())
        <div class="px-4 py-10 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
            <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">inbox</span>
            <p>Belum ada entri yang cocok.</p>
        </div>
    @else
        @php $bulkForm = auth()->user()->isMahasiswa(); @endphp
        @if ($bulkForm)
            <form id="bulk-delete-form" method="POST" action="{{ route('logbook.bulk-destroy') }}">
                @csrf
                @if (request('program')) <input type="hidden" name="program" value="{{ request('program') }}"> @endif
        @endif
        <div class="card p-0 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-border">
                        @if ($bulkForm)
                            <th class="py-3 px-4 w-10"><input type="checkbox" id="bulk-select-all" aria-label="Pilih semua yang bisa dihapus"></th>
                        @endif
                        @if (!auth()->user()->isMahasiswa())
                            <th class="py-3 px-4">Mahasiswa</th>
                        @endif
                        <th class="py-3 px-4">Sesi</th>
                        <th class="py-3 px-4 table-col-jenis">Jenis</th>
                        <th class="py-3 px-4">Topik</th>
                        <th class="py-3 px-4 table-col-tanggal">Tanggal</th>
                        <th class="py-3 px-4">Status</th>
                        @if (auth()->user()->isMahasiswa())
                            <th class="py-3 px-4">Dilihat</th>
                        @endif
                        <th class="py-3 px-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        // Thread induk-anak: jawaban revisi menempel di bawah induknya
                        // agar hubungan keduanya kelihatan; anak yang induknya tak
                        // sehalaman (filter/paginasi) tampil datar seperti biasa.
                        $byId = $entries->keyBy('id');
                        $threadChildren = [];
                        $threadRoots = [];
                        foreach ($entries as $e) {
                            if ($e->parent_entry_id && $byId->has($e->parent_entry_id)) {
                                $threadChildren[$e->parent_entry_id][] = $e;
                            } else {
                                $threadRoots[] = $e;
                            }
                        }
                    @endphp
                    @foreach ($threadRoots as $entry)
                        @include('logbook.partials.student-history-row', ['entry' => $entry, 'bulkForm' => $bulkForm, 'threadChild' => false, 'childCount' => count($threadChildren[$entry->id] ?? [])])
                        @foreach ($threadChildren[$entry->id] ?? [] as $child)
                            @include('logbook.partials.student-history-row', ['entry' => $child, 'bulkForm' => $bulkForm, 'threadChild' => true, 'parentId' => $entry->id])
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($bulkForm)
            </form>
        @endif
        <div class="px-2">{{ $entries->links() }}</div>
    @endif
</div>
@if (auth()->user()->isMahasiswa())
    <script>
        (function () {
            var form = document.getElementById('bulk-delete-form');
            if (!form) return;
            var boxes = Array.from(form.querySelectorAll('.bulk-checkbox:not([disabled])'));
            var countEl = document.getElementById('bulk-delete-count');
            var btn = document.getElementById('bulk-delete-btn');
            var selectAll = document.getElementById('bulk-select-all');

            function refresh() {
                var n = boxes.filter(function (b) { return b.checked; }).length;
                if (countEl) countEl.textContent = n;
                if (btn) btn.disabled = n === 0;
                if (selectAll) {
                    selectAll.checked = boxes.length > 0 && boxes.every(function (b) { return b.checked; });
                    selectAll.indeterminate = n > 0 && n < boxes.length;
                }
            }

            boxes.forEach(function (b) { b.addEventListener('change', refresh); });
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    boxes.forEach(function (b) { b.checked = selectAll.checked; });
                    refresh();
                });
            }
            form.addEventListener('submit', function (e) {
                var n = boxes.filter(function (b) { return b.checked; }).length;
                if (n === 0) { e.preventDefault(); return; }
                if (!confirm('Hapus ' + n + ' entri terpilih? Hanya draf / revisi sedang dikerjakan yang akan dihapus.')) {
                    e.preventDefault();
                }
            });
            refresh();
        })();
    </script>
@endif
