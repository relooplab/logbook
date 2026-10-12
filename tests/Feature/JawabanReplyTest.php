<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\PdfComment;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;

class JawabanReplyTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ta->update([
            'pembimbing_1_id' => $this->dosen->id,
            'pembimbing_2_id' => $this->dosen2->id,
            'status_ta' => 'aktif',
        ]);
        $this->entrySubmitted->update([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'riwayat_perbaikan' => [
                ['halaman' => 'Bab 4', 'komentar_dosen' => 'Perbaiki tabel', 'perbaikan' => 'Sudah diperbaiki', 'status' => 'Sudah'],
                ['halaman' => 'Bab 5', 'komentar_dosen' => 'Tambah sumber', 'perbaikan' => 'Menambah dua sumber', 'status' => 'Sebagian'],
            ],
        ]);
    }

    public function test_reviewer_can_save_and_read_row_reply(): void
    {
        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 0,
            'reply' => 'Bagus, tabel sudah benar.',
        ])->assertOk()->assertJson(['ok' => true]);

        $rows = $this->entrySubmitted->fresh()->riwayat_perbaikan;
        $this->assertSame('Bagus, tabel sudah benar.', $rows[0]['dosen_reply']);
        $this->assertNotEmpty($rows[0]['dosen_reply_at']);
        $this->assertArrayNotHasKey('dosen_reply', $rows[1]);

        // Tampil read-only di halaman detail + kolom Balasan Dosen ada.
        $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('Balasan Dosen', false)
            ->assertSee('Bagus, tabel sudah benar.', false);
    }

    public function test_empty_reply_clears_existing(): void
    {
        $rows = $this->entrySubmitted->riwayat_perbaikan;
        $rows[0]['dosen_reply'] = 'Sementara.';
        $rows[0]['dosen_reply_at'] = now()->toIso8601String();
        $this->entrySubmitted->update(['riwayat_perbaikan' => $rows]);

        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 0,
            'reply' => '',
        ])->assertOk();

        $this->assertArrayNotHasKey('dosen_reply', $this->entrySubmitted->fresh()->riwayat_perbaikan[0]);
    }

    public function test_non_reviewer_cannot_save_row_reply(): void
    {
        // Pemilik (mahasiswa) tidak boleh mengisi balasan dosen.
        $this->actingAs($this->mhs)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 0,
            'reply' => 'Palsu.',
        ])->assertForbidden();

        $outsider = \App\Models\User::create([
            'name' => 'Dosen Luar Balas', 'email' => 'dosen-luar-balas@test.com',
            'password' => bcrypt('password'),
        ]);
        $outsider->assignRole('dosen');
        $this->actingAs($outsider)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 0,
            'reply' => 'Palsu.',
        ])->assertForbidden();

        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 99,
            'reply' => 'Baris tak ada.',
        ])->assertNotFound();
    }

    public function test_mahasiswa_resubmit_preserves_and_cannot_inject_reply(): void
    {
        $rows = $this->entrySubmitted->riwayat_perbaikan;
        $rows[0]['dosen_reply'] = 'Tetap ada.';
        $rows[0]['dosen_reply_at'] = now()->toIso8601String();
        $this->entrySubmitted->update(['riwayat_perbaikan' => $rows]);

        // Suntikan dosen_reply dari request mahasiswa dibuang (baris lama
        // bersih sehingga preservasi tidak mengembalikannya).
        $this->entryDraft->update([
            'riwayat_perbaikan' => [
                ['halaman' => 'Bab 1', 'komentar_dosen' => 'Komen', 'perbaikan' => 'Baik', 'status' => 'Sudah'],
            ],
        ]);
        $this->actingAs($this->mhs)->put(route('logbook.update', $this->entryDraft), [
            'topik' => $this->entryDraft->topik,
            'tanggal_bimbingan' => now()->toDateString(),
            'riwayat_perbaikan' => [
                ['halaman' => 'Bab 1', 'komentar_dosen' => 'Komen', 'perbaikan' => 'Baik diubah', 'status' => 'Sudah', 'dosen_reply' => 'Suntikan jahat'],
            ],
        ])->assertRedirect();

        $fresh = $this->entryDraft->fresh()->riwayat_perbaikan;
        $this->assertArrayNotHasKey('dosen_reply', $fresh[0]);
        $this->assertSame('Baik diubah', $fresh[0]['perbaikan']);

        // Balasan dosen yang sah dipertahankan saat mahasiswa kirim ulang.
        $rows = $fresh;
        $rows[0]['dosen_reply'] = 'Tetap ada.';
        $this->entryDraft->update(['riwayat_perbaikan' => $rows]);
        $this->actingAs($this->mhs)->put(route('logbook.update', $this->entryDraft), [
            'topik' => $this->entryDraft->topik,
            'tanggal_bimbingan' => now()->toDateString(),
            'riwayat_perbaikan' => [
                ['halaman' => 'Bab 1', 'komentar_dosen' => 'Komen', 'perbaikan' => 'Baik final', 'status' => 'Sudah'],
            ],
        ])->assertRedirect();

        $again = $this->entryDraft->fresh()->riwayat_perbaikan;
        $this->assertSame('Tetap ada.', $again[0]['dosen_reply']);
        $this->assertSame('Baik final', $again[0]['perbaikan']);
    }

    public function test_build_feedback_includes_threads_and_row_replies(): void
    {
        // Hanya anotasi milik reviewer login yang ditarik (beserta thread);
        // anotasi mahasiswa tidak ikut.
        $this->entrySubmitted->comments()->create([
            'user_id' => $this->mhs->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 2, 'comment' => 'Anotasi mahasiswa, jangan ketarik.',
            'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
        ]);
        $comment = $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 3, 'comment' => 'Perjelas metode.',
            'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
        ]);
        $comment->replies()->create(['user_id' => $this->mhs->id, 'body' => 'Siap, dilengkapi.']);

        $rows = $this->entrySubmitted->riwayat_perbaikan;
        $rows[1]['dosen_reply'] = 'Sumber cukup.';
        $this->entrySubmitted->update(['riwayat_perbaikan' => $rows]);

        $feedback = $this->actingAs($this->dosen)
            ->postJson(route('quick-review.build-feedback', $this->entrySubmitted))
            ->assertOk()
            ->json('feedback');

        $this->assertStringNotContainsString('jangan ketarik', $feedback);
        $this->assertStringContainsString('Perjelas metode. | Hal. 3', $feedback);
        $this->assertStringContainsString('→ dibalas mahasiswa: Siap, dilengkapi.', $feedback);
        $this->assertStringContainsString('Tambah sumber → Menambah dua sumber → Sumber cukup. | Hal. Bab 5', $feedback);
    }

    public function test_show_uses_balanced_grid(): void
    {
        $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('detail-workspace-grid--balanced', false);
    }

    public function test_row_reply_notifies_owner_with_deep_link(): void
    {
        Notification::fake();

        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 1,
            'reply' => 'Sumber sudah cukup.',
        ])->assertOk();

        Notification::assertSentTo(
            $this->mhs,
            ActivityNotification::class,
            fn ($n) => str_contains($n->message, 'baris 2')
                && str_ends_with($n->url, '#jawaban-baris-1')
        );

        // Anchor tersedia di kartu utas.
        $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('id="jawaban-baris-1"', false);
    }

    public function test_queue_shows_peer_opener_and_unread_chips(): void
    {
        $this->entrySubmitted->update([
            'review_opened_at' => now()->subMinutes(10),
            'review_opened_by' => $this->dosen2->id,
        ]);
        $this->mhs->notify(new ActivityNotification(
            'Ping',
            route('logbook.show', $this->entrySubmitted),
            'Tes',
        ));
        $this->dosen->notify(new ActivityNotification(
            'Ping dosen',
            route('logbook.show', $this->entrySubmitted),
            'Tes',
        ));

        $html = $this->actingAs($this->dosen)->get(route('materials-review.index'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Dibuka', $html);
        $this->assertStringContainsString('>Baru<', $html);
    }

    public function test_stale_decision_redirects_with_friendly_error(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_APPROVED]);

        $this->actingAs($this->dosen)->post(route('logbook.approve', $this->entrySubmitted), [
            'feedback_dosen' => 'Terlambat.',
        ])->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($this->dosen)->post(route('quick-review.approve-next', $this->entrySubmitted), [
            'feedback_dosen' => 'Terlambat.',
        ])->assertRedirect(route('quick-review.index'))
            ->assertSessionHas('error');
    }

    public function test_logbook_submit_without_jawaban_rows_succeeds(): void
    {
        $mhs2 = \App\Models\User::create([
            'name' => 'Mhs Ringan', 'email' => 'ringan@test.com',
            'password' => bcrypt('password'), 'nim' => 'RINGAN001',
        ]);
        $mhs2->assignRole('mahasiswa');
        \App\Models\MahasiswaTa::create([
            'user_id' => $mhs2->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Ringan',
            'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);

        $this->actingAs($mhs2)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Sesi tanpa jawaban',
            'lampiran' => \Illuminate\Http\UploadedFile::fake()->create('sesi.pdf', 100, 'application/pdf'),
            'submit' => '1',
        ])->assertRedirect();

        $entry = LogbookEntry::where('jenis', LogbookEntry::JENIS_LOGBOOK)
            ->where('status', LogbookEntry::STATUS_SUBMITTED)->latest('id')->firstOrFail();
        $this->assertNull($entry->riwayat_perbaikan);
    }

    public function test_combined_status_sentence_renders(): void
    {
        // Form baru: belum ada simpanan.
        $this->actingAs($this->mhs)->get(route('logbook.create'))
            ->assertOk()
            ->assertSee('Belum ada simpanan di server', false)
            ->assertSee('data-status-combined', false);
    }
}
