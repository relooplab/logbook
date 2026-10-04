<?php

namespace App\Notifications;

use App\Models\Institution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $message,
        public ?string $url = null,
        public string $subject = 'Pemberitahuan Logbook',
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

        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject($this->subject)
            ->greeting('Halo '.$notifiable->name)
            ->line($this->message)
            ->when($this->url, fn ($m) => $m->action('Buka Aplikasi', $this->url));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
