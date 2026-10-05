<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\PdfComment;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use Illuminate\Console\Command;

class SendWeeklyDigest extends Command
{
    protected $signature = 'ta:weekly-digest';

    protected $description = 'Kirim digest mingguan (setiap Senin) ke dosen & mahasiswa';

    public function handle(): int
    {
        // ---------- Dosem ----------
        $dosens = User::role('dosen')->get();
        foreach ($dosens as $dosen) {
            // Toggle per institusi penerima: institusi yang menonaktifkan
            // digest dilewati, institusi lain tetap menerima.
            if (! Institution::forUser($dosen)->isWeeklyDigestEnabled()) {
                continue;
            }

            $taIds = MahasiswaTa::where('pembimbing_1_id', $dosen->id)
                ->orWhere('pembimbing_2_id', $dosen->id)
                ->pluck('id');

            $waiting = LogbookEntry::whereIn('mahasiswa_ta_id', $taIds)
                ->where('status', LogbookEntry::STATUS_SUBMITTED)->count();

            $redCount = 0;
            $taRows = MahasiswaTa::whereIn('id', $taIds)->get();
            foreach ($taRows as $ta) {
                if ($ta->regularity_status === 'red') $redCount++;
            }

            $unresolvedComments = PdfComment::whereIn('logbook_entry_id', function ($q) use ($taIds) {
                $q->select('id')->from('logbook_entries')->whereIn('mahasiswa_ta_id', $taIds);
            })->whereIn('resolution_status', [PdfComment::STATUS_OPEN, PdfComment::STATUS_ADDRESSED])->count();

            $dosen->notify(new WeeklyDigestNotification(
                "Digest mingguan Anda:\n• {$waiting} entri menunggu review\n• {$redCount} mahasiswa status merah\n• {$unresolvedComments} komentar PDF belum direspons",
                route('quick-review.index'),
                'Digest Mingguan Dosen',
            ));
        }

        // ---------- Mahasiswa ----------
        $mahasiswa = User::role('mahasiswa')->get();
        foreach ($mahasiswa as $m) {
            if (! Institution::forUser($m)->isWeeklyDigestEnabled()) {
                continue;
            }

            $ta = $m->programAktif();
            if (!$ta) continue;

            $lastEntry = $ta->entries()->latest('tanggal_bimbingan')->first();
            $lastBadge = $m->achievements()->latest('user_achievements.unlocked_at')->first();

            $message = "Digest mingguan Anda:\n• Fase saat ini: {$ta->faseLabel()}\n";
            $message .= "• Sesi terakhir: ".($lastEntry ? 'Sesi '.$lastEntry->sesi_ke.' ('.$lastEntry->tanggal_bimbingan?->format('d M').')' : 'belum ada')."\n";
            $message .= "• Badge terbaru: ".($lastBadge ? $lastBadge->name : 'belum ada')."\n";
            $message .= "• Status bimbingan: ".ucfirst($ta->regularity_status);

            $m->notify(new WeeklyDigestNotification(
                $message,
                route('dashboard'),
                'Digest Mingguan Mahasiswa',
            ));
        }

        $this->info('Weekly digest terkirim.');

        return self::SUCCESS;
    }
}
