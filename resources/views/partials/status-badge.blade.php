@php
    // Status program (mahasiswa_ta): Aktif / Menunggu / Selesai / Ditolak.
    $taMap = [
        "aktif" => ["badge-success", "Aktif"],
        "pending_approval" => ["badge-pending", "Menunggu Persetujuan"],
        "tamat" => ["badge-neutral", "Selesai"],
        "ditolak" => ["badge-danger", "Ditolak"],
    ];
    $map = [
        "draft" => ["badge-neutral", "Draf"],
        "submitted" => ["badge-pending", "Menunggu Review"],
        "approved" => ["badge-success", "Disetujui"],
        "revisi" => ["badge-danger", "Revisi Diminta"],
        "archived" => ["badge-neutral", "Diarsipkan"],
        "revision_in_progress" => ["badge-pending", "Revisi Sedang Dikerjakan"],
    ];

    if (isset($taMap[$status])) {
        [$cls, $label] = $taMap[$status];
    } else {
        // Label status operasional: statusLabel() model (utk LogbookEntry),
        // fallback ke mapping sederhana.
        $label = isset($entry) && $entry instanceof \App\Models\LogbookEntry
            ? $entry->statusLabel()
            : (\App\Models\LogbookEntry::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)));
        [$cls, $defaultLabel] = $map[$status] ?? ["badge-neutral", $label];
        $label = $label ?: $defaultLabel;
    }
@endphp

<span class="badge {{ $cls }}">{{ $label }}</span>