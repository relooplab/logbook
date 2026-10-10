<?php

namespace App\Services;

use App\Models\LogbookEntry;

/**
 * Rekonsiliasi rantai selesai: entri berstatus revisi / sedang dikerjakan
 * yang sudah punya anak tapi tanpa anak aktif (semua approved/archived)
 * berarti jawabannya sudah diterima — tandai approved agar tidak menggantung.
 * Tanpa ini banner menuntut jawaban sementara detail terkunci tanpa aksi.
 * Berjalan sampai fixpoint (rantai bertingkat sembuh berurutan).
 * Mengembalikan jumlah baris diperbaiki.
 */
class ReconcileApprovedParents
{
    public static function run(): int
    {
        $fixed = 0;
        $active = [
            LogbookEntry::STATUS_DRAFT,
            LogbookEntry::STATUS_SUBMITTED,
            LogbookEntry::STATUS_REVISI,
            LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ];

        do {
            $pass = 0;
            LogbookEntry::whereIn('status', [LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
                ->whereExists(function ($q) {
                    $q->selectRaw('1')->from('logbook_entries as c')
                        ->whereColumn('c.parent_entry_id', 'logbook_entries.id');
                })
                ->whereNotExists(function ($q) use ($active) {
                    $q->selectRaw('1')->from('logbook_entries as c')
                        ->whereColumn('c.parent_entry_id', 'logbook_entries.id')
                        ->whereIn('c.status', $active);
                })
                ->chunkById(200, function ($entries) use (&$pass) {
                    foreach ($entries as $entry) {
                        if ($entry->isEditable()) {
                            continue;
                        }
                        $entry->update(['status' => LogbookEntry::STATUS_APPROVED]);
                        $pass++;
                    }
                });
            $fixed += $pass;
        } while ($pass > 0);

        return $fixed;
    }
}
