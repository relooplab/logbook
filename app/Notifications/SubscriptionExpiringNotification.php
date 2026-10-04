<?php

namespace App\Notifications;

use App\Models\DirectorySubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DirectorySubscription $subscription,
        public string $status, // 'expiring' | 'expired'
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $nodeName = $this->subscription->scopeName();
        $scopeLabel = $this->subscription->scopeLabel();
        $endsAt = $this->subscription->ends_at?->format('d M Y') ?? '—';

        if ($this->status === 'expired') {
            $subject = '[Logbook] Langganan '.$scopeLabel.' "'.$nodeName.'" Telah Berakhir';
            $message = "Langganan {$scopeLabel} \"{$nodeName}\" telah berakhir pada {$endsAt}. Dosen yang terafiliasi ke node ini akan kembali ke kuota plan individual.";
        } else {
            $subject = '[Logbook] Langganan '.$scopeLabel.' "'.$nodeName.'" Akan Berakhir';
            $message = "Langganan {$scopeLabel} \"{$nodeName}\" akan berakhir pada {$endsAt}. Perpanjang sebelum berakhir agar kuota storage institusi tetap aktif.";
        }

        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject($subject)
            ->greeting('Halo '.$notifiable->name)
            ->line($message)
            ->action('Kelola Langganan', route('admin.system.directory-subscriptions'));
    }

    public function toArray(object $notifiable): array
    {
        $nodeName = $this->subscription->scopeName();
        $scopeLabel = $this->subscription->scopeLabel();
        $endsAt = $this->subscription->ends_at?->format('d M Y') ?? '—';

        if ($this->status === 'expired') {
            $message = "Langganan {$scopeLabel} \"{$nodeName}\" telah berakhir ({$endsAt}).";
        } else {
            $message = "Langganan {$scopeLabel} \"{$nodeName}\" akan berakhir pada {$endsAt}.";
        }

        return [
            'message' => $message,
            'url' => route('admin.system.directory-subscriptions'),
        ];
    }
}