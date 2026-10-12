<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Draf keputusan review dosen per entri (satu draf per dosen per entri).
     */
    public function up(): void
    {
        Schema::create('review_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logbook_entry_id')->constrained('logbook_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('feedback_dosen')->nullable();
            $table->string('review_decision', 20)->nullable();
            $table->text('archive_reason')->nullable();
            $table->timestamps();

            $table->unique(['logbook_entry_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_drafts');
    }
};
