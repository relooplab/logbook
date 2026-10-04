<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Digest pesan chat belum dibaca (H+2) dan eskalasi (H+7).
 * Email memakai markdown view + mail::table agar tiap pesan tampil
 * sebagai baris terstruktur (bukan paragraf gabungan), dengan tema
 * yang sama seperti email lain (clean-minimal).
 */
class ChatDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{time: string, sender: string, body: string}>  $items
     */
    public function __construct(
        public string $intro,
        public array $items,
        public string $url,
        public string $subject,
        public int $conversationId,
        public string $opening = '',
        public string $closing = '',
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->theme('clean-minimal')
            ->subject($this->subject)
            ->markdown('emails.chat-digest', [
                'penerima' => $notifiable->name,
                'opening' => $this->opening,
                'intro' => $this->intro,
                'items' => $this->items,
                'closing' => $this->closing,
                'url' => $this->url,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $first = $this->items[0] ?? null;
        $ringkas = $first ? " ({$first['sender']}: ".mb_strimwidth($first['body'], 0, 120, '…').')' : '';

        return [
            'kind' => 'chat_digest',
            'conversation_id' => $this->conversationId,
            'message' => $this->intro.$ringkas,
            'url' => $this->url,
        ];
    }
}
