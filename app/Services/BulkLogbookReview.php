<?php

namespace App\Services;

use App\Events\EntryStatusChanged;
use App\Models\LogbookEntry;
use App\Models\PdfComment;
use App\Models\ReviewDraft;
use App\Models\User;
use App\Support\Audit;

/**
 * Aksi massal pada antrean review bahan (/review-bahan): setujui & arsipkan.
 *
 * Berbeda dari aksi satuan di LogbookController yang memakai abort 403 saat
 * entri sudah diproses reviewer lain, di sini keputusan yang gagal (CAS) cukup
 * dilewati dan dihitung sebagai "dilewati" agar satu entri basi tidak
 * membatalkan seluruh batch.
 */
class BulkLogbookReview
{
    public function __construct(private LogbookReviewTransition $transition)
    {
    }

    /**
     * Setujui entri. Mengembalikan false bila entri sudah diproses lebih dulu.
     */
    public function approve(LogbookEntry $entry, User $reviewer): bool
    {
        if (! $this->transition->tryApply($entry, LogbookEntry::STATUS_APPROVED, [
            'feedback_dosen' => null,
            'reviewed_at' => now(),
        ])) {
            return false;
        }

        $entry->refresh();
        // Rantai revisi: anak yang disetujui menutup induknya (sama seperti aksi satuan).
        $this->transition->reconcileApprovedChain($entry);
        $this->resolveComments($entry);
        $this->clearDraft($entry, $reviewer);

        return true;
    }

    /**
     * Arsipkan entri tanpa memusnahkan berkas. False bila sudah diproses.
     */
    public function archive(LogbookEntry $entry, User $reviewer, ?string $reason = null): bool
    {
        $archivedAt = now();

        if (! $this->transition->tryApply($entry, LogbookEntry::STATUS_ARCHIVED, [
            'archive_reason' => $reason,
            'archived_by' => $reviewer->id,
            'archived_at' => $archivedAt,
            'reviewed_at' => $archivedAt,
        ])) {
            return false;
        }

        $entry->refresh();
        $this->clearDraft($entry, $reviewer);

        Audit::log('Dosen arsipkan entri logbook (massal)', [
            'entry_id' => $entry->id,
            'archived_by' => $reviewer->id,
            'reason' => $reason,
        ]);

        try {
            EntryStatusChanged::dispatch($entry, 'Entri Anda diarsipkan oleh dosen.');
        } catch (\Throwable $e) {
            report($e);
        }

        $entry->notifyParties(
            'Entri '.($entry->jenis === LogbookEntry::JENIS_REVISI ? 'revisi' : 'logbook sesi '.$entry->sesi_ke)
                .' diarsipkan oleh dosen.'.($reason ? ' Alasan: '.$reason : ''),
            route('logbook.show', $entry),
            'Entri Diarsipkan',
        );

        return true;
    }

    /**
     * Tandai seluruh komentar PDF pada entri (dan induknya) selesai.
     */
    private function resolveComments(LogbookEntry $entry): void
    {
        foreach (collect([$entry, $entry->parentEntry])->filter() as $target) {
            $target->comments()
                ->where('resolution_status', '!=', PdfComment::STATUS_RESOLVED)
                ->get()
                ->each(function (PdfComment $comment) {
                    $comment->setResolutionStatus(PdfComment::STATUS_RESOLVED);
                    $comment->save();
                });
        }
    }

    /**
     * Keputusan final membuang draf milik reviewer ini.
     */
    private function clearDraft(LogbookEntry $entry, User $reviewer): void
    {
        ReviewDraft::where('logbook_entry_id', $entry->id)
            ->where('user_id', $reviewer->id)
            ->delete();
    }
}
