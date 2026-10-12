<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\ReviewDraft;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ReviewDraftTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // DB pengujian dipakai bersama antar-run (firstOrCreate bisa
        // menemukan baris basi) — paksa fixture ke bentuk yang dibutuhkan.
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
        ]);
    }

    public function test_reviewer_can_upsert_and_resume_draft(): void
    {
        // entrySubmitted milik program yang dibimbing dosen → bisa review.
        $response = $this->actingAs($this->dosen)->postJson(route('review-drafts.store'), [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'feedback_dosen' => 'Perbaiki metodologi, tambah kutipan.',
            'review_decision' => 'revisi',
        ]);
        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('review_drafts', [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'user_id' => $this->dosen->id,
            'review_decision' => 'revisi',
        ]);

        // Upsert kedua menimpa, bukan menambah baris.
        $this->actingAs($this->dosen)->postJson(route('review-drafts.store'), [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'feedback_dosen' => 'Revisi kecil saja.',
            'review_decision' => 'approve',
        ])->assertOk();
        $this->assertSame(1, ReviewDraft::where('logbook_entry_id', $this->entrySubmitted->id)
            ->where('user_id', $this->dosen->id)->count());

        // Prefill di halaman detail + badge draf + stempel server.
        $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('Revisi kecil saja.', false)
            ->assertSee('Dimuat dari draf', false)
            ->assertSee('Draf tersimpan', false)
            ->assertSee('data-draf-saved-at', false)
            ->assertSee('Terakhir disimpan', false);

        // Prefill di quick-review (pilih item eksplisit — antrean bisa
        // berisi entri basi dari run lain).
        $this->actingAs($this->dosen)
            ->get(route('quick-review.index', ['item' => $this->entrySubmitted->id]))
            ->assertOk()
            ->assertSee('Revisi kecil saja.', false)
            ->assertSee('Dimuat dari draf', false);
    }

    public function test_non_reviewer_cannot_save_draft(): void
    {
        $outsider = \App\Models\User::create([
            'name' => 'Dosen Luar', 'email' => 'dosen-luar@test.com',
            'password' => bcrypt('password'),
        ]);
        $outsider->assignRole('dosen');

        $this->actingAs($outsider)->postJson(route('review-drafts.store'), [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'feedback_dosen' => 'Coba simpan.',
        ])->assertForbidden();

        $this->actingAs($outsider)
            ->deleteJson(route('review-drafts.destroy', $this->entrySubmitted))
            ->assertForbidden();
    }

    public function test_store_conflicts_when_entry_already_decided(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_APPROVED]);

        $this->actingAs($this->dosen)->postJson(route('review-drafts.store'), [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'feedback_dosen' => 'Terlambat.',
        ])->assertConflict();
    }

    public function test_final_decision_deletes_own_draft(): void
    {
        ReviewDraft::create([
            'logbook_entry_id' => $this->entrySubmitted->id,
            'user_id' => $this->dosen->id,
            'feedback_dosen' => 'Draf yang akan terhapus.',
            'review_decision' => 'approve',
        ]);

        $this->actingAs($this->dosen)->post(route('logbook.approve', $this->entrySubmitted), [
            'feedback_dosen' => 'Disetujui.',
        ])->assertRedirect();

        $this->assertDatabaseMissing('review_drafts', [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'user_id' => $this->dosen->id,
        ]);
    }

    public function test_two_reviewers_have_independent_drafts(): void
    {
        // dosen2 adalah pembimbing 2 program → juga reviewer.
        $this->actingAs($this->dosen)->postJson(route('review-drafts.store'), [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'feedback_dosen' => 'Versi dosen 1.',
            'review_decision' => 'approve',
        ])->assertOk();

        $this->actingAs($this->dosen2)->postJson(route('review-drafts.store'), [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'feedback_dosen' => 'Versi dosen 2.',
            'review_decision' => 'revisi',
        ])->assertOk();

        $this->assertSame('Versi dosen 1.', ReviewDraft::where('logbook_entry_id', $this->entrySubmitted->id)
            ->where('user_id', $this->dosen->id)->value('feedback_dosen'));
        $this->assertSame('Versi dosen 2.', ReviewDraft::where('logbook_entry_id', $this->entrySubmitted->id)
            ->where('user_id', $this->dosen2->id)->value('feedback_dosen'));

        // Masing-masing hanya melihat drafnya sendiri di halaman detail.
        $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()
            ->assertSee('Versi dosen 1.', false)
            ->assertDontSee('Versi dosen 2.', false);
    }

    public function test_owner_can_discard_draft(): void
    {
        ReviewDraft::create([
            'logbook_entry_id' => $this->entrySubmitted->id,
            'user_id' => $this->dosen->id,
            'feedback_dosen' => 'Akan dibuang.',
        ]);

        $this->actingAs($this->dosen)
            ->deleteJson(route('review-drafts.destroy', $this->entrySubmitted))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('review_drafts', [
            'logbook_entry_id' => $this->entrySubmitted->id,
            'user_id' => $this->dosen->id,
        ]);

        // Buang dua kali → 404.
        $this->actingAs($this->dosen)
            ->deleteJson(route('review-drafts.destroy', $this->entrySubmitted))
            ->assertNotFound();
    }

    public function test_materials_queue_shows_draft_chip(): void
    {
        $this->actingAs($this->dosen)->get(route('materials-review.index'))
            ->assertOk()
            ->assertDontSee('badge-draft', false);

        ReviewDraft::create([
            'logbook_entry_id' => $this->entrySubmitted->id,
            'user_id' => $this->dosen->id,
            'feedback_dosen' => 'Chip antrean.',
        ]);

        $html = $this->actingAs($this->dosen)->get(route('materials-review.index'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('badge-draft', $html);
        $this->assertStringContainsString('Draf keputusan tersimpan', $html);
    }
}
