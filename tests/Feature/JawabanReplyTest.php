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
            ->assertSee('Balasan Anda', false)
            ->assertSee('Bagus, tabel sudah benar.', false)
            ->assertSee('data-jawaban-bubble-wrap', false)
            ->assertSee('data-jawaban-bubble-text', false)
            ->assertSee('data-jawaban-bubble-time', false);

        // Mahasiswa melihat sudut pandang orang ketiga + stempel waktu persisten.
        $this->actingAs($this->mhs)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('Balasan Dosen', false)
            ->assertDontSee('Balasan Anda', false);
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

    public function test_whitespace_reply_is_treated_as_empty(): void
    {
        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 0,
            'reply' => '   ',
        ])->assertOk();

        $rows = $this->entrySubmitted->fresh()->riwayat_perbaikan;
        $this->assertArrayNotHasKey('dosen_reply', $rows[0]);

        // Tanpa bubble berlabel kosong di halaman (hook di template JS
        // abaikan — yang dihitung hanya bubble ter-render berisi teks).
        $html = $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()->getContent();
        preg_match_all('/data-jawaban-bubble-text">(.*?)<\/p>/s', $html, $m);
        $filled = array_filter(array_map('trim', $m[1]));
        $this->assertCount(0, $filled);
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

    public function test_identical_rows_collapse_on_save(): void
    {
        // Tiga baris ber-KONTEN sama (beda status) runtuh jadi satu;
        // status terisi dipertahankan.
        $mhs3 = \App\Models\User::create([
            'name' => 'Mhs Dobel', 'email' => 'dobel@test.com',
            'password' => bcrypt('password'), 'nim' => 'DOBEL001',
        ]);
        $mhs3->assignRole('mahasiswa');
        \App\Models\MahasiswaTa::create([
            'user_id' => $mhs3->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Dobel',
            'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);
        $row = ['halaman' => 'Hal. 1', 'komentar_dosen' => 'Rapikan', 'perbaikan' => 'Sudah dirapikan', 'status' => 'Sudah'];

        $this->actingAs($mhs3)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Dobel baris',
            'lampiran' => \Illuminate\Http\UploadedFile::fake()->create('sesi.pdf', 100, 'application/pdf'),
            'riwayat_perbaikan' => [$row, $row, $row],
            'submit' => '1',
        ])->assertRedirect();

        $entry = LogbookEntry::where('jenis', LogbookEntry::JENIS_LOGBOOK)
            ->where('status', LogbookEntry::STATUS_SUBMITTED)->latest('id')->firstOrFail();
        $this->assertCount(1, $entry->riwayat_perbaikan);
        $this->assertSame('Sudah', $entry->riwayat_perbaikan[0]['status']);
    }

    public function test_revisi_salin_source_is_parent_comments(): void
    {
        // Data komentar induk tertanam di halaman untuk tombol Salin.
        $mhs4 = \App\Models\User::create([
            'name' => 'Mhs Induk', 'email' => 'induk@test.com',
            'password' => bcrypt('password'), 'nim' => 'INDUK001',
        ]);
        $mhs4->assignRole('mahasiswa');
        $ta4 = \App\Models\MahasiswaTa::create([
            'user_id' => $mhs4->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Induk',
            'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);
        $parent = LogbookEntry::create([
            'mahasiswa_ta_id' => $ta4->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'status' => LogbookEntry::STATUS_REVISI,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
            'feedback_dosen' => 'Perbaiki metode.',
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Induk salin',
            'sesi_ke' => 1,
        ]);

        $this->actingAs($mhs4)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $parent->id]))
            ->assertOk()
            ->assertSee('data-parent-feedback="'.$parent->id.'"', false)
            ->assertSee('data-comments', false);
    }

    public function test_pull_skips_answered_annotations(): void
    {
        $mhs5 = \App\Models\User::create([
            'name' => 'Mhs Tarik', 'email' => 'tarik@test.com',
            'password' => bcrypt('password'), 'nim' => 'TARIK001',
        ]);
        $mhs5->assignRole('mahasiswa');
        $ta5 = \App\Models\MahasiswaTa::create([
            'user_id' => $mhs5->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Tarik',
            'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);
        $draft = LogbookEntry::create([
            'mahasiswa_ta_id' => $ta5->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'status' => LogbookEntry::STATUS_DRAFT,
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Draf tarik',
        ]);

        // Berbalasan di viewer → dilewati (jawabannya sudah ada di thread).
        $answered = $draft->comments()->create([
            'user_id' => $mhs5->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 1, 'comment' => 'Sudah dijawab di viewer.',
            'payload' => ['type' => 'highlight', 'page' => 1],
            'resolution_status' => PdfComment::STATUS_ADDRESSED, 'is_resolved' => false,
        ]);
        $answered->replies()->create(['user_id' => $this->dosen->id, 'body' => 'Oke.']);
        // Klaim tanpa tulisan (addressed tanpa balasan) → tetap ditarik.
        $draft->comments()->create([
            'user_id' => $mhs5->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 2, 'comment' => 'Klaim kosong.',
            'payload' => ['type' => 'highlight', 'page' => 2],
            'resolution_status' => PdfComment::STATUS_ADDRESSED, 'is_resolved' => false,
        ]);

        $out = $this->actingAs($mhs5)->postJson(route('logbook.annotations.pull', $draft))
            ->assertOk();
        $this->assertSame(1, $out->json('pulled'));
        $this->assertSame(1, $out->json('skipped_answered'));
        $rows = $draft->fresh()->riwayat_perbaikan;
        $this->assertCount(1, $rows);
        $this->assertSame('Klaim kosong.', $rows[0]['perbaikan']);
    }

    public function test_owner_can_ack_and_dosen_is_notified(): void
    {
        Notification::fake();

        $rows = $this->entrySubmitted->riwayat_perbaikan;
        $rows[0]['dosen_reply'] = 'Perbaiki lagi.';
        $rows[0]['dosen_reply_at'] = now()->toIso8601String();
        $this->entrySubmitted->update(['riwayat_perbaikan' => $rows]);

        // Bukan pemilik → 403.
        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-ack', $this->entrySubmitted), [
            'index' => 0,
        ])->assertForbidden();

        $this->actingAs($this->mhs)->postJson(route('logbook.jawaban-ack', $this->entrySubmitted), [
            'index' => 0,
        ])->assertOk()->assertJson(['ok' => true]);

        $fresh = $this->entrySubmitted->fresh()->riwayat_perbaikan;
        $this->assertTrue((bool) ($fresh[0]['dosen_reply_ack'] ?? false));
        $this->assertNotEmpty($fresh[0]['dosen_reply_ack_at']);

        Notification::assertSentTo(
            $this->dosen,
            ActivityNotification::class,
            fn ($n) => str_contains($n->message, 'menindaklanjuti')
        );

        // Tampil di halaman detail.
        $this->actingAs($this->mhs)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('Sudah ditindaklanjuti', false);
    }

    public function test_new_reply_resets_ack_and_resubmit_preserves_it(): void
    {
        $rows = $this->entrySubmitted->riwayat_perbaikan;
        $rows[0]['dosen_reply'] = 'Lama.';
        $rows[0]['dosen_reply_ack'] = true;
        $rows[0]['dosen_reply_ack_at'] = now()->subHour()->toIso8601String();
        $this->entrySubmitted->update(['riwayat_perbaikan' => $rows]);

        // Balasan baru menggugurkan ack lama.
        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 0,
            'reply' => 'Baru, lebih rinci.',
        ])->assertOk();
        $after = $this->entrySubmitted->fresh()->riwayat_perbaikan;
        $this->assertArrayNotHasKey('dosen_reply_ack', $after[0]);

        // Ack ulang lalu kirim-ulang mahasiswa: ack dipertahankan.
        $this->actingAs($this->mhs)->postJson(route('logbook.jawaban-ack', $this->entrySubmitted), [
            'index' => 0,
        ])->assertOk();
        $this->entryDraft->update(['riwayat_perbaikan' => [
            ['halaman' => 'Bab 1', 'komentar_dosen' => 'K', 'perbaikan' => 'P', 'status' => 'Sudah'],
        ]]);
        $this->actingAs($this->mhs)->put(route('logbook.update', $this->entryDraft), [
            'topik' => $this->entryDraft->topik,
            'tanggal_bimbingan' => now()->toDateString(),
            'riwayat_perbaikan' => [
                ['halaman' => 'Bab 1', 'komentar_dosen' => 'K', 'perbaikan' => 'P2', 'status' => 'Sudah'],
            ],
        ])->assertRedirect();
        // Draf tanpa ack tetap tanpa ack (tak bisa disuntik via form).
        $this->assertArrayNotHasKey('dosen_reply_ack', $this->entryDraft->fresh()->riwayat_perbaikan[0]);
    }

    public function test_resolve_source_marks_annotation_done(): void
    {
        $comment = $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 1, 'comment' => 'Rapikan tabel.',
            'payload' => ['type' => 'highlight', 'page' => 1],
            'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
        ]);
        $rows = $this->entrySubmitted->riwayat_perbaikan;
        $rows[0]['komentar_dosen'] = 'Rapikan tabel.';
        $rows[0]['komentar_comment_id'] = $comment->id;
        $this->entrySubmitted->update(['riwayat_perbaikan' => $rows]);

        // Chip status tampil di kartu.
        $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('Anotasi: Baru', false);

        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 0,
            'reply' => 'Baik.',
            'resolve_source' => true,
        ])->assertOk()->assertJson(['ok' => true, 'source_resolved' => true]);

        $this->assertSame(PdfComment::STATUS_RESOLVED, $comment->fresh()->resolution_status);

        // ID palsu (milik entri lain) diabaikan tanpa crash.
        $rows = $this->entrySubmitted->fresh()->riwayat_perbaikan;
        $rows[1]['komentar_comment_id'] = 999999;
        $this->entrySubmitted->update(['riwayat_perbaikan' => $rows]);
        $this->actingAs($this->dosen)->postJson(route('logbook.jawaban-reply', $this->entrySubmitted), [
            'index' => 1,
            'reply' => 'Oke.',
            'resolve_source' => true,
        ])->assertOk()->assertJson(['source_resolved' => false]);
    }

    public function test_export_pdf_contains_reply_column(): void
    {
        $html = view('exports.catatan-perbaikan', [
            'logbook' => $this->entrySubmitted,
            'riwayat' => [
                ['halaman' => 'Hal. 1', 'komentar_dosen' => 'K', 'perbaikan' => 'P', 'status' => 'Sudah', 'dosen_reply' => 'Balasan cetak.'],
            ],
            'pesan' => null,
        ])->render();

        $this->assertStringContainsString('Balasan Dosen', $html);
        $this->assertStringContainsString('Balasan cetak.', $html);
    }

    public function test_combined_status_sentence_renders(): void
    {
        // Form baru: belum ada simpanan.
        $this->actingAs($this->mhs)->get(route('logbook.create'))
            ->assertOk()
            ->assertSee('Belum ada simpanan — mulai mengisi', false)
            ->assertDontSee('Disimpan di server', false)
            ->assertSee('data-status-combined', false);
    }
}
