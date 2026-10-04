<?php

namespace App\Notifications;

use App\Models\Institution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InactivityReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $inactiveDays,
        public string $lastActivityDate,
        public string $faseLabel,
        public string $appUrl,
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

        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject('[Logbook] Pengingat: Tidak Ada Bimbingan Selama '.$this->inactiveDays.' Hari')
            ->greeting('Halo '.$notifiable->name)
            ->line("Tercatat tidak ada aktivitas bimbingan sejak {$this->lastActivityDate} ({$this->inactiveDays} hari).")
            ->line('Fase TA Anda saat ini: '.$this->faseLabel.'.')
            ->line('Segera hubungi pembimbing dan jadwalkan bimbingan:')
            ->action('Jadwalkan Bimbingan', config('app.jadwal_url'))
            ->line('Catat logbook: '.$this->appUrl.'/logbook/create');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Tidak ada bimbingan selama '.$this->inactiveDays.' hari. Segera hubungi pembimbing.',
            'url' => $this->appUrl.'/logbook/create',
        ];
    }
}
