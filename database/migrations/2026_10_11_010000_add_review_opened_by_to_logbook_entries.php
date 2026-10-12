<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pencatat reviewer terakhir yang membuka PDF (sinyal sejawat di antrean).
     */
    public function up(): void
    {
        Schema::table('logbook_entries', function (Blueprint $table) {
            $table->foreignId('review_opened_by')->nullable()->after('review_opened_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logbook_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_opened_by');
        });
    }
};
