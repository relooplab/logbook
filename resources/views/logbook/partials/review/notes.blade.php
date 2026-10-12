@php
    // Kartu catatan perbaikan compact (dipakai show & quick-review).
    $notesUser = auth()->user();
    $notesOwner = $notesUser->isMahasiswa() && $logbook->mahasiswaTa?->isMember($notesUser);
    $revisionRows = collect($logbook->riwayat_perbaikan ?? []);
    $readyRevisions = $logbook->completePerbaikanRows()->count();
    $needsAttentionRevisions = $revisionRows->count() - $readyRevisions;
    $completedRevisions = $revisionRows->filter(fn ($row) => ($row['status'] ?? null) === 'Sudah')->count();
    $revisionPercent = $revisionRows->count() ? (int) round($completedRevisions / $revisionRows->count() * 100) : 0;
    // Label sadar-peran (berbasis role, bukan ability): dosen membaca
    // transkrip masukannya sendiri + perbaikan mahasiswa; mahasiswa membaca
    // komentar dosen + perbaikannya.
    $notesReviewer = $notesUser->isDosen();
    $notesKomentarLabel = $notesReviewer ? 'Masukan Anda' : 'Komentar dosen';
    $notesPerbaikanLabel = $notesReviewer ? 'Perbaikan mahasiswa' : 'Perbaikan Anda';
    $notesTitle = $notesReviewer ? 'Jawaban Mahasiswa' : 'Jawaban';
    $notesDesc = $notesReviewer
        ? 'Transkrip masukan Anda dan perbaikan yang dilakukan mahasiswa.'
        : 'Komentar dosen dan perbaikan yang sudah Anda lakukan.';
    // Balasan dosen per baris: hanya reviewer saat entri menunggu review
    // yang bisa menulis (AJAX); selain itu read-only.
    $canReplyRows = $notesReviewer && auth()->user()->can('review', $logbook);
