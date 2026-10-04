<?php

namespace App\Services;

use App\Models\MahasiswaTa;
use App\Models\User;
use App\Models\WorkspaceFile;
use App\Notifications\ActivityNotification;
use App\Notifications\WorkspaceUploadDigestNotification;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi upload workspace: in-app instan per upload + email rekap
 * dengan throttle minimum 24 jam per (dosen penerima × program).
 *
 * - Upload pertama → email terkirim segera (rekap = batch saat itu).
 * - Upload berikutnya dalam < 24 jam → email ditahan (in-app tetap
 *   terkirim). File tertahan masuk rekap email berikutnya.
 * - Setelah 24 jam, upload berikutnya memicu email berisi semua file
 *   yang belum pernah masuk email untuk dosen tersebut.
 */
class WorkspaceUploadNotifier
{
    public const THROTTLE_HOURS = 24;

    private const EMAIL_PREVIEW_LIMIT = 5;

    public function notify(MahasiswaTa $mahasiswaTa, User $uploader, array $fileNames): void
    {
        $url = route('workspace.index', $mahasiswaTa);
        $studentName = $mahasiswaTa->mahasiswa?->name ?? $uploader->name;

        foreach ([$mahasiswaTa->pembimbing1, $mahasiswaTa->pembimbing2] as $dosen) {
            if (!$dosen || $dosen->id === $uploader->id) {
                continue;
            }

            $message = $this->instantMessage($studentName, $fileNames);

            try {
                $dosen->notify(new ActivityNotification($message, $url, 'File Baru di Workspace', ['database']));
            } catch (\Throwable $e) {
                report($e);
            }

            $this->maybeSendDigest($dosen, $mahasiswaTa, $uploader->name, $url);
        }
    }

    private function instantMessage(string $studentName, array $fileNames): string
    {
        $count = count($fileNames);

        if ($count === 0) {
            return "Mahasiswa '{$studentName}' mengunggah file baru ke workspace.";
        }

        if ($count === 1) {
            return "Mahasiswa '{$studentName}' mengunggah file '" . $this->shortName($fileNames[0]) . "' ke workspace.";
        }

        $shown = array_slice(array_map([$this, 'shortName'], $fileNames), 0, 3);
        $listed = "'" . implode("', '", $shown) . "'";

        if ($count <= 3) {
            return "Mahasiswa '{$studentName}' mengunggah {$count} file ke workspace: {$listed}.";
        }

        return "Mahasiswa '{$studentName}' mengunggah {$count} file ke workspace: {$listed}, dan " . ($count - 3) . ' file lainnya.';
    }

    private function shortName(string $name): string
    {
        return mb_strimwidth($name, 0, 40, '…');
    }

    /**
     * Kirim email rekap bila jendela throttle sudah lewat.
     *
     * Penanda dibaca dalam transaksi + row lock agar dua upload
     * beruntun tidak memicu dua email; penanda ditulis SETELAH
     * notifikasi terkirim agar kegagalan kirim tidak menghanguskan
     * file tertahan.
     */
    private function maybeSendDigest(User $dosen, MahasiswaTa $mahasiswaTa, string $uploaderName, string $url): void
    {
        $marker = DB::transaction(function () use ($dosen, $mahasiswaTa) {
            return DB::table('workspace_upload_notifications')
                ->where('user_id', $dosen->id)
                ->where('mahasiswa_ta_id', $mahasiswaTa->id)
                ->lockForUpdate()
                ->first();
        });

        if ($marker) {
            $due = $marker->last_sent_at === null
                || \Carbon\Carbon::parse($marker->last_sent_at)->lte(now()->subHours(self::THROTTLE_HOURS));

            $afterId = (int) ($marker->last_sent_file_id ?? 0);
            $pending = $due ? $this->pendingFiles($mahasiswaTa, $afterId) : collect();
        } else {
            $pending = $this->currentBatchFiles($mahasiswaTa);
            $due = $pending->isNotEmpty();
        }

        if (!$due || $pending->isEmpty()) {
            return;
        }

        $maxId = (int) $pending->max('id');

        try {
            $dosen->notify(new WorkspaceUploadDigestNotification($uploaderName, $this->previewFiles($pending), $url, "File Baru di Workspace — {$uploaderName}", $mahasiswaTa->id));
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        DB::table('workspace_upload_notifications')->updateOrInsert(
            ['user_id' => $dosen->id, 'mahasiswa_ta_id' => $mahasiswaTa->id],
            ['last_sent_at' => now(), 'last_sent_file_id' => $maxId, 'updated_at' => now()],
        );
    }

    /**
     * File yang belum pernah masuk email untuk dosen ini: id lebih besar
     * dari file terakhir yang direkap (watermark id, bukan timestamp).
     */
    private function pendingFiles(MahasiswaTa $mahasiswaTa, int $afterId)
    {
        return WorkspaceFile::where('mahasiswa_ta_id', $mahasiswaTa->id)
            ->whereNull('user_id')
            ->where('id', '>', $afterId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Batch saat ini = file program yang dibuat dalam 1 menit
     * terakhir (upload barusan). Mencegah email pertama menumpahkan
     * seluruh arsip lama bila penanda belum ada.
     */
    private function currentBatchFiles(MahasiswaTa $mahasiswaTa)
    {
        return WorkspaceFile::where('mahasiswa_ta_id', $mahasiswaTa->id)
            ->whereNull('user_id')
            ->where('created_at', '>=', now()->subMinute())
            ->orderBy('created_at')
            ->get();
    }

    private function previewFiles($files): array
    {
        return $files->sortByDesc('created_at')->take(self::EMAIL_PREVIEW_LIMIT)->reverse()->values()
            ->map(fn ($f) => [
                'name' => $this->shortName((string) $f->original_name),
                'bab' => $f->bab ? (string) $f->bab : null,
                'time' => $f->created_at?->format('d M H:i'),
            ])->all();
    }
}
