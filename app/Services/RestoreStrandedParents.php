<?php

namespace App\Services;

use App\Models\LogbookEntry;

/**
 * Perbaikan induk yatim: entri revision_in_progress yang revisi aktifnya
 * sudah dihapus akan menggantung permanen (tidak bisa edit/kirim/
 * buat-revisi). Kembalikan ke "revisi" agar bisa ditindaklanjuti lagi.
 * Hanya menyentuh baris tanpa anak SAMA SEKALI agar tidak mengubah rantai
 * revisi yang masih punya riwayat. Mengembalikan jumlah baris diperbaiki.
 */
class RestoreStrandedParents
{
    public static function run(): int
    {
        $fixed = 0;

        LogbookEntry::where('status', LogbookEntry::STATUS_REVISION_IN_PROGRESS)
            ->whereNotNull('submitted_at')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('logbook_entries as c')
                    ->whereColumn('c.parent_entry_id', 'logbook_entries.id');
            })
            ->chunkById(200, function ($entries) use (&$fixed) {
                foreach ($entries as $entry) {
                    if (! $entry->isEditable()) {
                        $entry->update(['status' => LogbookEntry::STATUS_REVISI]);
                        $fixed++;
                    }
                }
            });

        return $fixed;
    }
}
