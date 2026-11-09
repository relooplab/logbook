<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            // Status "selesai" sepenuhnya OTOMATIS: 2 hari setelah tanggal
            // kegiatan. Dosen tidak menandai selesai; dosen hanya bisa MENAHAN
            // lewat selesai_dikecualikan (tombol "Belum Selesai").
            $table->boolean('selesai_dikecualikan')->default(false)->after('status');
            $table->timestamp('selesai_dikecualikan_at')->nullable()->after('selesai_dikecualikan');
            $table->foreignId('selesai_dikecualikan_by')->nullable()->after('selesai_dikecualikan_at')
                ->constrained('users')->nullOnDelete();

            // Setelah selesai, mahasiswa harus minta izin edit; salah satu dosen
            // (pembimbing/penguji) menyetujui, izin berlaku 7 hari.
            $table->timestamp('edit_requested_at')->nullable()->after('selesai_dikecualikan_by');
            $table->text('edit_request_note')->nullable()->after('edit_requested_at');
            $table->timestamp('edit_approved_at')->nullable()->after('edit_request_note');
            $table->foreignId('edit_approved_by')->nullable()->after('edit_approved_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['mahasiswa_ta_id', 'selesai_dikecualikan']);
        });
    }

    public function down(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            $table->dropIndex(['mahasiswa_ta_id', 'selesai_dikecualikan']);
            $table->dropConstrainedForeignId('selesai_dikecualikan_by');
            $table->dropConstrainedForeignId('edit_approved_by');
            $table->dropColumn([
                'selesai_dikecualikan',
                'selesai_dikecualikan_at',
                'edit_requested_at',
                'edit_request_note',
                'edit_approved_at',
            ]);
        });
    }
};
