@php
    $activeFilters = collect($filters)->except('per_page')->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty();
    $metrics = ['total' => 'Total Entri', 'logbook' => 'Logbook', 'revisi' => 'Revisi', 'pending' => 'Menunggu Review', 'revision_requested' => 'Revisi Diminta'];
@endphp
<div class="logbook-history space-y-5">
    <x-page-header subtitle="BIMBINGAN" title="Logbook Bimbingan" description="Riwayat logbook dan revisi mahasiswa yang Anda bimbing atau uji." />

    <section aria-label="Ringkasan riwayat" class="history-metrics">
        @foreach ($metrics as $key => $label)
            <div class="history-metric">
                <span class="text-xl font-bold tabular-nums">{{ (int) $summary->$key }}</span>
                <span class="text-xs text-text-secondary">{{ $label }}</span>
            </div>
        @endforeach
    </section>
    <p class="text-xs text-text-secondary">Ringkasan seluruh riwayat yang dapat Anda akses, sebelum filter. Status dihitung sesuai status tersimpan.</p>

    <form method="GET" action="{{ route('logbook.index') }}" class="card p-4 space-y-3" aria-label="Pencarian dan filter riwayat">
        <div class="history-search">
            <div class="min-w-0 flex-1">
                <label for="history-keyword" class="block text-xs font-medium text-text-secondary mb-1.5">Cari riwayat</label>
                <input id="history-keyword" type="search" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="Cari mahasiswa, NIM, topik, atau isi..." class="history-input">
            </div>
            <button type="submit" class="btn-primary px-5 py-2.5 text-sm font-semibold">Cari</button>
        </div>
        <div class="history-filters">
            <div>
                <label for="history-jenis" class="history-label">Jenis</label>
                <select id="history-jenis" name="jenis" class="history-input">
                    <option value="">Semua jenis</option>
                    @foreach ([\App\Models\LogbookEntry::JENIS_LOGBOOK, \App\Models\LogbookEntry::JENIS_REVISI] as $type)
                        <option value="{{ $type }}" @selected(($filters['jenis'] ?? '') === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="history-status" class="history-label">Status</label>
                <select id="history-status" name="status" class="history-input">
                    <option value="">Semua status</option>
                    @foreach (\App\Models\LogbookEntry::STATUS_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="history-student" class="history-label">Mahasiswa</label>
                <select id="history-student" name="mahasiswa_id" class="history-input">
                    <option value="">Semua mahasiswa</option>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}" @selected((string) ($filters['mahasiswa_id'] ?? '') === (string) $student->id)>{{ $student->name }}{{ $student->nim ? ' · '.$student->nim : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="history-from" class="history-label">Dari tanggal</label>
                <input id="history-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="history-input">
            </div>
            <div>
                <label for="history-to" class="history-label">Sampai tanggal</label>
                <input id="history-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="history-input">
            </div>
            <a href="{{ route('logbook.index') }}" class="btn-secondary px-3 py-2.5 text-sm text-center">Reset Filter</a>
        </div>
        <input type="hidden" name="per_page" value="{{ $entries->perPage() }}">
        @if (request()->filled('program')) <input type="hidden" name="program" value="{{ request('program') }}"> @endif
        <p class="text-xs text-text-secondary">Rentang tanggal mengikuti tanggal bimbingan (logbook) / tanggal pengiriman (revisi).</p>
    </form>

    <section class="card overflow-hidden" aria-label="Daftar riwayat">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4">
            <nav class="flex gap-4" aria-label="Jenis entri">
                @foreach (['' => 'Semua', \App\Models\LogbookEntry::JENIS_LOGBOOK => 'Logbook', \App\Models\LogbookEntry::JENIS_REVISI => 'Revisi'] as $type => $label)
                    <a href="{{ route('logbook.index', array_merge(request()->except('page', 'jenis'), $type ? ['jenis' => $type] : [])) }}" class="history-tab {{ ($filters['jenis'] ?? '') === $type ? 'is-active' : '' }}" @if (($filters['jenis'] ?? '') === $type) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <span class="text-xs text-text-secondary py-2">{{ $entries->total() }} entri</span>
        </div>
        @if ($entries->isEmpty())
            <div class="px-4 py-12 text-center text-text-secondary" role="status">
                <span class="material-symbols-outlined icon-lg mb-3" aria-hidden="true">menu_book</span>
                <p>{{ $activeFilters ? 'Tidak ada entri yang cocok dengan pencarian atau filter.' : 'Belum ada riwayat logbook atau revisi.' }}</p>
                @if ($activeFilters) <a href="{{ route('logbook.index') }}" class="inline-block mt-4 text-brand hover:underline">Reset Filter</a> @endif
            </div>
        @else
            <div class="history-desktop">
                <table class="history-table">
                    <caption class="sr-only">Riwayat logbook dan revisi mahasiswa</caption>
                    <thead><tr>
                        @foreach (['Mahasiswa', 'Entri', 'Topik', 'Tanggal', 'Status', 'Aksi'] as $column)
                            <th scope="col">{{ $column }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td>@include('logbook.partials.student-identity')</td>
                                <td>@include('logbook.partials.entry-type')</td>
                                <td>
                                    <p class="font-medium">{{ $entry->topik ?: 'Revisi' }}</p>
                                    @if ($entry->dosen && $entry->dosen_id !== auth()->id())
                                        <p class="mt-1 text-xs text-text-secondary">Penerima: {{ $entry->mahasiswaTa?->dosenRoleLabel($entry->dosen) ?? 'Dosen' }} · {{ $entry->dosen->name }}</p>
                                    @endif
                                </td>
                                <td class="text-xs font-mono whitespace-nowrap">{{ $entry->tanggal_tampil?->format('d M Y') ?? '—' }}</td>
                                <td>@include('partials.status-badge', ['status' => $entry->status, 'entry' => $entry])</td>
                                <td><a href="{{ route('logbook.show', $entry) }}" class="history-detail" aria-label="Lihat detail {{ $entry->jenis }} {{ $entry->mahasiswaTa?->mahasiswa?->name }}">Detail <span aria-hidden="true">→</span></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="history-mobile">
                @foreach ($entries as $entry)
                    <article class="history-mobile-entry" aria-label="Entri {{ $entry->jenis }} {{ $entry->mahasiswaTa?->mahasiswa?->name }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">@include('logbook.partials.student-identity')</div>
                            <div class="shrink-0 max-w-[45%]">@include('partials.status-badge', ['status' => $entry->status, 'entry' => $entry])</div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
                            @include('logbook.partials.entry-type')
                            <time class="text-xs font-mono text-text-secondary">{{ $entry->tanggal_tampil?->format('d M Y') ?? '—' }}</time>
                        </div>
                        <p class="mt-3 text-xs text-text-secondary">Topik</p>
                        <p class="mt-1 text-sm font-medium">{{ $entry->topik ?: 'Revisi' }}</p>
                        @if ($entry->dosen && $entry->dosen_id !== auth()->id())
                            <p class="mt-1 text-xs text-text-secondary">Penerima: {{ $entry->mahasiswaTa?->dosenRoleLabel($entry->dosen) ?? 'Dosen' }} · {{ $entry->dosen->name }}</p>
                        @endif
                        <a href="{{ route('logbook.show', $entry) }}" class="history-detail inline-block mt-3" aria-label="Lihat detail {{ $entry->jenis }} {{ $entry->mahasiswaTa?->mahasiswa?->name }}">Lihat Detail <span aria-hidden="true">→</span></a>
                    </article>
                @endforeach
            </div>
        @endif
        <footer class="history-footer border-t border-border px-4 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <p class="text-xs text-text-secondary">Menampilkan {{ $entries->firstItem() ?? 0 }}–{{ $entries->lastItem() ?? 0 }} dari {{ $entries->total() }} entri</p>
                <form method="GET" action="{{ route('logbook.index') }}" class="flex items-center gap-2">
                    @foreach (request()->except('page', 'per_page') as $key => $value)
                        @if (is_scalar($value)) <input type="hidden" name="{{ $key }}" value="{{ $value }}"> @endif
                    @endforeach
                    <label for="history-page-size" class="sr-only">Entri per halaman</label>
                    <select id="history-page-size" name="per_page" class="history-input history-page-size">
                        @foreach ([20, 50, 100] as $size) <option value="{{ $size }}" @selected($entries->perPage() === $size)>{{ $size }} / halaman</option> @endforeach
                    </select>
                    <button type="submit" class="btn-secondary px-2.5 py-2 text-xs">Terapkan</button>
                </form>
            </div>
            {{ $entries->links('logbook.partials.pagination') }}
        </footer>
    </section>
</div>
