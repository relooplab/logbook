{{-- Kartu thread: satu rantai umpan balik -> revisi -> review. Menerima $thread dari controller. --}}
@php
    $searchText = mb_strtolower(implode(' ', array_filter([
        $thread['title'],
        $thread['reviewerName'],
        ...$thread['nodes']->map(fn ($n) => $n->feedback_dosen)->filter()->all(),
    ])));
@endphp
<article class="card overflow-hidden thread-card" data-search="{{ $searchText }}"
    data-status="{{ $thread['latestEntry']->status }}" data-activity="{{ $thread['latestAt']?->timestamp ?? 0 }}">
    {{-- Header thread --}}
    <button type="button" data-thread-toggle aria-expanded="{{ $thread['expanded'] ? 'true' : 'false' }}"
        aria-controls="{{ $thread['key'] }}-body"
        class="w-full flex items-start justify-between gap-3 p-4 sm:p-5 text-left hover:bg-bg-hover/50 transition-colors">
        <div class="min-w-0">
            <p class="font-semibold text-text-primary truncate">{{ $thread['title'] }}</p>
            <p class="text-xs text-text-secondary mt-0.5">
                {{ $thread['reviewerName'] ?? '—' }}
                @if ($thread['firstFeedbackAt'])
                    · {{ $thread['firstFeedbackAt']->format('d M Y') }}
                @endif
            </p>
            <p class="text-xs text-text-secondary mt-0.5">
                {{ $ta?->jenisLabel() }}@if ($thread['reviewerRole']) · {{ $thread['reviewerRole'] }}@endif
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @include('partials.status-badge', ['status' => $thread['latestEntry']->status, 'entry' => $thread['latestEntry']])
            <span class="text-xs text-text-secondary whitespace-nowrap hidden sm:inline">{{ $thread['feedbackCount'] }} feedback · {{ $thread['revisionCount'] }} revisi</span>
            <span class="material-symbols-outlined icon-md text-text-secondary transition-transform" data-thread-chevron
                @if (! $thread['expanded']) style="transform: rotate(-90deg)" @endif>expand_more</span>
        </div>
    </button>
    <p class="px-4 pb-3 -mt-2 text-xs text-text-secondary sm:hidden">{{ $thread['feedbackCount'] }} feedback · {{ $thread['revisionCount'] }} revisi</p>

    {{-- Timeline --}}
    <div id="{{ $thread['key'] }}-body" data-thread-body @if (! $thread['expanded']) class="hidden" @endif
        class="px-4 sm:px-5 pb-5">
        <ol class="relative border-l-2 border-border ml-2 space-y-5 pt-1">
            @foreach ($thread['nodes'] as $node)
                @php
                    $openComments = $node->comments->where('resolution_status', '!=', \App\Models\PdfComment::STATUS_RESOLVED);
                    $doneItems = $node->actionItems->where('is_done', true)->count();
                    $totalItems = $node->actionItems->count();
                    $isRevision = $node->jenis === \App\Models\LogbookEntry::JENIS_REVISI;
                    $hasExtras = $openComments->isNotEmpty() || $totalItems > 0;
                    $createdTs = $node->created_at?->timestamp ?? 0;
                    $submittedTs = $node->submitted_at?->timestamp;
                    // Revisi tidak mungkin dibuat setelah dikirim; bila data
                    // artefak (mis. backdate), jepit waktu dibuat ke waktu kirim.
                    if ($submittedTs && $createdTs > $submittedTs) {
                        $createdTs = $submittedTs;
                    }
                    // Susun event node lalu urutkan kronologis agar timeline tidak bolak-balik.
                    $events = [];
                    if (! $isRevision && $node->submitted_at) {
                        $events[] = ['at' => $node->submitted_at->timestamp, 'kind' => 'sent-logbook'];
                    }
                    if (! $isRevision && filled($node->feedback_dosen)) {
                        $events[] = ['at' => ($node->reviewed_at ?? $node->created_at)?->timestamp ?? 0, 'kind' => 'feedback'];
                    }
                    if ($isRevision) {
                        $events[] = ['at' => $createdTs, 'kind' => 'created'];
                    }
                    if ($isRevision && $node->submitted_at) {
                        $events[] = ['at' => $node->submitted_at->timestamp, 'kind' => 'sent-revision'];
                    }
                    if ($isRevision && filled($node->feedback_dosen)) {
                        $events[] = ['at' => ($node->reviewed_at ?? $node->created_at)?->timestamp ?? 0, 'kind' => 'review'];
                    }
                    $events = collect($events)->sortBy('at')->values();
                    $extrasRendered = false;
                @endphp
                <li class="ml-4 space-y-3">
                    <span class="absolute -left-[9px] mt-1 h-3.5 w-3.5 rounded-full {{ $isRevision ? 'bg-brand' : 'bg-status-success' }}"></span>

                    @foreach ($events as $ev)
                        {{-- Logbook dikirim --}}
                        @if ($ev['kind'] === 'sent-logbook')
                            <div>
                                <p class="text-sm font-medium text-text-primary">
                                    Logbook dikirim
                                    <span class="text-xs font-normal text-text-secondary">· {{ $node->submitted_at->format('d M Y, H:i') }}</span>
                                </p>
                            </div>
                        @endif

                        {{-- Umpan balik diterima (node logbook) --}}
                        @if ($ev['kind'] === 'feedback')
                            <div>
                                <p class="text-sm font-medium text-text-primary">Umpan balik diterima</p>
                                @php $longFeedback = mb_strlen($node->feedback_dosen) > 220; @endphp
                                <p class="text-sm text-text-secondary whitespace-pre-wrap mt-0.5" data-feedback-text>{{ $longFeedback ? \Illuminate\Support\Str::limit($node->feedback_dosen, 220) : $node->feedback_dosen }}</p>
                                @if ($longFeedback)
                                    <p class="hidden text-sm text-text-secondary whitespace-pre-wrap mt-0.5" data-feedback-full>{{ $node->feedback_dosen }}</p>
                                    <button type="button" data-feedback-more class="text-xs text-brand hover:underline mt-1">Selengkapnya</button>
                                @endif
                                <p class="text-xs text-text-secondary mt-1">
                                    {{ $node->dosen?->name ?? '—' }}
                                    @if ($node->reviewed_at)
                                        · {{ $node->reviewed_at->format('d M Y, H:i') }}
                                    @endif
                                    · <a href="{{ route('logbook.show', $node) }}" class="text-brand hover:underline">Lihat Detail</a>
                                </p>
                            </div>
                        @endif

                        {{-- Revisi dibuat --}}
                        @if ($ev['kind'] === 'created')
                            <div>
                                <p class="text-sm font-medium text-text-primary">
                                    Revisi dibuat
                                    <span class="text-xs font-normal text-text-secondary">· Revisi {{ $node->revision_round ?? '—' }}</span>
                                </p>
                                @if (filled($node->progres_kendala))
                                    <p class="text-sm text-text-secondary mt-0.5">{{ $node->progres_kendala }}</p>
                                @endif
                                @if ($node->riwayat_perbaikan)
                                    <div class="mt-1.5 space-y-1.5">
                                        @foreach (array_slice($node->riwayat_perbaikan, 0, 3) as $row)
                                            <div class="flex items-start gap-2 text-sm bg-bg-panel rounded-lg px-3 py-2">
                                                <span class="text-xs text-text-secondary mt-0.5 w-16 shrink-0">{{ $row['halaman'] ?? '—' }}</span>
                                                <span class="flex-1 text-text-primary">{{ $row['perbaikan'] ?? '' }}</span>
                                                <span class="text-xs {{ ($row['status'] ?? '') === 'Sudah' ? 'text-status-success' : 'text-status-pending' }} shrink-0">{{ $row['status'] ?? '' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="text-xs text-text-secondary mt-1">
                                    Mahasiswa
                                    @if ($node->created_at)
                                        · {{ $node->created_at->format('d M Y, H:i') }}
                                    @endif
                                </p>
                            </div>
                        @endif

                        {{-- Revisi dikirim --}}
                        @if ($ev['kind'] === 'sent-revision')
                            <div>
                                <p class="text-sm font-medium text-text-primary">
                                    Revisi dikirim
                                    <span class="text-xs font-normal text-text-secondary">· {{ $node->submitted_at->format('d M Y, H:i') }}</span>
                                </p>
                                <div class="mt-1">
                                    @include('partials.status-badge', ['status' => $node->status, 'entry' => $node])
                                </div>
                                <a href="{{ route('logbook.show', $node) }}" class="text-xs text-brand hover:underline mt-1 inline-block">Lihat revisi →</a>
                            </div>
                        @endif

                        {{-- Hasil review (gabungan feedback dosen) --}}
                        @if ($ev['kind'] === 'review')
                            <div>
                                <p class="text-sm font-medium text-text-primary">
                                    @if ($node->status === \App\Models\LogbookEntry::STATUS_APPROVED)
                                        Revisi disetujui
                                    @elseif ($node->status === \App\Models\LogbookEntry::STATUS_REVISI)
                                        Revisi diminta
                                    @else
                                        Umpan balik diterima
                                    @endif
                                    @if ($node->reviewed_at)
                                        <span class="text-xs font-normal text-text-secondary">· {{ $node->reviewed_at->format('d M Y, H:i') }}</span>
                                    @endif
                                </p>
                                @php $longFeedback = mb_strlen($node->feedback_dosen) > 220; @endphp
                                <p class="text-sm text-text-secondary whitespace-pre-wrap mt-0.5" data-feedback-text>{{ $longFeedback ? \Illuminate\Support\Str::limit($node->feedback_dosen, 220) : $node->feedback_dosen }}</p>
                                @if ($longFeedback)
                                    <p class="hidden text-sm text-text-secondary whitespace-pre-wrap mt-0.5" data-feedback-full>{{ $node->feedback_dosen }}</p>
                                    <button type="button" data-feedback-more class="text-xs text-brand hover:underline mt-1">Selengkapnya</button>
                                @endif
                                <p class="text-xs text-text-secondary mt-1">
                                    {{ $node->dosen?->name ?? '—' }}
                                    · <a href="{{ route('logbook.show', $node) }}" class="text-brand hover:underline">Lihat Detail</a>
                                </p>
                            </div>
                        @endif

                        {{-- Komentar & action items menempel pada event review/feedback --}}
                        @if (! $extrasRendered && $hasExtras && in_array($ev['kind'], ['feedback', 'review'], true))
                            @php $extrasRendered = true; @endphp
                            @if ($openComments->isNotEmpty())
                                <div>
                                    <p class="text-sm font-medium text-text-primary">Komentar belum diselesaikan ({{ $openComments->count() }})</p>
                                    <div class="mt-1.5 space-y-1.5">
                                        @foreach ($openComments->take(3) as $comment)
                                            <div class="flex items-start gap-2 text-sm bg-bg-panel rounded-lg px-3 py-2">
                                                <span class="text-xs text-text-secondary mt-0.5">Hal. {{ $comment->page_number ?: '—' }}</span>
                                                <span class="flex-1 text-text-primary">{{ $comment->comment }}</span>
                                            </div>
                                        @endforeach
                                        @if ($openComments->count() > 3)
                                            <a href="{{ route('logbook.show', $node) }}" class="text-xs text-brand hover:underline">+{{ $openComments->count() - 3 }} komentar lainnya</a>
                                        @endif
                                    </div>
                                </div>
                            @endif
                            @if ($totalItems > 0)
                                <div>
                                    <p class="text-sm font-medium text-text-primary">Action Items ({{ $doneItems }}/{{ $totalItems }} selesai)</p>
                                    <div class="mt-1.5 space-y-1.5">
                                        @foreach ($node->actionItems as $item)
                                            <div class="flex items-center gap-2 text-sm">
                                                <input type="checkbox" class="action-item-toggle rounded bg-bg-surface" data-entry-id="{{ $node->id }}" data-item-id="{{ $item->id }}" @checked($item->is_done)>
                                                <span class="flex-1 {{ $item->is_done ? 'line-through text-text-secondary' : 'text-text-primary' }}">{{ $item->text }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                    @endforeach

                    {{-- Fallback: node tanpa event review tapi punya komentar/action items --}}
                    @if (! $extrasRendered && $hasExtras)
                        @if ($openComments->isNotEmpty())
                            <div>
                                <p class="text-sm font-medium text-text-primary">Komentar belum diselesaikan ({{ $openComments->count() }})</p>
                            </div>
                        @endif
                        @if ($totalItems > 0)
                            <div>
                                <p class="text-sm font-medium text-text-primary">Action Items ({{ $doneItems }}/{{ $totalItems }} selesai)</p>
                                <div class="mt-1.5 space-y-1.5">
                                    @foreach ($node->actionItems as $item)
                                        <div class="flex items-center gap-2 text-sm">
                                            <input type="checkbox" class="action-item-toggle rounded bg-bg-surface" data-entry-id="{{ $node->id }}" data-item-id="{{ $item->id }}" @checked($item->is_done)>
                                            <span class="flex-1 {{ $item->is_done ? 'line-through text-text-secondary' : 'text-text-primary' }}">{{ $item->text }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                </li>
            @endforeach
        </ol>

        {{-- Aksi primer thread --}}
        <div class="mt-4 pt-4 border-t border-border flex flex-wrap items-center gap-2">
            @if ($thread['action'])
                <a href="{{ $thread['action']['url'] }}"
                    class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90 inline-flex items-center gap-1.5">
                    <span class="material-symbols-outlined icon-sm text-accent-orange">edit_note</span> {{ $thread['action']['label'] }}
                </a>
            @elseif ($thread['actionInfo'])
                <span class="text-sm text-text-secondary">{{ $thread['actionInfo'] }}</span>
            @endif
            <a href="{{ route('logbook.show', $thread['latestEntry']) }}"
                class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">Lihat Detail</a>
        </div>
    </div>
</article>
