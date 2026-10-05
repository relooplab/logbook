<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tautan video conference terpisah dari lokasi fisik.
     * Backfill: pindahkan URL pertama yang tertanam di `lokasi`
     * ke `meeting_link`, lalu bersihkan URL dari `lokasi`.
     */
    public function up(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            $table->string('meeting_link', 2048)->nullable()->after('lokasi');
        });

        DB::table('seminar_submissions')
            ->whereNotNull('lokasi')
            ->orderBy('id')
            ->eachById(function ($row) {
                if (! is_string($row->lokasi) || $row->lokasi === '') {
                    return;
                }
                if (! preg_match_all('~https?://[^\s<>()]+~iu', $row->lokasi, $matches)) {
                    return;
                }

                $firstUrl = null;
                foreach ($matches[0] as $match) {
                    $url = rtrim($match, '.,;!?');
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $firstUrl = $url;
                        break;
                    }
                }

                if ($firstUrl === null) {
                    return;
                }

                $locationText = trim(str_replace($matches[0], '', $row->lokasi), " \t\n\r\0\x0B,;()");

                DB::table('seminar_submissions')->where('id', $row->id)->update([
                    'meeting_link' => $firstUrl,
                    'lokasi' => $locationText !== '' ? $locationText : null,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('seminar_submissions', function (Blueprint $table) {
            $table->dropColumn('meeting_link');
        });
    }
};
