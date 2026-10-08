@php
    // Kartu konteks: komentar PDF seluruh rantai ke atas + tombol pakai-ulang.
    // Induk langsung expanded; leluhur lebih tua collapsed per entri.
    // $useFeedbackButtons (bool), $lastFeedback (?string) opsional.
    $useFeedbackButtons = $useFeedbackButtons ?? false;
    $lastFeedback = $lastFeedback ?? null;
    $chain = [];
    $cursor = $logbook->parentEntry;
    $depth = 0;
    while ($cursor && $depth < 5) {
        $chain[] = $cursor;
        $cursor = $cursor->parentEntry;
        $depth++;
    }
    $directParent = $chain[0] ?? null;
    $olderAncestors = array_slice($chain, 1);
    $directComments = $directParent?->comments ?? collect();
    $ownComments = $logbook->comments ?? collect();
    $resolutionLabels = ['open' => 'Belum ditindaklanjuti', 'addressed' => 'Sudah dijawab', 'resolved' => 'Selesai'];
    $hasAnything = $directParent?->feedback_dosen || $directComments->isNotEmpty() || $ownComments->isNotEmpty()
        || collect($olderAncestors)->contains(fn ($a) => $a->comments->isNotEmpty())
        || $lastFeedback;
@endphp
@if ($hasAnything)
<section class="card min-w-0 p-5 sm:p-6" aria-labelledby="previous-heading">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="previous-heading" class="font-heading text-lg font-semibold text-text-primary">Komentar Sebelumnya</h2>
        @php $totalComments = $ownComments->count() + $directComments->count() + collect($olderAncestors)->sum(fn ($a) => $a->comments->count()); @endphp
        @if ($totalComments > 0)
            <span class="text-xs text-text-secondary">{{ $totalComments }} komentar</span>
        @endif
    </div>
    @if ($directParent?->feedback_dosen)
        <div class="mt-4 rounded-xl bg-bg-panel p-4">
            <p class="text-xs font-semibold text-text-secondary">Feedback entri induk #{{ $directParent->id }}</p>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-text-primary">{{ $directParent->feedback_dosen }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                <a href="{{ route('logbook.show', $directParent) }}" class="inline-block text-xs font-medium text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Buka entri induk ↗</a>
                @if ($useFeedbackButtons)
                    <button type="button" class="use-last-feedback text-xs font-semibold text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" data-body="{{ $directParent->feedback_dosen }}">Gunakan sebagai feedback</button>
                @endif
            </div>
        </div>
    @endif
    @if ($ownComments->isNotEmpty())
        <p class="mt-4 text-xs font-semibold text-text-secondary">Komentar entri ini</p>
        <div class="mt-2 overflow-x-auto rounded-xl border border-border">
            <table class="w-full min-w-[560px] text-sm">
                <thead><tr class="text-left text-text-secondary border-b border-border">
                    <th scope="col" class="py-2 px-3 font-medium">No</th>
                    <th scope="col" class="py-2 px-3 font-medium">Komentar</th>
                    <th scope="col" class="py-2 px-3 font-medium">Hal.</th>
                    <th scope="col" class="py-2 px-3 font-medium">Status</th>
                </tr></thead>
                <tbody>
                    @foreach ($ownComments as $comment)
                        <tr class="border-b border-border last:border-0 {{ $comment->resolution_status === 'resolved' ? 'opacity-60' : '' }}">
                            <td class="py-2 px-3 font-mono text-text-secondary">{{ $loop->iteration }}</td>
                            <td class="py-2 px-3">{{ $comment->comment }}</td>
                            <td class="py-2 px-3 whitespace-nowrap text-text-secondary">{{ $comment->page_number ? 'Hal. '.$comment->page_number : '—' }}</td>
                            <td class="py-2 px-3 whitespace-nowrap text-xs">{{ $resolutionLabels[$comment->resolution_status] ?? $comment->resolution_status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($directComments->isNotEmpty())
        <div class="mt-4 overflow-x-auto rounded-xl border border-border">
            <table class="w-full min-w-[560px] text-sm">
                <thead><tr class="text-left text-text-secondary border-b border-border">
                    <th scope="col" class="py-2 px-3 font-medium">No</th>
                    <th scope="col" class="py-2 px-3 font-medium">Komentar</th>
                    <th scope="col" class="py-2 px-3 font-medium">Hal.</th>
                    <th scope="col" class="py-2 px-3 font-medium">Status</th>
                </tr></thead>
                <tbody>
                    @foreach ($directComments as $comment)
                        <tr class="border-b border-border last:border-0 {{ $comment->resolution_status === 'resolved' ? 'opacity-60' : '' }}">
                            <td class="py-2 px-3 font-mono text-text-secondary">{{ $loop->iteration }}</td>
                            <td class="py-2 px-3">{{ $comment->comment }}</td>
                            <td class="py-2 px-3 whitespace-nowrap text-text-secondary">{{ $comment->page_number ? 'Hal. '.$comment->page_number : '—' }}</td>
                            <td class="py-2 px-3 whitespace-nowrap text-xs">{{ $resolutionLabels[$comment->resolution_status] ?? $comment->resolution_status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @foreach ($olderAncestors as $ancestor)
        @php
            $ancestorComments = $ancestor->comments;
            $ancestorDone = $ancestorComments->where('resolution_status', 'resolved')->count();
        @endphp
        @if ($ancestorComments->isNotEmpty())
            <details class="mt-3 rounded-xl border border-dashed border-border px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-text-primary">
                    Entri #{{ $ancestor->id }}{{ $ancestor->revision_round ? ' · Revisi ke-'.$ancestor->revision_round : '' }} — {{ $ancestorComments->count() }} komentar
                    @if ($ancestorDone > 0)<span class="ml-1 text-xs font-normal text-text-secondary">{{ $ancestorDone }} selesai</span>@endif
                </summary>
                <div class="mt-3 overflow-x-auto rounded-xl border border-border">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead><tr class="text-left text-text-secondary border-b border-border">
                            <th scope="col" class="py-2 px-3 font-medium">No</th>
                            <th scope="col" class="py-2 px-3 font-medium">Komentar</th>
                            <th scope="col" class="py-2 px-3 font-medium">Hal.</th>
                            <th scope="col" class="py-2 px-3 font-medium">Status</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($ancestorComments as $comment)
                                <tr class="border-b border-border last:border-0 {{ $comment->resolution_status === 'resolved' ? 'opacity-60' : '' }}">
                                    <td class="py-2 px-3 font-mono text-text-secondary">{{ $loop->iteration }}</td>
                                    <td class="py-2 px-3">{{ $comment->comment }}</td>
                                    <td class="py-2 px-3 whitespace-nowrap text-text-secondary">{{ $comment->page_number ? 'Hal. '.$comment->page_number : '—' }}</td>
                                    <td class="py-2 px-3 whitespace-nowrap text-xs">{{ $resolutionLabels[$comment->resolution_status] ?? $comment->resolution_status }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    @endforeach
    @if ($lastFeedback && $lastFeedback !== $directParent?->feedback_dosen)
        <div class="mt-4 rounded-xl bg-bg-panel p-4">
            <p class="text-xs font-semibold text-text-secondary">Feedback terakhir tersimpan</p>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-text-primary">{{ $lastFeedback }}</p>
            @if ($useFeedbackButtons)
                <button type="button" class="use-last-feedback mt-2 text-xs font-semibold text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" data-body="{{ $lastFeedback }}">Gunakan sebagai feedback</button>
            @endif
        </div>
    @endif
</section>
@endif
