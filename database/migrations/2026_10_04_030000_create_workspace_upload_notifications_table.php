<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda email rekap upload workspace per (dosen penerima × program).
     * - last_sent_at: kapan email terakhir dikirim — jendela throttle 24 jam.
     * - Setiap pembimbing punya jendelanya sendiri (P1 ≠ P2).
     * - File yang belum masuk email = file dengan created_at > last_sent_at
     *   (atau batch saat ini bila penanda belum ada).
     */
    public function up(): void
    {
        Schema::create('workspace_upload_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mahasiswa_ta_id')->constrained('mahasiswa_ta')->cascadeOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'mahasiswa_ta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_upload_notifications');
    }
};