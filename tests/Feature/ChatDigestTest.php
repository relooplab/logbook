<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Institution;
use App\Models\MahasiswaTa;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ChatDigestNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatDigestTest extends TestCase
{
    use DatabaseTransactions;

    private User $student;

    private User $lecturer;

    private Conversation $thread;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolasi: hapus thread stray di DB scratch (di dalam transaksi test,
        // di-rollback otomatis) agar command tidak memproses data asing.
        \App\Models\Message::query()->delete();
        \App\Models\Conversation::query()->delete();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->student = $this->account('mahasiswa', 'DigestStudent');
        $this->lecturer = $this->account('dosen', 'DigestLecturer');
        $program = MahasiswaTa::create(['user_id' => $this->student->id, 'jenis' => 'ta',
            'fase' => 'proposal', 'status_ta' => 'aktif', 'target_sesi' => 7,
            'judul_ta' => 'Judul digest panjang untuk program '.$this->student->id,
            'pembimbing_1_id' => $this->lecturer->id]);
        $this->thread = Conversation::create([
            'user_one_id' => min($this->student->id, $this->lecturer->id),
            'user_two_id' => max($this->student->id, $this->lecturer->id),
            'mahasiswa_ta_id' => $program->id,
        ]);
    }

    private function account(string $role, string $label): User
    {
        $suffix = uniqid();
        $user = User::create([
            'name' => $label.' '.$suffix, 'email' => $suffix.'@digest.test',
            'password' => bcrypt('password'), 'registration_status' => 'active',
            'nim' => $role === 'mahasiswa' ? 'NIM'.$suffix : null,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function send(User $sender, string $body): Message
    {
        return Message::create([
            'conversation_id' => $this->thread->id,
            'sender_id' => $sender->id,
            'body' => $body,
        ]);
    }

    public function test_no_digest_before_two_days(): void
    {
        Notification::fake();
        $this->send($this->student, 'Halo dosen, mohon review logbook saya.');

        $this->travel(1)->day();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_digest_sent_after_two_days_unread(): void
    {
        Notification::fake();
        $this->send($this->student, 'Halo dosen, mohon review logbook saya.');
        $this->send($this->student, 'Tambahan: file revisi sudah saya unggah.');

        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertSentTo($this->lecturer, ChatDigestNotification::class,
            function ($n) {
                $bodies = implode("\n", array_column($n->items, 'body'));

                return str_contains($bodies, 'mohon review logbook saya')
                    && str_contains($bodies, 'file revisi sudah saya unggah')
                    && $n->opening !== '' && $n->closing !== ''
                    && str_contains($n->intro, '2 hari');
            });
    }

    public function test_no_duplicate_digest_without_new_activity(): void
    {
        Notification::fake();
        $this->send($this->student, 'Pesan yang akan didigest sekali saja.');

        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();
        $this->travel(2)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertSentToTimes($this->lecturer, ChatDigestNotification::class, 1);
    }

    public function test_reply_cancels_pending_digest(): void
    {
        Notification::fake();
        $this->send($this->student, 'Halo dosen.');
        $this->travel(1)->day();
        $this->send($this->lecturer, 'Baik, saya cek dulu ya.');

        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        // Balasan dosen belum dibalas mahasiswa > 2 hari → digest untuk mahasiswa, bukan dosen.
        Notification::assertNotSentTo($this->lecturer, ChatDigestNotification::class);
        Notification::assertSentTo($this->student, ChatDigestNotification::class);
    }

    public function test_read_cancels_pending_digest(): void
    {
        Notification::fake();
        $msg = $this->send($this->student, 'Sudah dibaca tapi belum dibalas.');
        $msg->update(['read_at' => now()]);

        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_new_message_after_digest_starts_new_episode(): void
    {
        Notification::fake();
        $this->send($this->student, 'Episode pertama.');
        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        $this->send($this->student, 'Episode kedua setelah digest.');
        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertSentToTimes($this->lecturer, ChatDigestNotification::class, 2);
    }

    public function test_escalation_sent_at_day_seven_only_once(): void
    {
        Notification::fake();
        $this->send($this->student, 'Pesan yang butuh eskalasi.');

        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();
        $this->travel(5)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();
        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertSentToTimes($this->lecturer, ChatDigestNotification::class, 2);
        Notification::assertSentTo($this->lecturer, ChatDigestNotification::class,
            fn ($n) => str_contains($n->subject, 'Masih menunggu'));
    }

    public function test_digest_preview_shows_newest_five_and_strips_markdown_links(): void
    {
        Notification::fake();
        $base = now()->subMinutes(10);
        for ($i = 1; $i <= 7; $i++) {
            $msg = $this->send($this->student, "Pesan urutan ke-{$i}.");
            $msg->created_at = $base->copy()->addSeconds($i);
            $msg->save();
        }
        $last = $this->send($this->student, 'Klik [promo menarik](https://evil.example/phising) ya dosen.');
        $last->created_at = $base->copy()->addSeconds(8);
        $last->save();

        $this->travel(3)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertSentTo($this->lecturer, ChatDigestNotification::class,
            function ($n) {
                $bodies = implode("\n", array_column($n->items, 'body'));

                return count($n->items) === 5
                    && str_contains($bodies, 'Pesan urutan ke-7.')
                    && str_contains($bodies, 'promo menarik ya dosen.')
                    && ! str_contains($bodies, 'Pesan urutan ke-1.')
                    && ! str_contains($bodies, 'https://evil.example/phising');
            });
    }

    public function test_toggle_off_disables_chat_digest(): void
    {
        Notification::fake();
        Institution::first()->update(['chat_digest_enabled' => false]);
        $this->send($this->student, 'Pesan saat toggle mati.');

        $this->travel(10)->days();
        $this->artisan('chat:send-pending-digests')->assertSuccessful();

        Notification::assertNothingSent();
    }
}