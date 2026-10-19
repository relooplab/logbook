<?php

namespace App\Services;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Antrean "bahan mahasiswa yang belum ditinjau" untuk seorang dosen.
 *
 * Konsep review gate:
 *  - Logbook/revisi: entry ber-status `submitted` (belum di-approve/revisi)
 *    di mana dosen ini adalah reviewer (pembimbing 1/2 atau dosen_id entry).
 *  - Seminar/sidang: submission ber-status `submitted` yang belum dibaca
 *    (belum ada SeminarSubmissionRead) oleh dosen ini, pada program yang
 *    melibatkan dosen sebagai pembimbing/penguji.
 */
class MaterialsReviewQueue
{
    /**
     * Program (TA/KP) yang dibimbing dosen ini.
     */
    private function guidedTaIds(User $user): Collection
    {
        return MahasiswaTa::where('pembimbing_1_id', $user->id)
            ->orWhere('pembimbing_2_id', $user->id)
            ->whereNotIn('status_ta', [MahasiswaTa::STATUS_PENDING_APPROVAL, MahasiswaTa::STATUS_DITOLAK])
            ->pluck('id');
    }

    /**
     * Logbook/revisi yang menunggu review dosen ini.
     */
    public function pendingLogbook(User $user): Collection
    {
        $taIds = $this->guidedTaIds($user);

        return LogbookEntry::where('status', LogbookEntry::STATUS_SUBMITTED)
            ->where(function ($q) use ($taIds, $user) {
                $q->whereIn('mahasiswa_ta_id', $taIds)
                    ->orWhere('dosen_id', $user->id);
            })
            ->whereHas('mahasiswaTa', fn ($q) => $q->whereNotIn('status_ta', [MahasiswaTa::STATUS_PENDING_APPROVAL, MahasiswaTa::STATUS_DITOLAK]))
            ->with(['mahasiswaTa.mahasiswa'])
            // Antrean dilayani FIFO (tertua dulu), selaras urutan quick-review.
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Seminar/sidang yang belum dibaca dosen ini.
     */
    public function pendingSeminar(User $user): Collection
    {
        return SeminarSubmission::where('status', SeminarSubmission::STATUS_SUBMITTED)
            ->whereHas('mahasiswaTa', function ($q) use ($user) {
                $q->where(function ($qq) use ($user) {
                    $qq->where('pembimbing_1_id', $user->id)
                        ->orWhere('pembimbing_2_id', $user->id)
                        ->orWhere('penguji_1_id', $user->id)
                        ->orWhere('penguji_2_id', $user->id);
                })->whereNotIn('status_ta', [MahasiswaTa::STATUS_PENDING_APPROVAL, MahasiswaTa::STATUS_DITOLAK]);
            })
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->with(['mahasiswaTa.mahasiswa'])
            // Antrean dilayani FIFO (tertua dulu).
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Semua bahan belum ditinjau, dikelompokkan per jenis.
     *
     * @return array{logbook: Collection, seminar: Collection}
     */
    public function pendingFor(User $user): array
    {
        return [
            'logbook' => $this->pendingLogbook($user),
            'seminar' => $this->pendingSeminar($user),
        ];
    }

    /**
     * Total bahan belum ditinjau (untuk gate redirect).
     */
    public function countFor(User $user): int
    {
        return $this->pendingLogbook($user)->count() + $this->pendingSeminar($user)->count();
    }
}
