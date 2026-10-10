@if ($logbook->jenis === 'revisi')
    @php
        $miniRows = collect($logbook->riwayat_perbaikan ?? []);
        $miniDone = $miniRows->filter(fn ($row) => ($row['status'] ?? null) === 'Sudah')->count();
    @endphp
    <section class="card p-5 detail-workspace-card" aria-label="Status review revisi">
        <h2 class="font-heading font-semibold text-text-primary mb-3">Review</h2>
        @include('partials.status-badge', ['status' => $logbook->status])
        <dl class="grid grid-cols-3 gap-2 mt-4 text-center text-xs text-text-secondary">
            <div><dt>Total</dt><dd class="text-lg font-semibold text-text-primary mt-1">{{ $miniRows->count() }}</dd></div>
            <div><dt>Sudah dibetulkan</dt><dd class="text-lg font-semibold text-status-success mt-1">{{ $miniDone }}</dd></div>
            <div><dt>Belum dibetulkan</dt><dd class="text-lg font-semibold text-status-danger mt-1">{{ $miniRows->count() - $miniDone }}</dd></div>
        </dl>
        @php $miniPercent = $miniRows->count() ? (int) round($miniDone / $miniRows->count() * 100) : 0; @endphp
        <div class="mt-4 h-2 rounded-full bg-bg-hover overflow-hidden" role="progressbar" aria-label="Progres review revisi" aria-valuenow="{{ $miniDone }}" aria-valuemin="0" aria-valuemax="{{ $miniRows->count() }}"><div class="h-full bg-status-success rounded-full" style="width: {{ $miniPercent }}%"></div></div>
    </section>
@endif
