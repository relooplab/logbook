<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda file terakhir yang masuk rekap email (watermark per id).
     * Perbandingan pakai id (monotonik), bukan created_at — upload dalam
     * detik yang sama dengan email sebelumnya tidak boleh hilang dari
     * rekap berikutnya.
     */
    public function up(): void
    {
        Schema::table('workspace_upload_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('last_sent_file_id')->nullable()->after('last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_upload_notifications', function (Blueprint $table) {
            $table->dropColumn('last_sent_file_id');
        });
    }
};