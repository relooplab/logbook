<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_files', function (Blueprint $table) {
            // Arsip = berkas lama yang diganti mahasiswa saat mengedit bahan
            // seminar/sidang. Berkas sengaja TIDAK dihapus agar riwayat utuh;
            // hanya dosen pembimbing/penguji (atau admin) yang boleh menghapus.
            $table->boolean('is_archived')->default(false)->after('bab');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
            $table->string('archive_note')->nullable()->after('archived_at');

            $table->index(['mahasiswa_ta_id', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_files', function (Blueprint $table) {
            $table->dropIndex(['mahasiswa_ta_id', 'is_archived']);
            $table->dropColumn(['is_archived', 'archived_at', 'archive_note']);
        });
    }
};
