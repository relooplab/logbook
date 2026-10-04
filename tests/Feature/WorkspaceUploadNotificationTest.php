<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\WorkspaceUploadDigestNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Upload workspace: in-app instan + email rekap throttle 24 jam.
 */
class WorkspaceUploadNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;

    private User $dosen1;

    private User $dosen2;

    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $suffix = uniqid();
        $this->mhs = $this->account('mahasiswa', "Mhs WUN {$suffix}");
        $this->dosen1 = $this->account('dosen', "Dosen1 WUN {$suffix}");
        $this->dosen2 = $this->account('dosen', "Dosen2 WUN {$suffix}");

        $this->ta = MahasiswaTa::create([
            'user_id' => $this->mhs->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'fase' => 'proposal',
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'target_sesi' => 7,
            'judul_ta' => 'Judul WUN '.$suffix,
            'pembimbing_1_id' => $this->dosen1->id,
            'pembimbing_2_id' => $this->dosen2->id,
        ]);
    }

    private function account(string $role, string $name): User
    {
        $suffix = uniqid();
        $user = User::create([
            'name' => $name, 'email' => $suffix.'@wun.test',
            'password' => bcrypt('password'), 'registration_status' => 'active',
            'nim' => 'WUN'.$suffix,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function upload(User $actor, string $filename, string $bab = 'Bab 1'): void
    {
        $this->actingAs($actor)->post(route('workspace.store', $this->ta), [
            'files' => [UploadedFile::fake()->create($filename, 100, 'application/pdf')],
            'bab' => $bab,
        ])->assertSessionHasNoErrors();
    }

    public function test_first_upload_sends_immediate_digest_and_instant_inapp(): void
    {
        Notification::fake();
        $this->upload($this->mhs, 'proposal-awal.pdf');

        foreach ([$this->dosen1, $this->dosen2] as $dosen) {
            Notification::assertSentTo($dosen, WorkspaceUploadDigestNotification::class,
                fn ($n) => $n->mahasiswaTaId === $this->ta->id
                    && $n->uploaderName === $this->mhs->name
                    && str_contains($n->subject, $this->mhs->name)
                    && str_contains($n->subject, 'File Baru di Workspace')
                    && count($n->files) === 1
                    && str_contains($n->files[0]['name'], 'proposal-awal.pdf'));

            Notification::assertSentTo($dosen, ActivityNotification::class,
                fn ($n) => str_contains($n->message, $this->mhs->name)
                    && str_contains($n->message, 'proposal-awal.pdf')
                    && $n->channels === ['database']);
        }

        Notification::assertNotSentTo($this->mhs, ActivityNotification::class);
        Notification::assertNotSentTo($this->mhs, WorkspaceUploadDigestNotification::class);
    }

    public function test_repeat_upload_within_24h_suppresses_email_but_not_inapp(): void
    {
        Notification::fake();
        $this->upload($this->mhs, 'batch-pertama.pdf');
        $this->upload($this->mhs, 'batch-kedua.pdf');

        Notification::assertSentToTimes($this->dosen1, WorkspaceUploadDigestNotification::class, 1);
        Notification::assertSentToTimes($this->dosen1, ActivityNotification::class, 2);
    }

    public function test_next_email_after_24h_recaps_unsent_files(): void
    {
        Notification::fake();
        $this->upload($this->mhs, 'file-segera.pdf');
        $this->upload($this->mhs, 'file-tertahan-satu.pdf');
        $this->upload($this->mhs, 'file-tertahan-dua.pdf');

        $this->travel(25)->hours();
        $this->upload($this->mhs, 'file-pemicu.pdf');

        Notification::assertSentToTimes($this->dosen1, WorkspaceUploadDigestNotification::class, 2);
        Notification::assertSentTo($this->dosen1, WorkspaceUploadDigestNotification::class,
            function ($n) {
                $names = implode("\n", array_column($n->files, 'name'));

                return str_contains($names, 'file-tertahan-satu.pdf')
                    && str_contains($names, 'file-tertahan-dua.pdf')
                    && str_contains($names, 'file-pemicu.pdf')
                    && ! str_contains($names, 'file-segera.pdf');
            });
    }

    public function test_throttle_window_is_independent_per_supervisor(): void
    {
        Notification::fake();
        $this->upload($this->mhs, 'independen.pdf');

        Notification::assertSentToTimes($this->dosen1, WorkspaceUploadDigestNotification::class, 1);
        Notification::assertSentToTimes($this->dosen2, WorkspaceUploadDigestNotification::class, 1);

        // Majukan jendela dosen2 saja: upload berikutnya hanya memicu email dosen2.
        \Illuminate\Support\Facades\DB::table('workspace_upload_notifications')
            ->where('user_id', $this->dosen2->id)
            ->where('mahasiswa_ta_id', $this->ta->id)
            ->update(['last_sent_at' => now()->subHours(25)]);

        $this->upload($this->mhs, 'independen-kedua.pdf');

        Notification::assertSentToTimes($this->dosen1, WorkspaceUploadDigestNotification::class, 1);
        Notification::assertSentToTimes($this->dosen2, WorkspaceUploadDigestNotification::class, 2);
    }

    public function test_long_filename_is_trimmed_in_messages(): void
    {
        Notification::fake();
        $this->upload($this->mhs, str_repeat('a', 60).'.pdf');

        Notification::assertSentTo($this->dosen1, WorkspaceUploadDigestNotification::class,
            fn ($n) => mb_strlen($n->files[0]['name']) <= 40 && str_ends_with($n->files[0]['name'], '…'));

        Notification::assertSentTo($this->dosen1, ActivityNotification::class,
            fn ($n) => ! str_contains($n->message, str_repeat('a', 60)));
    }

    public function test_digest_email_renders_clean_minimal_theme(): void
    {
        $html = (string) (new WorkspaceUploadDigestNotification(
            'Andi',
            [['name' => 'proposal.pdf', 'bab' => 'Bab 1', 'time' => '04 Okt 10:00']],
            'http://localhost/workspace',
            'File Baru di Workspace — Andi',
            $this->ta->id,
        ))->toMail($this->dosen1)->render();

        $this->assertStringContainsString('Andi mengunggah file baru ke workspace.', $html);
        $this->assertStringContainsString('proposal.pdf', $html);
        $this->assertStringContainsString('Buka Workspace', $html);
    }
}
