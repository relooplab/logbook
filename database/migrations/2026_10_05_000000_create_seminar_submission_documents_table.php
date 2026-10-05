<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dokumen tambahan opsional pada submission seminar/sidang:
     * file (maks 3, total 10 MB) dan tautan (mis. Google Drive).
     */
    public function up(): void
    {
        Schema::create('seminar_submission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seminar_submission_id')->constrained('seminar_submissions')->cascadeOnDelete();
            $table->string('type', 10); // file | link
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('url', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seminar_submission_documents');
    }
};
