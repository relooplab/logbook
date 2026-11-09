<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_files', function (Blueprint $table) {
            // Field form asal berkas arsip (undangan / materi / dokumen_tambahan).
            // Dipakai untuk membatasi jumlah riwayat: maksimal 4 berkas per field,
            // berkas terlama otomatis tertimpa saat mahasiswa mengganti lagi.
            $table->string('archive_field')->nullable()->after('archive_note');

            $table->index(['mahasiswa_ta_id', 'archive_field']);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_files', function (Blueprint $table) {
            $table->dropIndex(['mahasiswa_ta_id', 'archive_field']);
            $table->dropColumn('archive_field');
        });
    }
};
