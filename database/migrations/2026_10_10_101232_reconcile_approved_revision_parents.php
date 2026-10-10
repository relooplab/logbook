<?php

use App\Services\ReconcileApprovedParents;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Perbaikan data: rantai revisi yang sudah selesai (anak terminal,
     * induk tertinggal di revisi) ditutup ke approved agar banner tidak
     * menuntut jawaban yang tak bisa dikerjakan.
     */
    public function up(): void
    {
        ReconcileApprovedParents::run();
    }

    /**
     * Perbaikan data satu arah (tidak bisa dikembalikan otomatis).
     */
    public function down(): void
    {
        //
    }
};
