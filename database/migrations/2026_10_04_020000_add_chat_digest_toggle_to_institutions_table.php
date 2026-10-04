<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Toggle digest chat tertunda per institusi (default ON).
     * - chat_digest_enabled: digest pesan belum dibaca (H+2) + eskalasi (H+7).
     */
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->boolean('chat_digest_enabled')->default(true)->after('daily_reminder_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['chat_digest_enabled']);
        });
    }
};
