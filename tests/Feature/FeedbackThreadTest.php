<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class FeedbackThreadTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    private function makeEntry(array $overrides): LogbookEntry
    {
        return LogbookEntry::create(array_merge([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => null,
            'topik' => 'Topik thread',
            'progres_kendala' => 'Progres',
            'tanggal_bimbingan' => now(),
            'status' => LogbookEntry::STATUS_REVISI,
        ], $overrides));
    }

    public function test_feedback_chain_renders_as_single_thread(): void
    {
        $parent = $this->makeEntry([
            'sesi_ke' => 11,
            'feedback_dosen' => 'Perbaiki bab 1.',
            'reviewed_at' => now()->subDays(3),
        ]);
        $child = $this->makeEntry([
            'jenis' => LogbookEntry::JENIS_REVISI,
            'parent_entry_id' => $parent->id,
            'revision_round' => 1,
            'topik' => $parent->topik,
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->mhs)->get(route('logbook.feedback'));

        $response->assertOk();
        $content = $response->getContent();
        // Satu kartu thread untuk satu rantai.
        $this->assertSame(1, substr_count($content, '<article'));
        $this->assertStringContainsString('1 feedback · 1 revisi', $content);
        $this->assertStringContainsString('Perbaiki bab 1.', $content);
        // Status terbaru (submitted) tampil di header thread.
        $this->assertStringContainsString('Menunggu Review', $content);
        // Revisi sudah dikirim: aksi primer berupa info menunggu, bukan Buat Revisi.
        $this->assertStringContainsString('Menunggu review dosen', $content);
        $this->assertStringNotContainsString('Buat Revisi', $content);
    }

    public function test_separate_roots_render_as_separate_threads(): void
    {
        $this->makeEntry(['sesi_ke' => 21, 'topik' => 'Thread A', 'feedback_dosen' => 'Feedback A.', 'reviewed_at' => now()->subDays(2)]);
        $this->makeEntry(['sesi_ke' => 22, 'topik' => 'Thread B', 'feedback_dosen' => 'Feedback B.', 'reviewed_at' => now()->subDay()]);

        $response = $this->actingAs($this->mhs)->get(route('logbook.feedback'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertSame(2, substr_count($content, '<article'));
        $this->assertStringContainsString('Thread A', $content);
        $this->assertStringContainsString('Thread B', $content);
    }

    public function test_thread_without_feedback_is_excluded(): void
    {
        $this->makeEntry(['sesi_ke' => 31, 'topik' => 'Tanpa feedback', 'feedback_dosen' => null]);

        $response = $this->actingAs($this->mhs)->get(route('logbook.feedback'));

        $response->assertOk();
        $this->assertStringNotContainsString('Tanpa feedback', $response->getContent());
    }

    public function test_revision_requested_thread_offers_buat_revisi_once(): void
    {
        $this->makeEntry([
            'sesi_ke' => 41,
            'topik' => 'Butuh revisi',
            'feedback_dosen' => 'Mohon diperbaiki.',
            'reviewed_at' => now()->subDay(),
            'status' => LogbookEntry::STATUS_REVISI,
        ]);

        $response = $this->actingAs($this->mhs)->get(route('logbook.feedback'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'Buat Revisi'));
    }

    public function test_events_render_in_chronological_order(): void
    {
        $this->makeEntry([
            'sesi_ke' => 51,
            'topik' => 'Urutan kronologis',
            'feedback_dosen' => 'Perbaiki bagian ini.',
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
            'status' => LogbookEntry::STATUS_REVISI,
        ]);

        $content = $this->actingAs($this->mhs)->get(route('logbook.feedback'))->getContent();

        // Logbook dikirim (lebih dulu) harus tampil sebelum umpan balik diterima.
        $this->assertLessThan(
            strpos($content, 'Umpan balik diterima'),
            strpos($content, 'Logbook dikirim'),
            'Logbook dikirim harus tampil sebelum Umpan balik diterima.'
        );
    }

    public function test_revision_feedback_merged_into_review_event(): void
    {
        $parent = $this->makeEntry([
            'sesi_ke' => 61,
            'topik' => 'Revisi berfeedback',
            'feedback_dosen' => 'Feedback awal.',
            'reviewed_at' => now()->subDays(3),
        ]);
        $this->makeEntry([
            'jenis' => LogbookEntry::JENIS_REVISI,
            'parent_entry_id' => $parent->id,
            'revision_round' => 1,
            'topik' => $parent->topik,
            'feedback_dosen' => 'Masih kurang tepat.',
            'status' => LogbookEntry::STATUS_REVISI,
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
        ]);

        $content = $this->actingAs($this->mhs)->get(route('logbook.feedback'))->getContent();

        // Feedback node revisi tampil sekali, di dalam event hasil review.
        $this->assertSame(1, substr_count($content, 'Masih kurang tepat.'));
        $this->assertStringContainsString('Revisi diminta', $content);
        // "Umpan balik diterima" hanya untuk node logbook (1x).
        $this->assertSame(1, substr_count($content, 'Umpan balik diterima'));
        // Revisi dibuat (tanpa feedback) tampil sebelum Revisi dikirim.
        $this->assertLessThan(
            strpos($content, 'Revisi dikirim'),
            strpos($content, 'Revisi dibuat'),
            'Revisi dibuat harus tampil sebelum Revisi dikirim.'
        );
    }
}
