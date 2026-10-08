<?php

use App\Services\RestoreStrandedParents;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Perbaikan data: kembalikan induk yatim (revision_in_progress yang
     * revisi aktifnya sudah dihapus) ke "revisi" agar bisa ditindaklanjuti
     * lagi. Tanpa induk yatim, entri mati permanen (tidak bisa edit/kirim/
     * buat-revisi). Hanya menyentuh baris tanpa anak SAMA SEKALI agar tidak
     * mengubah rantai revisi yang masih punya riwayat.
     */
    public function up(): void
    {
        RestoreStrandedParents::run();
    }

    /**
     * Perbaikan data satu arah (tidak bisa dikembalikan otomatis).
     */
    public function down(): void
    {
        //
    }
};
