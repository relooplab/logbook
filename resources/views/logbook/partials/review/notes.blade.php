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
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full min-w-[640px] text-sm">
                <caption class="sr-only">{{ $notesReviewer ? 'Transkrip masukan Anda dan perbaikan mahasiswa' : 'Komentar dosen dan perbaikan Anda' }}</caption>
                <thead><tr class="text-left text-text-secondary border-b border-border">
                    <th scope="col" class="py-2 px-3 font-medium">No</th>
                    <th scope="col" class="py-2 px-3 font-medium">Halaman</th>
                    <th scope="col" class="py-2 px-3 font-medium">{{ $notesKomentarLabel }}</th>
                    <th scope="col" class="py-2 px-3 font-medium">{{ $notesPerbaikanLabel }}</th>
                    <th scope="col" class="py-2 px-3 font-medium">Status</th>
                </tr></thead>
                <tbody>
                    @forelse ($revisionRows as $r)
                        @php $revisionStatus = $r['status'] ?? '—'; @endphp
                        <tr class="border-b border-border last:border-0 hover:bg-bg-panel/50">
                            <td class="py-2 px-3 font-mono text-text-secondary">{{ $loop->iteration }}</td>
                            <td class="py-2 px-3 whitespace-nowrap font-medium">{{ $r['halaman'] ?? '—' }}</td>
                            <td class="py-2 px-3">{{ $r['komentar_dosen'] ?? '—' }}</td>
                            <td class="py-2 px-3">{{ $r['perbaikan'] ?? '—' }}</td>
                            <td class="py-2 px-3 whitespace-nowrap">
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium
                                    {{ $revisionStatus === 'Sudah' ? 'bg-status-success/10 text-status-success' : '' }}
                                    {{ $revisionStatus === 'Sebagian' ? 'bg-status-pending/10 text-status-pending' : '' }}
                                    {{ $revisionStatus === 'Draf' ? 'bg-bg-hover text-text-secondary border border-dashed border-border' : '' }}
                                    {{ $revisionStatus === 'Belum' ? 'bg-status-danger/10 text-status-danger' : '' }}
                                ">{{ $revisionStatus }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 px-3 text-sm text-text-secondary">Belum ada catatan perbaikan.</td></tr>
                    @endempty
                </tbody>
            </table>
        </div>
    </section>
@endif
