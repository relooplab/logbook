@php
    $isMahasiswa = auth()->user()->isMahasiswa();
    $needsAction = $isMahasiswa && in_array($entry->status, ['draft', 'revisi', 'revision_in_progress']);
    $program = $entry->mahasiswaTa;
    $viewer = auth()->user();
    $canViewProgram = $program && (
        ($viewer->isAdmin() && ($viewer->isSystemAdmin() || $viewer->institution_id === null || $program->institution_id === $viewer->institution_id))
        || (!$viewer->isAdmin() && $viewer->isDosen() && ($program->isPembimbing($viewer) || $program->isPenguji($viewer)))
    );
    $threadChild = $threadChild ?? false;
    $parentId = $parentId ?? null;
    $childCount = $childCount ?? 0;
    $canResume = $threadChild && $isMahasiswa && auth()->user()->can('update', $entry);
@endphp
<tr class="border-b border-border last:border-0 hover:bg-bg-panel/50 {{ $needsAction ? 'bg-status-pending/5' : '' }}"
    @if ($threadChild) data-thread-child="{{ $parentId }}" @else data-thread="entry-{{ $entry->id }}" @endif>
    @if ($bulkForm)
        @php $deletable = $entry->isEditable(); @endphp
        <td class="py-3 px-4">
            <input type="checkbox" name="ids[]" value="{{ $entry->id }}" class="bulk-checkbox"
                @disabled(! $deletable) title="{{ $deletable ? 'Pilih untuk hapus' : 'Hanya draf / revisi sedang dikerjakan yang bisa dihapus' }}">
        </td>
    @endif
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
    <td class="py-3 px-4 table-col-jenis">
        @if ($threadChild)
            <span title="Jawaban untuk entri #{{ $parentId }}">↳ Revisi</span>
        @else
            {{ ucfirst($entry->jenis) }}
            @if ($entry->parent_entry_id)
                <a href="{{ route('logbook.show', $entry->parent_entry_id) }}" class="block text-[10px] text-brand hover:underline">↳ jawaban entri #{{ $entry->parent_entry_id }}</a>
            @endif
        @endif
    </td>
    <td class="py-3 px-4">
        @if ($threadChild)<span class="text-text-secondary" aria-hidden="true">↳ </span>@endif{{ $entry->topik ?? 'Revisi' }}
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
        @if ($canResume)
            <a href="{{ route('logbook.edit', $entry) }}" class="ml-2 font-medium text-brand hover:underline">Lanjutkan draf →</a>
        @endif
    </td>
</tr>
