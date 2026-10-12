{{-- Kartu Kelola Draf bersama (wizard logbook & revisi). --}}
{{-- Props: $entries (collection), $wizard ('logbook'|'revisi'), --}}
{{-- $currentDraftId (?int), $programJenis (?string), $formId (string unik). --}}
@php
    $entries = $entries ?? collect();
    $wizard = $wizard ?? 'logbook';
    $currentDraftId = $currentDraftId ?? null;
    $programJenis = $programJenis ?? null;
    $formId = $formId ?? 'bulk-delete-form-drafts';
    $count = $entries->count();
    $isOpen = $count > 0 && $count <= 3 && empty($currentDraftId);
    $continueRoute = $wizard === 'revisi' ? 'logbook.create-revisi' : 'logbook.create';
    $selectAllId = $formId.'-select-all';
    $countId = $formId.'-count';
    $btnId = $formId.'-btn';
@endphp
<details class="card p-5" @if($isOpen) open @endif aria-label="Kelola draf">
    <summary class="flex cursor-pointer flex-wrap items-center gap-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
        <span class="icon-chip h-10 w-10" aria-hidden="true">
            <span class="material-symbols-outlined icon-md text-brand">draft</span>
        </span>
        <span class="min-w-0 flex-1">
            <span class="font-heading font-semibold text-text-primary">Kelola Draf ({{ $count }})</span>
            <span class="block text-caption text-text-secondary">Lanjutkan untuk edit di wizard, atau hapus yang tidak jadi dipakai. Hanya draf milik program ini.</span>
        </span>
    </summary>
    <div class="mt-4">
        @if ($entries->isEmpty())
            <div class="px-4 py-6 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
                <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50" aria-hidden="true">inbox</span>
                <p class="text-sm">Tidak ada draf {{ $wizard === 'revisi' ? 'revisi' : 'logbook' }} pada program ini.</p>
            </div>
        @else
            <form id="{{ $formId }}" method="POST" action="{{ route('logbook.bulk-destroy') }}" class="space-y-2">
                @csrf
                @if ($programJenis) <input type="hidden" name="program" value="{{ $programJenis }}"> @endif
                <label class="flex items-center gap-2 text-xs text-text-secondary" for="{{ $selectAllId }}">
                    <input type="checkbox" id="{{ $selectAllId }}" class="w-5 h-5 accent-brand">
                    Pilih semua
                </label>
                @foreach ($entries as $entry)
                    @php
                        $isCurrent = $currentDraftId && (int) $entry->id === (int) $currentDraftId;
                        $title = $entry->jenis === 'revisi'
                            ? ($entry->parentEntry?->sesi_ke ? 'Jawaban Sesi '.$entry->parentEntry->sesi_ke : 'Jawaban baru')
                            : 'Draf #'.$entry->id;
                        $continueUrl = route($continueRoute, array_filter(['draft_id' => $entry->id, 'program' => $programJenis]));
                    @endphp
                    <div class="flex flex-col gap-3 rounded-xl border border-border px-3 py-3 text-sm hover:bg-bg-panel/50 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <span class="icon-chip h-9 w-9" aria-hidden="true">
                                <span class="material-symbols-outlined icon-md text-brand">description</span>
                            </span>
                            <input type="checkbox" name="ids[]" value="{{ $entry->id }}"
                                id="{{ $formId }}-entry-{{ $entry->id }}"
                                class="{{ $formId }}-checkbox mt-1 w-5 h-5 shrink-0 accent-brand"
                                aria-label="Pilih draf #{{ $entry->id }} untuk hapus"
                                @disabled($isCurrent) title="{{ $isCurrent ? 'Draf sedang dibuka — hapus dari halaman daftar bila perlu' : 'Pilih untuk hapus' }}">
                            <label for="{{ $formId }}-entry-{{ $entry->id }}" class="min-w-0 flex-1 cursor-pointer">
                                <span class="block font-medium text-text-primary">{{ $title }} · {{ \Illuminate\Support\Str::limit($entry->topik ?: 'Tanpa topik', 60) }}</span>
                                <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-secondary">
                                    <span>{{ $entry->tanggal_tampil?->format('d M Y') ?? '—' }}</span>
                                    <span aria-hidden="true">·</span>
                                    @include('partials.status-badge', ['status' => $entry->status, 'entry' => $entry])
                                    <span aria-hidden="true">·</span>
                                    <span>diubah {{ $entry->updated_at?->diffForHumans() ?? '—' }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined icon-sm" aria-hidden="true">{{ $entry->lampiran_path ? 'check_circle' : 'upload_file' }}</span>{{ $entry->lampiran_path ? 'Sudah diunggah' : 'Belum ada file' }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <div class="flex flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            @if ($isCurrent)
                                <span class="badge badge-pending">Sedang dibuka</span>
                            @else
                                <a href="{{ $continueUrl }}"
                                    class="btn-secondary inline-flex min-h-11 items-center justify-center gap-2 px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Lanjutkan
                                    <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_forward</span></a>
                                <button type="button" data-delete-single="{{ $entry->id }}"
                                    class="inline-flex min-h-11 items-center justify-center px-4 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm font-medium hover:bg-status-danger/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Hapus</button>
                            @endif
                        </div>
                    </div>
                @endforeach
                <div class="flex flex-wrap items-center gap-2 pt-2">
                    <button type="submit" id="{{ $btnId }}" disabled
                        class="px-4 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm font-medium hover:bg-status-danger/20 disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
                        Hapus terpilih (<span id="{{ $countId }}" role="status">0</span>)
                    </button>
                    <span class="text-xs text-text-secondary">File draf yang dihapus ikut terhapus dan tidak bisa dikembalikan.</span>
                </div>
            </form>
            <script>
                (function () {
                    var form = document.getElementById(@json($formId));
                    if (!form || form.dataset.bound) return;
                    form.dataset.bound = '1';
                    var boxes = Array.from(form.querySelectorAll('input[type="checkbox"][name="ids[]"]:not([disabled])'));
                    var countEl = document.getElementById(@json($countId));
                    var btn = document.getElementById(@json($btnId));
                    var selectAll = document.getElementById(@json($selectAllId));

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
                    form.querySelectorAll('[data-delete-single]').forEach(function (singleBtn) {
                        singleBtn.addEventListener('click', function () {
                            var id = singleBtn.getAttribute('data-delete-single');
                            boxes.forEach(function (b) { b.checked = b.value === id; });
                            refresh();
                            if (!confirm('Hapus draf #' + id + '? File ikut terhapus dan tidak bisa dikembalikan.')) return;
                            form.submit();
                        });
                    });
                    form.addEventListener('submit', function (e) {
                        if (form.dataset.singleConfirmed) return;
                        var checked = boxes.filter(function (b) { return b.checked; });
                        if (checked.length === 0) { e.preventDefault(); return; }
                        if (checked.length > 1 && !confirm('Hapus ' + checked.length + ' draf terpilih? File ikut terhapus dan tidak bisa dikembalikan.')) {
                            e.preventDefault();
                        }
                    });
                    refresh();
                })();
            </script>
        @endif
    </div>
</details>
