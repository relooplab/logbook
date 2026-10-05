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
    </form>

    @if ($entries->isEmpty())
        <div class="px-4 py-10 rounded-xl bg-bg-panel border border-border text-center text-text-secondary">
            <span class="material-symbols-outlined icon-lg mb-2 text-text-secondary/50">inbox</span>
            <p>Belum ada entri yang cocok.</p>
        </div>
    @else
        <div class="card p-0 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-border">
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
                    @foreach ($entries as $entry)
                        @php
                            $isMahasiswa = auth()->user()->isMahasiswa();
                            $needsAction = $isMahasiswa && in_array($entry->status, ['draft', 'revisi', 'revision_in_progress']);
                            $program = $entry->mahasiswaTa;
                            $viewer = auth()->user();
                            $canViewProgram = $program && (
                                ($viewer->isAdmin() && ($viewer->isSystemAdmin() || $viewer->institution_id === null || $program->institution_id === $viewer->institution_id))
                                || (!$viewer->isAdmin() && $viewer->isDosen() && ($program->isPembimbing($viewer) || $program->isPenguji($viewer)))
                            );
                        @endphp
                        <tr class="border-b border-border last:border-0 hover:bg-bg-panel/50 {{ $needsAction ? 'bg-status-pending/5' : '' }}">
                            @if (!$isMahasiswa)
                                <td class="py-3 px-4">
                                    @if ($canViewProgram && $program->mahasiswa)
                                        <a href="{{ route($program->isKp() ? 'mahasiswa-kp.show' : 'mahasiswa-ta.show', $program) }}" class="text-brand hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">{{ $program->mahasiswa->name }}</a>
                                    @else
                                        {{ $program?->mahasiswa?->name }}
                                    @endif
                                    @if ($entry->mahasiswaTa)
                                        <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded {{ $entry->mahasiswaTa->isKp() ? 'bg-brand/10 text-brand' : 'bg-bg-panel text-text-secondary' }}">{{ $entry->mahasiswaTa->jenisLabel() }}</span>
                                    @endif
                                </td>
                            @endif
                            <td class="py-3 px-4 font-mono">{{ $entry->jenis === 'revisi' ? '—' : $entry->sesi_ke }}</td>
                            <td class="py-3 px-4 table-col-jenis">{{ ucfirst($entry->jenis) }}</td>
                            <td class="py-3 px-4">
                                {{ $entry->topik ?? 'Revisi' }}
                                @if (!$isMahasiswa && $entry->dosen)
                                    <span class="block text-[10px] text-text-secondary">
                                        → {{ $entry->mahasiswaTa?->dosenRoleLabel($entry->dosen) ?? 'Dosen' }}: {{ $entry->dosen->name }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 table-col-tanggal font-mono">{{ $entry->tanggal_tampil?->format('d M Y') ?? '—' }}</td>
                            <td class="py-3 px-4">@include('partials.status-badge', ['status' => $entry->status, 'entry' => $entry])</td>
                            @if ($isMahasiswa)
                                <td class="py-3 px-4">
                                    @if ($entry->review_opened_at)
                                        <span class="inline-flex items-center gap-1 text-status-success text-xs" title="Dibuka dosen {{ $entry->review_opened_at->diffForHumans() }}">
                                            <span class="material-symbols-outlined icon-sm text-status-info">visibility</span> Sudah
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-text-secondary text-xs">
                                            <span class="material-symbols-outlined icon-sm text-status-info">visibility_off</span> Belum
                                        </span>
                                    @endif
                                </td>
                            @endif
                            <td class="py-3 px-4">
                                <a href="{{ route('logbook.show', $entry) }}" class="text-brand hover:underline">Detail</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-2">{{ $entries->links() }}</div>
    @endif
</div>
