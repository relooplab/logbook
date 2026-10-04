<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda digest chat per conversation (anti-spam):
     * - chat_digest_sent_at: digest hari ke-2 sudah terkirim untuk episode ini.
     * - chat_escalation_sent_at: eskalasi hari ke-7 sudah terkirim.
     * Keduanya di-null-kan lagi saat ada pesan baru (episode baru).
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('chat_digest_sent_at')->nullable()->after('updated_at');
            $table->timestamp('chat_escalation_sent_at')->nullable()->after('chat_digest_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['chat_digest_sent_at', 'chat_escalation_sent_at']);
        });
    }
};
