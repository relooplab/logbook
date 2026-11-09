<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            // Dosen menandai seminar/sidang benar-benar SELESAI. Sebelum ini,
            // mahasiswa bebas mengedit dan mengganti berkas tanpa arsip.
            $table->timestamp('selesai_at')->nullable()->after('status');
            $table->foreignId('selesai_by')->nullable()->after('selesai_at')
                ->constrained('users')->nullOnDelete();

            // Setelah selesai, mahasiswa harus minta izin edit dulu; salah satu
            // dosen (pembimbing/penguji) menyetujui.
            $table->timestamp('edit_requested_at')->nullable()->after('selesai_by');
            $table->text('edit_request_note')->nullable()->after('edit_requested_at');
            $table->timestamp('edit_approved_at')->nullable()->after('edit_request_note');
            $table->foreignId('edit_approved_by')->nullable()->after('edit_approved_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['mahasiswa_ta_id', 'selesai_at']);
        });
    }

    public function down(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            $table->dropIndex(['mahasiswa_ta_id', 'selesai_at']);
            $table->dropConstrainedForeignId('edit_approved_by');
            $table->dropConstrainedForeignId('selesai_by');
            $table->dropColumn(['selesai_at', 'edit_requested_at', 'edit_request_note', 'edit_approved_at']);
        });
    }
};
