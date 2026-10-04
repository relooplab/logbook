<?php

namespace App\Notifications;

use App\Models\Institution;
use App\Models\SeminarSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Notifikasi ke dosen ketika mahasiswa mengirim bahan seminar/sidang.
 * Berisi detail jadwal lengkap, tautan langsung dokumen (signed URL dengan
 * masa berlaku terbatas), dan lampiran kalender .ics.
 */
class SeminarSubmissionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SeminarSubmission $submission,
        public string $role = 'dosen', // 'dosen' | 'mahasiswa'
        public bool $isUpdate = false,
        /** @var string[] Ringkasan field yang berubah, mis. ['Tanggal', 'Materi']. */
        public array $changedFields = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Resolve config mail/branding sesuai institusi penerima (queue worker).
        Institution::forUser($notifiable)->applyToConfig();

        $submission = $this->submission;
        $ta = $submission->mahasiswaTa;
        $mahasiswa = $ta?->mahasiswa;

        $urlUndangan = $this->signedUrl('undangan');
        $urlMateri = $submission->materi_path ? $this->signedUrl('materi') : null;

        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject($this->isUpdate ? 'Perubahan Bahan '.$submission->jenisLabel() : 'Bahan '.$submission->jenisLabel().' Dikirim')
            ->markdown('emails.seminar-submission', [
                'role' => $this->role,
                'isUpdate' => $this->isUpdate,
                'changedFields' => $this->changedFields,
                'penerima' => $notifiable->name,
                'namaMahasiswa' => $mahasiswa?->name ?? '—',
                'jenisLabel' => $submission->jenisLabel(),
                'tanggal' => $submission->tanggal?->format('l, d F Y') ?? '—',
                'waktuMulai' => $submission->waktu?->format('H:i') ?? '—',
                'waktuSelesai' => $submission->end()->format('H:i'),
                'durasiMenit' => SeminarSubmission::DEFAULT_DURASI_MENIT,
                'lokasi' => $submission->lokasi ?: '—',
                'diundang' => $submission->undanganKepadaLabel(),
                'catatan' => $submission->catatan_keterangan,
                'urlUndangan' => $urlUndangan,
                'namaFileUndangan' => $submission->undangan_original_name,
                'urlMateri' => $urlMateri,
                'namaFileMateri' => $submission->materi_original_name ?: 'file materi',
                'urlDetail' => route('seminar-submission.show', $submission),
            ])
            ->attachData(
                $this->buildIcs($notifiable),
                'undangan-'.$submission->id.'.ics',
                ['mime' => 'text/calendar; charset=utf-8']
            );
    }

    public function toArray(object $notifiable): array
    {
        $submission = $this->submission;
        $mahasiswa = $submission->mahasiswaTa?->mahasiswa;
        $nama = $mahasiswa?->name ?? '—';

        if ($this->isUpdate) {
            $rincian = $this->changedFields ? ' ('.implode(', ', $this->changedFields).')' : '';
            $msg = $this->role === 'mahasiswa'
                ? 'Anda memperbarui bahan '.$submission->jenisLabel().$rincian.'.'
                : 'Mahasiswa '.$nama.' memperbarui bahan '.$submission->jenisLabel().$rincian.'.';
        } else {
            $msg = $this->role === 'mahasiswa'
                ? 'Anda telah mengirim bahan '.$submission->jenisLabel().'.'
                : 'Mahasiswa '.$nama.' mengirim bahan '.$submission->jenisLabel().'.';
        }

        return [
            'message' => $msg,
            'url' => route('seminar-submission.show', $submission),
        ];
    }

    /**
     * Tautan berbagi dokumen (signed URL) dengan masa berlaku terbatas.
     */
    private function signedUrl(string $jenis): string
    {
        $route = $jenis === 'materi'
            ? 'seminar-submission.shared-materi'
            : 'seminar-submission.shared-undangan';

        return URL::temporarySignedRoute(
            $route,
            $this->submission->sharedLinkExpiration(),
            ['submission' => $this->submission->id]
        );
    }

    /**
     * Bangun isi lampiran kalender (.ics / RFC 5545).
     * DTEND default = waktu mulai + DEFAULT_DURASI_MENIT karena form
     * pemberian bahan belum memiliki isian durasi.
     */
    private function buildIcs(object $notifiable): string
    {
        $submission = $this->submission;
        $mahasiswa = $submission->mahasiswaTa?->mahasiswa;
        $start = $submission->start();
        $end = $submission->end();

        $mahasiswaName = $mahasiswa?->name ?? '—';

        // Narasi selaras dengan badan email notifikasi, disesuaikan untuk teks
        // polos lampiran kalender (tanpa bullet). Sertakan tautan dokumen agar
        // bisa dibuka langsung dari undangan kalender.
        $description = 'Undangan '.$submission->jenisLabel().' atas nama mahasiswa '.$mahasiswaName.'.'."\n"
            .'Jenis: '.$submission->jenisLabel()."\n"
            .'Mahasiswa: '.$mahasiswaName."\n"
            .'Tanggal: '.$submission->start()->format('l, d F Y')."\n"
            .'Waktu: '.$submission->start()->format('H:i').' – '.$submission->end()->format('H:i').' ('.SeminarSubmission::DEFAULT_DURASI_MENIT.' menit)'."\n"
            .'Lokasi: '.($submission->lokasi ?: '—')."\n"
            .'Diundang: '.$submission->undanganKepadaLabel()
            .($submission->catatan_keterangan ? "\nCatatan: ".$submission->catatan_keterangan : '')
            ."\nLink Surat Undangan: ".$this->signedUrl('undangan')
            .($submission->materi_path ? "\nLink Materi: ".$this->signedUrl('materi') : '');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Logbook//Bahan Seminar Sidang//ID',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:bahan-seminar-'.$submission->id.'@'.$this->icsDomain(),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            // Waktu dikirim dalam UTC (akhiran Z): semua klien kalender
            // (Google Calendar, Apple Calendar, Outlook) menampilkannya konsisten
            // tanpa bergantung pada resolusi TZID/VTIMEZONE di sisi klien.
            'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$end->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escapeIcs(($this->isUpdate ? 'PERUBAHAN: ' : '').$submission->jenisLabel().' — '.($mahasiswa?->name ?? 'Mahasiswa')),
            'LOCATION:'.$this->escapeIcs((string) ($submission->lokasi ?? '')),
            'DESCRIPTION:'.$this->escapeIcs($description),
            'ORGANIZER:MAILTO:'.($mahasiswa?->email ?: 'no-reply@example.com'),
            'ATTENDEE:MAILTO:'.$notifiable->email,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return $this->foldLines(implode("\r\n", $lines)."\r\n");
    }

    /**
     * Escape nilai teks ICS sesuai RFC 5545 (backslash, newline, koma, titik koma).
     */
    private function escapeIcs(string $value): string
    {
        return str_replace(
            ["\\", "\r\n", "\r", "\n", ";", ","],
            ["\\\\", "\\n", "\\n", "\\n", "\\;", "\\,"],
            $value
        );
    }

    /**
     * Line-folding ICS: maksimal 75 oktet per baris (RFC 5545), kelanjutan baris
     * didahului CRLF + satu spasi. Pembelahan dilakukan per karakter agar tidak
     * memotong di tengah karakter multibyte (mis. em-dash —).
     */
    private function foldLines(string $content): string
    {
        $folded = [];

        foreach (explode("\r\n", $content) as $line) {
            if (strlen($line) <= 75) {
                $folded[] = $line;
                continue;
            }

            $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $segments = [];
            $current = '';

            // Segmen pertama boleh 75 oktet; segmen lanjutan maks 74 karena
            // didahului 1 spasi folding (RFC 5545: maks 75 oktet/baris).
            foreach ($chars as $char) {
                $limit = $segments === [] ? 75 : 74;
                if ($current !== '' && strlen($current.$char) > $limit) {
                    $segments[] = $current;
                    $current = $char;
                } else {
                    $current .= $char;
                }
            }

            if ($current !== '') {
                $segments[] = $current;
            }

            $folded[] = implode("\r\n ", $segments);
        }

        return implode("\r\n", $folded);
    }

    /**
     * Host aplikasi untuk komponen UID ICS.
     */
    private function icsDomain(): string
    {
        return (string) (parse_url(config('app.url', ''), PHP_URL_HOST) ?: 'localhost');
    }
}