@endphp
@if ($revisionRows->isNotEmpty())
    <section class="card p-4 sm:p-5" aria-labelledby="revision-list-title">
        <div class="mb-3">
            <h2 id="revision-list-title" class="font-heading font-semibold text-lg text-text-primary">{{ $notesTitle }}</h2>
            <p class="text-sm text-text-secondary mt-1">{{ $notesDesc }}</p>
        </div>
        @if ($needsAttentionRevisions > 0 && $notesOwner && $logbook->isEditable())
            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-status-pending/50 bg-status-pending/10 px-4 py-3 text-sm" role="status">
                <span class="font-medium">{{ $needsAttentionRevisions }} dari {{ $revisionRows->count() }} baris belum lengkap</span>
                <a href="{{ route('logbook.edit', $logbook) }}" class="font-semibold text-brand hover:underline">Lengkapi →</a>
            </div>
        @elseif ($needsAttentionRevisions > 0 && $notesUser->can('review', $logbook))
            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-status-pending/50 bg-status-pending/10 px-4 py-3 text-sm" role="status">
                <span class="font-medium">{{ $needsAttentionRevisions }} dari {{ $revisionRows->count() }} jawaban belum lengkap</span>
            </div>
        @endif
        <div class="flex items-center gap-3 mb-3 text-sm"><span>{{ $completedRevisions }} dari {{ $revisionRows->count() }} diperbaiki</span><div class="h-2 flex-1 rounded-full bg-bg-hover overflow-hidden" role="progressbar" aria-label="Progres perbaikan" aria-valuenow="{{ $completedRevisions }}" aria-valuemin="0" aria-valuemax="{{ $revisionRows->count() }}"><div class="h-full bg-status-success rounded-full" style="width: {{ $revisionPercent }}%"></div></div><span>{{ $revisionPercent }}%</span></div>
        <div class="sr-only">{{ $notesReviewer ? 'Transkrip masukan Anda dan perbaikan mahasiswa' : 'Komentar dosen dan perbaikan Anda' }}</div>
        <div class="space-y-3" data-jawaban-table data-save-url="{{ $canReplyRows ? route('logbook.jawaban-reply', $logbook) : '' }}" data-entry-id="{{ $logbook->id }}">
            @forelse ($revisionRows as $r)
                @php $revisionStatus = $r['status'] ?? '—'; @endphp
                <article id="jawaban-baris-{{ $loop->index }}" class="rounded-xl border border-border p-4 scroll-mt-24" data-jawaban-row="{{ $loop->index }}" aria-label="Jawaban baris {{ $loop->iteration }} halaman {{ $r['halaman'] ?? '—' }}">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-text-secondary">
                        <span class="font-mono">#{{ $loop->iteration }}</span>
                        <span aria-hidden="true">·</span>
                        <span class="font-medium text-text-primary">{{ $r['halaman'] ?? '—' }}</span>
                        <span aria-hidden="true">·</span>
                        <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium
                            {{ $revisionStatus === 'Sudah' ? 'bg-status-success/10 text-status-success' : '' }}
                            {{ $revisionStatus === 'Sebagian' ? 'bg-status-pending/10 text-status-pending' : '' }}
                            {{ $revisionStatus === 'Draf' ? 'bg-bg-hover text-text-secondary border border-dashed border-border' : '' }}
                            {{ $revisionStatus === 'Belum' ? 'bg-status-danger/10 text-status-danger' : '' }}
                        ">{{ $revisionStatus }}</span>
                    </div>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="max-w-[85%] rounded-xl rounded-tl-sm bg-bg-panel px-3 py-2">
                            <p class="text-[11px] font-semibold text-text-secondary">{{ $notesKomentarLabel }}</p>
                            <p class="mt-0.5 whitespace-pre-wrap break-words text-text-primary">{{ $r['komentar_dosen'] ?? '—' }}</p>
                        </div>
                        <div class="ml-auto max-w-[85%] rounded-xl rounded-tr-sm bg-brand/10 px-3 py-2">
                            <p class="text-[11px] font-semibold text-brand">{{ $notesPerbaikanLabel }}</p>
                            <p class="mt-0.5 whitespace-pre-wrap break-words text-text-primary">{{ $r['perbaikan'] ?? '—' }}</p>
                        </div>
                        @if (!empty($r['dosen_reply']))
                            <div class="max-w-[85%] rounded-xl rounded-tl-sm bg-bg-panel px-3 py-2">
                                <p class="text-[11px] font-semibold text-text-secondary">Balasan Dosen</p>
                                <p class="mt-0.5 whitespace-pre-wrap break-words text-text-primary">{{ $r['dosen_reply'] }}</p>
                            </div>
                        @endif
                        @if ($canReplyRows)
                            <div class="flex flex-col gap-1.5 pt-1">
                                <label class="sr-only" for="jawaban-reply-{{ $logbook->id }}-{{ $loop->index }}">Balasan untuk baris {{ $loop->iteration }}</label>
                                <textarea id="jawaban-reply-{{ $logbook->id }}-{{ $loop->index }}" rows="2" maxlength="2000" placeholder="Tulis balasan…" data-jawaban-reply
                                    class="w-full rounded-lg border border-border bg-bg-surface px-2.5 py-1.5 text-sm text-text-primary placeholder:text-text-secondary focus:outline-none focus:ring-2 focus:ring-brand/40">{{ $r['dosen_reply'] ?? '' }}</textarea>
                                <div class="flex items-center gap-2">
                                    <button type="button" data-jawaban-save
                                        class="inline-flex min-h-9 items-center px-3 py-1.5 rounded-lg bg-brand/10 text-brand text-xs font-semibold hover:bg-brand/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Simpan</button>
                                    <span class="hidden text-[11px] text-text-secondary" data-jawaban-status role="status" aria-live="polite"></span>
                                </div>
                            </div>
                        @elseif (empty($r['dosen_reply']))
                            <p class="text-xs text-text-secondary">Belum ada balasan dosen.</p>
                        @endif
                    </div>
                </article>
            @empty
                <p class="py-2 text-sm text-text-secondary">Belum ada catatan perbaikan.</p>
            @endempty
        </div>
        @if ($canReplyRows)
            <script>
                (function () {
                    var table = document.querySelector('[data-jawaban-table]');
                    if (!table || table.dataset.bound) return;
                    table.dataset.bound = '1';
                    var saveUrl = table.dataset.saveUrl;
                    var csrf = document.querySelector('meta[name="csrf-token"]')?.content
                        || document.querySelector('input[name="_token"]')?.value || '';
                    table.querySelectorAll('[data-jawaban-row]').forEach(function (row) {
                        var area = row.querySelector('[data-jawaban-reply]');
                        var btn = row.querySelector('[data-jawaban-save]');
                        var status = row.querySelector('[data-jawaban-status]');
                        if (!area || !btn) return;
                        function setStatus(text, isError) {
                            if (!status) return;
                            status.textContent = text;
                            status.classList.toggle('hidden', !text);
                            status.classList.toggle('text-status-danger', !!isError);
                        }
                        btn.addEventListener('click', async function () {
                            btn.disabled = true;
                            setStatus('Menyimpan…', false);
                            try {
                                const res = await fetch(saveUrl, {method: 'POST',
                                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                                    credentials: 'same-origin',
                                    body: JSON.stringify({index: parseInt(row.dataset.jawabanRow), reply: area.value})});
                                if (res.status === 404) { setStatus('Baris tidak ditemukan.', true); return; }
                                if (!res.ok) throw new Error();
                                setStatus('Tersimpan', false);
                            } catch (e) {
                                setStatus('Gagal menyimpan. Coba lagi.', true);
                            } finally {
                                btn.disabled = false;
                            }
                        });
                    });
                })();
            </script>
        @endif
    </section>
@endif
