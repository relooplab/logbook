<?php

namespace App\Services;

use App\Models\LogbookEntry;

class LogbookReviewTransition
{
    public function apply(LogbookEntry $entry, string $status, array $attributes = []): void
    {
        abort_unless($this->tryApply($entry, $status, $attributes), 403, 'Entri sudah diproses reviewer lain. Muat ulang halaman.');

        // Rantai selesai: anak revisi yang disetujui menutup induknya.
        // Tanpa ini induk tertinggal di "revisi" selamanya: banner menuntut
        // jawaban tapi halaman detail terkunci tanpa aksi.
        if ($status === LogbookEntry::STATUS_APPROVED) {
            $this->reconcileApprovedChain($entry->fresh());
        }
    }

    public function tryApply(LogbookEntry $entry, string $status, array $attributes = []): bool    {
        // CAS: keputusan lain yang menang lebih dulu tak boleh ditimpa model stale.
        $updated = LogbookEntry::whereKey($entry->id)
            ->where('status', LogbookEntry::STATUS_SUBMITTED)
            ->update(array_merge($attributes, ['status' => $status]));
        if ($updated === 1) {
            $entry->refresh();
            return true;
        }

        return false;
    }

    /**
     * Naik rantai induk: induk berstatus revisi / sedang dikerjakan yang
     * sudah tanpa anak aktif ikut disetujui (jawabannya sudah diterima).
     * Berhenti di induk yang masih punya anak aktif atau status terminal.
     */
    public function reconcileApprovedChain(LogbookEntry $entry): void
    {
        $active = [
            LogbookEntry::STATUS_DRAFT,
            LogbookEntry::STATUS_SUBMITTED,
            LogbookEntry::STATUS_REVISI,
            LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ];

        $cursor = $entry->parentEntry;
        $guard = 0;
        while ($cursor && $guard < 20) {
            $guard++;
            if (! in_array($cursor->status, [LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS], true)) {
                break;
            }
            if ($cursor->revisionChildren()->whereIn('status', $active)->exists()) {
                break;
            }
            $cursor->update(['status' => LogbookEntry::STATUS_APPROVED]);
            $cursor = $cursor->parentEntry;
        }
    }
}
