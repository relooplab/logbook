<?php

namespace App\Console\Commands;

use App\Models\InactivityNotification;
use App\Models\Institution;
use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Notifications\InactivityReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class NotifyInactiveStudents extends Command
{
    protected $signature = 'ta:notify-inactive
                            {--weeks=3 : Ambang inaktivitas (minggu)}
                            {--resend-days=7 : Jeda kirim ulang (hari)}';

    protected $description = 'Kirim email pengingat ke mahasiswa yang tidak bimbingan > ambang, dan CC pembimbing 1';

    public function handle(): int
    {
        $weeks = (int) $this->option('weeks');
        $resendDays = (int) $this->option('resend-days');
        $threshold = now()->subWeeks($weeks);
        $appUrl = rtrim((string) config('app.url'), '/');

        $inactive = MahasiswaTa::with('mahasiswa')
            ->whereDoesntHave('entries', function ($q) use ($threshold) {
                $q->where('created_at', '>=', $threshold);
            })
            ->get();

        $sent = 0;
        foreach ($inactive as $ta) {
            if (!$ta->mahasiswa) {
                continue;
            }

            // Toggle per institusi penerima (sama seperti reminder harian 08:00).
            if (! Institution::forUser($ta->mahasiswa)->isDailyReminderEnabled()) {
                continue;
            }

            // Anti-spam: kirim hanya jika belum pernah, atau jeda > resendDays.
            $last = InactivityNotification::where('mahasiswa_ta_id', $ta->id)
                ->orderByDesc('notified_at')
                ->first();

            if ($last && $last->notified_at->gt(now()->subDays($resendDays))) {
                continue;
            }

            // Tanggal aktivitas terakhir.
            $lastEntry = LogbookEntry::where('mahasiswa_ta_id', $ta->id)
                ->orderByDesc('created_at')
                ->first();
            $lastDate = $lastEntry?->created_at?->format('d M Y') ?? '—';
            $inactiveDays = $lastEntry
                ? (int) $lastEntry->created_at->diffInDays(now())
                : (int) $ta->created_at?->diffInDays(now()) ?? $weeks * 7;

            // Kirim ke mahasiswa.
            $ta->mahasiswa->notify(new InactivityReminderNotification(
                (string) $inactiveDays,
                $lastDate,
                $ta->faseLabel(),
                $appUrl,
            ));

            // CC pembimbing 1 via Mail raw (agar satu email menyertakan CC).
            if ($ta->pembimbing1) {
                Mail::raw(
                    "Ini adalah salinan untuk pembimbing.\n\nMahasiswa {$ta->mahasiswa->name} ({$ta->mahasiswa->nim}) tidak ada aktivitas bimbingan sejak {$lastDate} ({$inactiveDays} hari). Fase TA saat ini: {$ta->faseLabel()}.",
                    function ($message) use ($ta, $appUrl) {
                        $message->to($ta->pembimbing1->email)
                            ->subject('[Logbook] Salinan: Mahasiswa tidak aktif bimbingan');
                    }
                );
            }

            InactivityNotification::create([
                'mahasiswa_ta_id' => $ta->id,
                'notified_at' => now(),
                'inactive_days' => $inactiveDays,
            ]);

            $sent++;
            $this->line("Kirim reminder ke {$ta->mahasiswa->email} (inaktif {$inactiveDays} hari)");
        }

        $this->info("Selesai. {$sent} pengingat terkirim.");

        return self::SUCCESS;
    }
}
