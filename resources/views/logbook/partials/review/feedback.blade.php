@if ($logbook->feedback_dosen)
    @php
        $feedbackReviewer = ($logbook->dosen && $logbook->mahasiswaTa)
            ? (($logbook->mahasiswaTa->dosenRoleLabel($logbook->dosen) ? $logbook->mahasiswaTa->dosenRoleLabel($logbook->dosen).' — ' : '').$logbook->dosen->name)
            : ($logbook->mahasiswaTa?->pembimbing1?->name ?? 'dosen');
    @endphp
    <section class="card border-status-pending/30 bg-status-pending/10 p-5 sm:p-6" aria-labelledby="feedback-title">
        <div class="flex items-center gap-3 mb-3">
            <span class="icon-chip h-10 w-10" aria-hidden="true">
                <span class="material-symbols-outlined icon-md text-status-pending">forum</span>
            </span>
            <div class="min-w-0">
                <h2 id="feedback-title" class="font-heading font-semibold text-lg text-text-primary">Pesan Dosen</h2>
                <p class="text-xs text-text-secondary mt-0.5">oleh {{ $feedbackReviewer }} · {{ $logbook->reviewed_at?->format('d M Y') ?? '—' }}</p>
            </div>
        </div>
        <div class="text-sm whitespace-pre-wrap">{{ $logbook->feedback_dosen }}</div>
        @php
            // Komentar manual dosen (ditulis langsung, bukan hasil anotasi PDF
            // yang berpayload geometri) di seluruh rantai: pelengkap feedback;
            // yang berpayload tampil di tabel Anotasi PDF sesi.
            $supportChainIds = [$logbook->id];
            $supportCursor = $logbook->parentEntry;
            $supportDepth = 0;
            while ($supportCursor && $supportDepth < 5) {
                $supportChainIds[] = $supportCursor->id;
                $supportCursor = $supportCursor->parentEntry;
                $supportDepth++;
            }
            $supportingComments = \App\Models\PdfComment::whereIn('logbook_entry_id', $supportChainIds)
                ->whereNull('payload')
                ->whereIn('resolution_status', [\App\Models\PdfComment::STATUS_OPEN, \App\Models\PdfComment::STATUS_ADDRESSED])
                ->with(['user', 'replies.user'])
                ->orderBy('logbook_entry_id')
                ->orderBy('id')
                ->get()
                ->filter(fn ($c) => $c->user?->isDosen());
        @endphp
        @if ($supportingComments->isNotEmpty())
            <ol class="mt-3 space-y-1.5 text-sm">
                @foreach ($supportingComments as $sc)
                    <li>{{ $loop->iteration }}. ({{ $sc->logbook_entry_id === $logbook->id ? 'Sesi ini' : 'Sesi sebelumnya, entri #'.$sc->logbook_entry_id }} · dosen) {{ $sc->comment }}
                        @php $lastReply = $sc->replies->last(); @endphp
                        @if ($lastReply)
                            <span class="block text-xs text-text-secondary">→ dibalas {{ $lastReply->user?->isDosen() ? 'dosen' : 'mahasiswa' }}: {{ $lastReply->body }}</span>
                        @elseif ($sc->reply)
                            <span class="block text-xs text-text-secondary">→ dibalas mahasiswa: {{ $sc->reply }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endif
