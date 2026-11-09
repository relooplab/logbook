<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            // Status "selesai" otomatis berlaku 2 hari setelah tanggal kegiatan.
            // Dosen dapat menahannya secara manual (selesai_dikecualikan = true),
            // dan pengecualian itu menang atas aturan otomatis.
            $table->boolean('selesai_dikecualikan')->default(false)->after('selesai_by');
            $table->timestamp('selesai_dikecualikan_at')->nullable()->after('selesai_dikecualikan');
            $table->foreignId('selesai_dikecualikan_by')->nullable()->after('selesai_dikecualikan_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selesai_dikecualikan_by');
            $table->dropColumn(['selesai_dikecualikan', 'selesai_dikecualikan_at']);
        });
    }
};
