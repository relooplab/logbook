<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Alur "kirim perbaikan kepada dosen penguji":
 *  - Mahasiswa memilih penerima revisi (pembimbing ATAU dosen penguji).
 *  - Penerima menjadi reviewer entri (bisa Setujui / Minta Revisi).
 *  - Pembimbing tetap menerima notifikasi (CC) & tetap bisa mereview.
 */
class RevisiRecipientTest extends TestCase
{
    use DatabaseTransactions;

    private User $mahasiswa;

    private User $pembimbing;

    private User $penguji;

    private User $dosenLain;

    private MahasiswaTa $ta;

    private LogbookEntry $parent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mahasiswa = $this->makeUser('Mhs Revisi', 'mahasiswa', ['nim' => 'NIM'.substr(md5(uniqid()), 0, 8)]);
        $this->pembimbing = $this->makeUser('Pembimbing Satu', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);
        $this->penguji = $this->makeUser('Penguji Satu', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);
        $this->dosenLain = $this->makeUser('Dosen Lain', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);

        $this->ta = MahasiswaTa::create([
            'user_id' => $this->mahasiswa->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'pembimbing_1_id' => $this->pembimbing->id,
            'penguji_1_id' => $this->penguji->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'proposal',
        ]);

        $this->parent = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 1,
            'dosen_id' => $this->pembimbing->id,
            'topik' => 'Bimbingan 1',
            'status' => LogbookEntry::STATUS_REVISI,
            'reviewed_at' => now(),
        ]);
    }

    private function makeUser(string $name, string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ], $extra));

        $user->assignRole($role);

        return $user;
    }

    private function revisiPayload(int $recipientId, bool $submit = true): array
    {
        return [
            'parent_entry_id' => $this->parent->id,
            'addressed_dosen_id' => $recipientId,
            'submit' => $submit ? 1 : null,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Perbaikan sudah dikerjakan sesuai komentar dosen.',
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Perbaiki metodologi.',
                    'perbaikan' => 'Metodologi sudah diperbaiki.',
                    'status' => LogbookEntry::PERBAIKAN_SUDAH,
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('revisi.pdf', 100, 'application/pdf'),
        ];
    }

    private function logbookPayload(?int $recipientId, bool $submit = true): array
    {
        return [
            'addressed_dosen_id' => $recipientId,
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Bimbingan dengan penerima pilihan',
            'progres_kendala' => 'Membahas progres dan kendala.',
            'submit' => $submit ? 1 : null,
            // Setup memakai parent status revisi → gerbang lunak meminta
            // pernyataan sesi baru agar thread revisi tidak putus diam-diam.
            'confirm_new_despite_revision' => '1',
        ];
    }

    private function assertReviewEmail(User $recipient, LogbookEntry $entry, string $description): void
    {
        Notification::assertSentTo($recipient, ActivityNotification::class, function (ActivityNotification $notification) use ($recipient, $entry, $description) {
            $expected = $this->mahasiswa->name.' (NIM '.$this->mahasiswa->nim.') mengirim '.$description.' untuk direview';
            $this->assertStringContainsString($expected, $notification->message);
            $this->assertSame('Entri Baru Menunggu Review', $notification->subject);
            $this->assertSame(route('logbook.show', $entry), $notification->url);
            $this->assertStringContainsString($expected, $notification->toArray($recipient)['message']);
            $mail = $notification->toMail($recipient);
            $this->assertSame($notification->subject, $mail->subject);
            $this->assertStringContainsString($this->mahasiswa->name, $mail->render());
            $this->assertStringContainsString($this->mahasiswa->nim, $mail->render());

            return true;
        });
    }

    public function test_logbook_baru_email_review_menyebut_nama_dan_nim_mahasiswa(): void
    {
        Notification::fake();

        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $this->logbookPayload($this->penguji->id))
            ->assertRedirect(route('logbook.index'));

        $entry = $this->ta->entries()->where('jenis', LogbookEntry::JENIS_LOGBOOK)->latest('id')->firstOrFail();
        $this->assertReviewEmail($this->penguji, $entry, 'entri logbook sesi '.$entry->sesi_ke);
        $this->assertReviewEmail($this->pembimbing, $entry, 'entri logbook sesi '.$entry->sesi_ke);
    }

    public function test_revisi_baru_email_review_menyebut_mahasiswa_dan_peran_penerima(): void
    {
        Notification::fake();

        $this->actingAs($this->mahasiswa)->post(route('logbook.store-revisi'), $this->revisiPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->entryRevisiTerakhir();
        $this->assertReviewEmail($this->penguji, $entry, 'entri revisi');
        $this->assertReviewEmail($this->pembimbing, $entry, 'entri revisi');
        Notification::assertSentTo($this->penguji, ActivityNotification::class, function (ActivityNotification $notification) {
            $this->assertStringContainsString('oleh Penguji 1', $notification->message);

            return true;
        });
    }

    public function test_draf_yang_dikirim_kemudian_email_review_menyebut_identitas_mahasiswa(): void
    {
        Notification::fake();

        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $this->logbookPayload($this->penguji->id, false))
            ->assertRedirect();
        $entry = $this->ta->entries()->where('jenis', LogbookEntry::JENIS_LOGBOOK)->latest('id')->firstOrFail();
        Notification::assertNothingSent();

        $this->post(route('logbook.submit', $entry))->assertRedirect();
        $this->assertReviewEmail($this->penguji, $entry, 'entri logbook sesi '.$entry->sesi_ke);
        $this->assertReviewEmail($this->pembimbing, $entry, 'entri logbook sesi '.$entry->sesi_ke);
    }

    public function test_pesan_review_tanpa_nim_tetap_menyebut_nama_mahasiswa(): void
    {
        $this->mahasiswa->update(['nim' => null]);
        $this->assertSame(
            'Mhs Revisi mengirim entri logbook sesi 1 untuk direview.',
            $this->parent->reviewSubmissionMessage(),
        );
    }

    public function test_form_logbook_menampilkan_pembimbing_dan_penguji_sekali_saja(): void
    {
        $this->ta->update(['penguji_2_id' => $this->pembimbing->id]);

        $this->actingAs($this->mahasiswa)->get(route('logbook.create'))
            ->assertOk()
            ->assertSee('Kirim kepada (penerima logbook)')
            ->assertSee('Pembimbing 1 & Penguji 2 — Pembimbing Satu')
            ->assertSee('Penguji 1 — Penguji Satu')
            ->assertSee('value="'.$this->pembimbing->id.'" selected', false)
            ->assertDontSee('Dosen Lain');
    }

    public function test_logbook_kepada_penguji_dapat_direview_dan_pembimbing_menerima_cc(): void
    {
        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $this->logbookPayload($this->penguji->id))
            ->assertRedirect(route('logbook.index'));

        $entry = $this->ta->entries()->where('jenis', LogbookEntry::JENIS_LOGBOOK)->latest('id')->firstOrFail();
        $this->assertSame($this->penguji->id, $entry->dosen_id);
        $this->assertSame($this->penguji->id, $entry->reviewDosen()?->id);
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $entry->status);
        $this->assertSame(1, $this->penguji->fresh()->notifications()->count());
        $this->assertSame(1, $this->pembimbing->fresh()->notifications()->count());

        $this->actingAs($this->penguji)->get(route('logbook.show', $entry))->assertOk()->assertSee('Keputusan Review');
        $this->actingAs($this->dosenLain)->post(route('logbook.approve', $entry))->assertForbidden();
        $this->actingAs($this->penguji)->post(route('logbook.approve', $entry))->assertRedirect();
        $this->assertSame(LogbookEntry::STATUS_APPROVED, $entry->fresh()->status);
    }

    public function test_logbook_draft_menyimpan_penerima_untuk_dikirim_nanti(): void
    {
        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $this->logbookPayload($this->penguji->id, false))
            ->assertRedirect(route('logbook.index'));

        $entry = $this->ta->entries()->where('jenis', LogbookEntry::JENIS_LOGBOOK)->latest('id')->firstOrFail();
        $this->assertSame(LogbookEntry::STATUS_DRAFT, $entry->status);
        $this->assertSame($this->penguji->id, $entry->dosen_id);
        $this->assertSame(0, $this->penguji->fresh()->notifications()->count());
        $this->assertFalse($this->penguji->can('review', $entry));

        $this->actingAs($this->mahasiswa)->post(route('logbook.submit', $entry))->assertRedirect();
        $this->assertTrue($this->penguji->can('review', $entry->fresh()));
        $this->assertSame(1, $this->penguji->fresh()->notifications()->count());
        $this->assertSame(1, $this->pembimbing->fresh()->notifications()->count());
    }

    public function test_penguji_penerima_logbook_dapat_meminta_revisi(): void
    {
        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $this->logbookPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->ta->entries()->where('jenis', LogbookEntry::JENIS_LOGBOOK)->latest('id')->firstOrFail();
        $this->actingAs($this->penguji)->post(route('logbook.request-revisi', $entry), [
            'feedback_dosen' => 'Mohon lengkapi pembahasan dan perbaiki kesimpulan bab terakhir.',
        ])->assertRedirect();

        $this->assertSame(LogbookEntry::STATUS_REVISI, $entry->fresh()->status);
    }

    public function test_pembimbing_dua_penerima_logbook_tidak_mendapat_notifikasi_ganda(): void
    {
        $this->ta->update(['pembimbing_2_id' => $this->penguji->id]);

        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $this->logbookPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->ta->entries()->where('jenis', LogbookEntry::JENIS_LOGBOOK)->latest('id')->firstOrFail();
        $this->assertSame($this->penguji->id, $entry->reviewDosen()?->id);
        $this->assertSame(1, $this->penguji->fresh()->notifications()->count());
        $this->assertSame(1, $this->pembimbing->fresh()->notifications()->count());
    }

    public function test_logbook_lama_tanpa_penerima_menggunakan_pembimbing(): void
    {
        $this->parent->update(['dosen_id' => null]);

        $this->assertSame($this->pembimbing->id, $this->parent->fresh()->reviewDosen()?->id);
    }

    public function test_logbook_penerima_tidak_sah_ditolak_dan_tanpa_pilihan_default_ke_pembimbing(): void
    {
        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $this->logbookPayload($this->dosenLain->id))
            ->assertSessionHasErrors('addressed_dosen_id');
        $this->assertSame(1, $this->ta->entries()->count());

        $payload = $this->logbookPayload(null, false);
        unset($payload['addressed_dosen_id']);
        $this->actingAs($this->mahasiswa)->post(route('logbook.store'), $payload)->assertRedirect();
        $entry = $this->ta->entries()->where('jenis', LogbookEntry::JENIS_LOGBOOK)->latest('id')->firstOrFail();
        $this->assertSame($this->pembimbing->id, $entry->dosen_id);
    }

    private function entryRevisiTerakhir(): LogbookEntry
    {
        return LogbookEntry::where('parent_entry_id', $this->parent->id)->firstOrFail();
    }

    public function test_label_peran_dosen_mengenali_pembimbing_dan_penguji(): void
    {
        $this->assertSame('Pembimbing 1', $this->ta->dosenRoleLabel($this->pembimbing));
        $this->assertSame('Penguji 1', $this->ta->dosenRoleLabel($this->penguji));
        $this->assertNull($this->ta->dosenRoleLabel($this->dosenLain));

        $options = $this->ta->dosenRecipientOptions();
        $this->assertArrayHasKey($this->pembimbing->id, $options);
        $this->assertArrayHasKey($this->penguji->id, $options);
        $this->assertStringContainsString('Penguji 1 — Penguji Satu', $options[$this->penguji->id]);
    }

    public function test_dosen_ganda_peran_hanya_muncul_sekali_dengan_label_gabungan(): void
    {
        // Mode individual: satu dosen menjadi pembimbing 1 sekaligus penguji 1.
        $this->ta->update(['penguji_1_id' => $this->pembimbing->id]);

        $ta = $this->ta->fresh();
        $this->assertSame('Pembimbing 1 & Penguji 1', $ta->dosenRoleLabel($this->pembimbing));

        $options = $ta->dosenRecipientOptions();
        $dosenIds = array_keys($options);
        $this->assertSame([$this->pembimbing->id], array_values(array_filter($dosenIds, fn ($id) => $id === $this->pembimbing->id)));
        $this->assertContains('Pembimbing 1 & Penguji 1 — Pembimbing Satu', $options);
        $this->assertCount(1, $options);
    }

    public function test_mahasiswa_dapat_mengirim_revisi_kepada_dosen_penguji(): void
    {
        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $this->revisiPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->entryRevisiTerakhir();
        $this->assertSame(LogbookEntry::JENIS_REVISI, $entry->jenis);
        $this->assertSame($this->penguji->id, $entry->dosen_id);
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $entry->status);
        $this->assertSame($this->penguji->id, $entry->reviewDosen()?->id);

        // Penerima (penguji) dan pembimbing (CC) sama-sama diberi tahu.
        $this->assertSame(1, $this->penguji->fresh()->notifications()->count());
        $this->assertSame(1, $this->pembimbing->fresh()->notifications()->count());
    }

    public function test_revisi_tanpa_penerima_tetap_ke_pembimbing(): void
    {
        $payload = $this->revisiPayload($this->pembimbing->id);
        unset($payload['addressed_dosen_id']);

        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $payload)
            ->assertRedirect();

        $this->assertSame($this->pembimbing->id, $this->entryRevisiTerakhir()->dosen_id);
    }

    public function test_penerima_di_luar_program_ditolak(): void
    {
        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $this->revisiPayload($this->dosenLain->id))
            ->assertSessionHasErrors('addressed_dosen_id');
    }

    public function test_dosen_penguji_dapat_membuka_dan_menyetujui_revisi(): void
    {
        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $this->revisiPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->entryRevisiTerakhir();

        // Penguji (penerima) = reviewer penuh.
        $this->actingAs($this->penguji)->get(route('logbook.show', $entry))->assertOk();
        $this->actingAs($this->penguji)->get(route('logbook.pdf-viewer', $entry))->assertOk();
        $this->actingAs($this->penguji)->post(route('logbook.approve', $entry))->assertRedirect();

        $this->assertSame(LogbookEntry::STATUS_APPROVED, $entry->fresh()->status);
    }

    public function test_dosen_penguji_dapat_meminta_revisi(): void
    {
        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $this->revisiPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->entryRevisiTerakhir();

        $this->actingAs($this->penguji)
            ->post(route('logbook.request-revisi', $entry), [
                'feedback_dosen' => 'Bagian analisis masih perlu diperbaiki lagi dengan detail.',
            ])
            ->assertRedirect();

        $this->assertSame(LogbookEntry::STATUS_REVISI, $entry->fresh()->status);
    }

    public function test_dosen_penguji_luar_program_tidak_dapat_menyetujui(): void
    {
        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $this->revisiPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->entryRevisiTerakhir();

        $this->actingAs($this->dosenLain)->post(route('logbook.approve', $entry))->assertForbidden();
    }

    public function test_pembimbing_tetap_dapat_mereview_revisi_untuk_penguji(): void
    {
        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $this->revisiPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->entryRevisiTerakhir();

        $this->assertTrue($this->pembimbing->can('review', $entry));
        $this->actingAs($this->pembimbing)->post(route('logbook.approve', $entry))->assertRedirect();
        $this->assertSame(LogbookEntry::STATUS_APPROVED, $entry->fresh()->status);
    }

    public function test_penguji_penerima_muncul_di_antrean_review(): void
    {
        $this->actingAs($this->mahasiswa)
            ->post(route('logbook.store-revisi'), $this->revisiPayload($this->penguji->id))
            ->assertRedirect();

        $entry = $this->entryRevisiTerakhir();

        $this->assertTrue($this->penguji->can('review', $entry));
        $this->actingAs($this->penguji)->get(route('quick-review.index'))->assertOk();
    }

    public function test_dosen_peran_ganda_hanya_muncul_sekali_dengan_label_gabungan(): void
    {
        $this->ta->update(['pembimbing_2_id' => $this->penguji->id]);

        $options = $this->ta->fresh()->dosenRecipientOptions();

        $this->assertCount(2, $options);
        $this->assertSame(
            'Pembimbing 2 & Penguji 1 — Penguji Satu',
            $options[$this->penguji->id]
        );
        $this->assertSame(
            'Pembimbing 1 — Pembimbing Satu',
            $options[$this->pembimbing->id]
        );
    }

    public function test_form_revisi_menampilkan_pilihan_penguji(): void
    {
        $response = $this->actingAs($this->mahasiswa)->get(route('logbook.create-revisi'));

        $response->assertOk();
        $response->assertSee('Kirim kepada (penerima perbaikan)');
        $response->assertSee('Penguji 1 — Penguji Satu');
        $response->assertSee('Pembimbing 1 — Pembimbing Satu');
    }
}
