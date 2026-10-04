<?php

namespace App\Notifications;

use App\Models\Institution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rekap upload workspace untuk dosen pembimbing (throttle 24 jam).
 * Isi email = file terbaru yang belum masuk email sebelumnya, memakai
 * markdown view + mail::table seperti email digest lainnya (clean-minimal).
 */
class WorkspaceUploadDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{name: string, bab: ?string, time: string}>  $files
     */
    public function __construct(
        public string $uploaderName,
        public array $files,
        public string $url,
        public string $subject,
        public int $mahasiswaTaId,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Resolve config mail/branding sesuai institusi penerima (queue worker).
        Institution::forUser($notifiable)->applyToConfig();

        $count = count($this->files);
        $opening = $count === 1
            ? "{$this->uploaderName} mengunggah file baru ke workspace."
            : "{$this->uploaderName} mengunggah {$count} file baru ke workspace sejak pemberitahuan terakhir.";

        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject($this->subject)
            ->markdown('emails.workspace-upload-digest', [
                'penerima' => $notifiable->name,
                'opening' => $opening,
                'files' => $this->files,
                'url' => $this->url,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'workspace_upload_digest',
            'mahasiswa_ta_id' => $this->mahasiswaTaId,
            'message' => $this->subject,
            'url' => $this->url,
        ];
    }
}
