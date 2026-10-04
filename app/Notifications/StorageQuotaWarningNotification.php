<?php

namespace App\Notifications;

use App\Models\Institution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StorageQuotaWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $threshold,
        public int $usedMb,
        public int $limitMb,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        Institution::forUser($notifiable)->applyToConfig();

        $percent = $this->limitMb > 0 ? (int) round($this->usedMb / $this->limitMb * 100) : 0;

        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject('[Logbook] Kuota Penyimpanan Mendekati Limit ('.$percent.'%)')
            ->greeting('Halo '.$notifiable->name)
            ->line("Pemakaian penyimpanan Anda sudah mencapai {$percent}% dari kuota.")
            ->line('Terpakai: '.$this->usedMb.' MB dari '.$this->limitMb.' MB.')
            ->line('Segera kelola file Anda untuk menghindari pemblokiran upload.')
            ->action('Kelola Penyimpanan', route('storage.index'));
    }

    public function toArray(object $notifiable): array
    {
        $percent = $this->limitMb > 0 ? (int) round($this->usedMb / $this->limitMb * 100) : 0;

        return [
            'message' => "Kuota penyimpanan mencapai {$percent}% (terpakai {$this->usedMb} MB dari {$this->limitMb} MB).",
            'url' => route('storage.index'),
        ];
    }
}