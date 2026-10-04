<?php

namespace App\Notifications;

use App\Models\Institution;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\URL;

/**
 * Notifikasi verifikasi email yang dikirim saat user wajib verifikasi
 * (MustVerifyEmail). URL yang dipakai adalah signed route Laravel
 * ke endpoint 'verification.verify'.
 */
class VerifyEmail extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Samakan brand/nama pengirim dengan institusi penerima.
        Institution::forUser($notifiable)->applyToConfig();

        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject(Lang::get('Verifikasi Alamat Email Anda'))
            ->greeting('Halo '.$notifiable->name)
            ->line(Lang::get('Silakan klik tombol di bawah ini untuk memverifikasi alamat email Anda.'))
            ->action(Lang::get('Verifikasi Email'), $verificationUrl)
            ->line(Lang::get('Jika Anda tidak merasa mendaftar di '.config('app.name').', abaikan email ini.'));
    }

    protected function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
