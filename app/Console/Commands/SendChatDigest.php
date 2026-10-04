<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Institution;
use App\Notifications\ChatDigestNotification;
use Illuminate\Console\Command;

class SendChatDigest extends Command
{
    protected $signature = 'chat:send-pending-digests';

    protected $description = 'Kirim digest pesan chat belum dibaca (H+2) dan eskalasi (H+7)';

    private const DIGEST_DAYS = 2;

    private const ESCALATION_DAYS = 7;

    private const PREVIEW_LIMIT = 5;

    public function handle(): int
    {
        $sent = 0;

        $threads = Conversation::with(['latestMessage', 'userOne', 'userTwo'])
            ->whereHas('latestMessage', fn ($q) => $q->where('created_at', '<=', now()->subDays(self::DIGEST_DAYS)))
            ->cursor();

        foreach ($threads as $thread) {
            if ($this->processThread($thread)) {
                $sent++;
            }
        }

        $this->info("Digest chat terkirim: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Proses satu conversation. Return true jika ada notifikasi terkirim.
     */
    private function processThread(Conversation $thread): bool
    {
        $last = $thread->latestMessage;
        if (! $last) {
            return false;
        }

        $sender = $last->sender_id === $thread->user_one_id ? $thread->userOne : $thread->userTwo;
        $recipient = $last->sender_id === $thread->user_one_id ? $thread->userTwo : $thread->userOne;
        if (! $sender || ! $recipient) {
            return false;
        }

        // Batal diam-diam: semua pesan pengirim sudah dibaca penerima.
        $unread = $thread->messages()
            ->where('sender_id', $sender->id)
            ->whereNull('read_at')
            ->count();
        if ($unread === 0) {
            return false;
        }

        // Toggle per institusi penerima.
        if (! Institution::forUser($recipient)->isChatDigestEnabled()) {
            return false;
        }

        $ageDays = $last->created_at->diffInDays(now());
        $digested = $thread->chat_digest_sent_at
            && $thread->chat_digest_sent_at->gte($last->created_at);

        if (! $digested && $ageDays >= self::DIGEST_DAYS) {
            $this->sendDigest($thread, $sender, $recipient, $unread, false);
            $thread->update(['chat_digest_sent_at' => now()]);

            return true;
        }

        $escalated = $thread->chat_escalation_sent_at
            && $thread->chat_escalation_sent_at->gte($last->created_at);

        if ($digested && ! $escalated && $ageDays >= self::ESCALATION_DAYS) {
            $this->sendDigest($thread, $sender, $recipient, $unread, true);
            $thread->update(['chat_escalation_sent_at' => now()]);

            return true;
        }

        return false;
    }

    /**
     * Netralkan sintaks Markdown link pada isi pesan sebelum dirangkai ke
     * email: parser Markdown email akan mengubah [teks](url) menjadi link
     * klikabel (vektor phishing via email terpercaya). URL dibuang, teks
     * link dipertahankan agar isi tetap terbaca.
     */
    private function plainText(string $body): string
    {
        $text = str_replace('![', '[', $body);

        return (string) preg_replace('/\[([^\]]*)\]\(([^)]*)\)/', '$1', $text);
    }

    private function sendDigest(Conversation $thread, $sender, $recipient, int $unread, bool $escalation): void
        {
            $items = $thread->messages()->with('sender:id,name')->reorder()->latest()->limit(self::PREVIEW_LIMIT)
                ->get()->reverse()->map(function ($m) {
                    $text = trim((string) $m->body) !== '' ? $this->plainText($m->body) : '[lampiran file]';

                    return [
                        'time' => $m->created_at->format('d M H:i'),
                        'sender' => $m->sender->name,
                        // Sel tabel Markdown rusak oleh | dan baris baru: netralkan.
                        'body' => mb_strimwidth(str_replace('|', '/', preg_replace('/\s+/', ' ', $text)), 0, 300, '…'),
                    ];
                })->values()->all();

            $url = route('chat.show', $thread);
        if ($escalation) {
            $subject = "[Logbook] Masih menunggu: {$unread} pesan belum dibaca dari {$sender->name}";
            $opening = 'Sudah seminggu percakapan berikut belum mendapatkan respons.';
            $intro = 'Pesan yang masih menunggu:';
        } else {
            $subject = "[Logbook] {$unread} pesan belum dibaca dari {$sender->name}";
            $opening = 'Kami mencatat ada percakapan di Logbook yang menunggu respons Anda.';
            $intro = 'Pesan yang belum dibaca lebih dari 2 hari:';
        }
        $closing = 'Klik tombol di bawah untuk membuka percakapan dan membalas langsung di aplikasi.';

            // Satu baris ringkasan in-app: hapus baris digest lama yang belum
            // dibaca untuk conversation ini (kunci stabil conversation_id),
            // lalu buat yang baru (beserta email).
            $recipient->unreadNotifications()
                ->where('type', ChatDigestNotification::class)
                ->where('data->conversation_id', $thread->id)
                ->delete();

            $recipient->notify(new ChatDigestNotification($intro, $items, $url, $subject, $thread->id, $opening, $closing));
        }
}
