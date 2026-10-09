<span class="inline-flex items-center gap-1.5 text-sm">
    <span class="material-symbols-outlined icon-sm text-text-secondary" aria-hidden="true">{{ $entry->jenis === \App\Models\LogbookEntry::JENIS_REVISI ? 'edit_note' : 'menu_book' }}</span>
    {{ ucfirst($entry->jenis) }}@if ($entry->jenis === \App\Models\LogbookEntry::JENIS_LOGBOOK && $entry->sesi_ke) · Sesi {{ $entry->sesi_ke }}@endif@if ($entry->jenis === \App\Models\LogbookEntry::JENIS_REVISI && !$entry->parent_entry_id) · tanpa entri asal@endif
</span>
