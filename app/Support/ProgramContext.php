<?php

namespace App\Support;

use App\Models\MahasiswaTa;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolve program (TA/KP) yang sedang aktif untuk mahasiswa.
 *
 * Prioritas:
 * 1. Program yang dipilih via ?program=ta|kp
 * 2. Program aktif (status_ta = aktif)
 * 3. Program pertama
 */
class ProgramContext
{
    /**
     * Resolve program untuk user mahasiswa.
     */
    public static function resolve(?User $user, ?Request $request = null): ?MahasiswaTa
    {
        if (!$user || !$user->isMahasiswa()) {
            return null;
        }

        $programs = $user->allPrograms()->with(['pembimbing1', 'pembimbing2', 'penguji1', 'penguji2', 'members'])->get();
        if ($programs->isEmpty()) {
            return null;
        }

        $requested = $request?->input('program');
        if ($requested === 'kp' || $requested === 'ta') {
            $selected = $programs->firstWhere('jenis', $requested);
            if ($selected) {
                return $selected;
            }
        }

        // Prioritas: program aktif → program pertama (bisa termasuk ditolak,
        // agar dashboard bisa menampilkan alasan penolakan).
        return $user->programAktif() ?: $programs->first();
    }

    /**
     * Semua program milik user mahasiswa.
     */
    public static function programs(?User $user): \Illuminate\Support\Collection
    {
        if (!$user || !$user->isMahasiswa()) {
            return collect();
        }

        return $user->allPrograms()->with(['pembimbing1', 'pembimbing2', 'penguji1', 'penguji2', 'members'])->get();
    }
}