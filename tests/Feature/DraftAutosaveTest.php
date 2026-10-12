<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;

/**
 * Simpan Draft ke server + autosave: cabang JSON endpoint draf, idempotensi
 * (tidak membuat baris baru saat draf yang sama disimpan berulang), dan
 * tautan resume "Lanjutkan draf terakhir".
 */
class DraftAutosaveTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    private function logbookPayload(array $overrides = []): array
    {
        return array_merge([
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Topik autosave server',
            'progres_kendala' => 'Pesan singkat',
            'confirm_new_despite_revision' => '1',
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    public function test_store_returns_json_for_draft_and_creates_once(): void
    {
        $before = LogbookEntry::where('mahasiswa_ta_id', $this->ta->id)
            ->where('jenis', LogbookEntry::JENIS_LOGBOOK)->count();

        $this->actingAs($this->mhs)
            ->postJson(route('logbook.store'), $this->logbookPayload())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('draft', true)
            ->assertJsonStructure(['entry_id', 'saved_at']);

        $after = LogbookEntry::where('mahasiswa_ta_id', $this->ta->id)
            ->where('jenis', LogbookEntry::JENIS_LOGBOOK)->count();
        $this->assertSame($before + 1, $after);
    }

    public function test_repeated_draft_saves_update_same_row_without_duplicate(): void
    {
        $first = $this->actingAs($this->mhs)
            ->postJson(route('logbook.store'), $this->logbookPayload())
            ->assertOk()->json('entry_id');

        $before = LogbookEntry::where('mahasiswa_ta_id', $this->ta->id)->count();

        // Autosave berikutnya membawa draft_id → memperbarui baris yang sama.
        $this->actingAs($this->mhs)
            ->postJson(route('logbook.store'), $this->logbookPayload([
                'draft_id' => $first,
                'topik' => 'Topik diperbarui autosave',
            ]))
            ->assertOk()->assertJsonPath('entry_id', $first);

        $this->assertSame($before, LogbookEntry::where('mahasiswa_ta_id', $this->ta->id)->count());
        $this->assertSame('Topik diperbarui autosave', LogbookEntry::find($first)->topik);
        $this->assertSame(LogbookEntry::STATUS_DRAFT, LogbookEntry::find($first)->status);
    }

    public function test_revisi_draft_returns_json_and_stays_in_progress(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $entryId = $this->actingAs($this->mhs)
            ->postJson(route('logbook.store-revisi'), [
                'parent_entry_id' => $this->entrySubmitted->id,
                'tanggal_pengiriman' => now()->toDateString(),
                'progres_kendala' => 'Draf revisi',
                'lampiran' => UploadedFile::fake()->create('rev.pdf', 100, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('draft', true)
            ->json('entry_id');

        $this->assertSame(LogbookEntry::STATUS_REVISION_IN_PROGRESS, LogbookEntry::find($entryId)->status);
    }

    public function test_update_returns_json_and_keeps_status(): void
    {
        $entry = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 90,
            'topik' => 'Draf untuk edit',
            'tanggal_bimbingan' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_DRAFT,
        ]);

        $this->actingAs($this->mhs)
            ->putJson(route('logbook.update', $entry), [
                'tanggal_bimbingan' => now()->toDateString(),
                'topik' => 'Topik edit autosave',
                'progres_kendala' => 'pesan',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('entry_id', $entry->id);

        $fresh = $entry->fresh();
        $this->assertSame('Topik edit autosave', $fresh->topik);
        $this->assertSame(LogbookEntry::STATUS_DRAFT, $fresh->status);
    }

    public function test_draft_validation_rejects_missing_required_fields(): void
    {
        $this->actingAs($this->mhs)
            ->postJson(route('logbook.store'), [
                'tanggal_bimbingan' => now()->toDateString(),
                'topik' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('topik');
    }

    public function test_non_owner_cannot_autosave(): void
    {
        $entry = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 91,
            'topik' => 'Draf milik mhs',
            'tanggal_bimbingan' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_DRAFT,
        ]);

        $this->actingAs($this->dosen)
            ->putJson(route('logbook.update', $entry), [
                'tanggal_bimbingan' => now()->toDateString(),
                'topik' => 'Coba curi',
            ])
            ->assertForbidden();
    }

    public function test_resume_link_does_not_leak_other_users_draft(): void
    {
        // Draf milik program lain tidak boleh muncul sebagai resume.
        $other = \App\Models\User::create([
            'name' => 'Mhs Isolasi Resume', 'email' => 'isolasi-resume@test.com',
            'password' => bcrypt('password'), 'nim' => 'ISOLASI013',
        ]);
        $other->assignRole('mahasiswa');
        $otherTa = \App\Models\MahasiswaTa::create([
            'user_id' => $other->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Isolasi Resume', 'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);
        $otherDraft = LogbookEntry::create([
            'mahasiswa_ta_id' => $otherTa->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'topik' => 'Draf orang lain',
            'tanggal_bimbingan' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_DRAFT,
        ]);

        $html = $this->actingAs($this->mhs)->get(route('logbook.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('draft_id='.$otherDraft->id, $html);
    }

    public function test_revisi_repeated_draft_saves_update_same_row_without_duplicate(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $first = $this->actingAs($this->mhs)
            ->postJson(route('logbook.store-revisi'), [
                'parent_entry_id' => $this->entrySubmitted->id,
                'tanggal_pengiriman' => now()->toDateString(),
                'progres_kendala' => 'Draf revisi awal',
                'lampiran' => UploadedFile::fake()->create('rev.pdf', 100, 'application/pdf'),
            ])
            ->assertOk()->json('entry_id');

        $before = LogbookEntry::where('mahasiswa_ta_id', $this->ta->id)->count();

        // Autosave berikutnya membawa draft_id → baris sama, tanpa duplikat anak.
        $this->actingAs($this->mhs)
            ->postJson(route('logbook.store-revisi'), [
                'draft_id' => $first,
                'parent_entry_id' => $this->entrySubmitted->id,
                'tanggal_pengiriman' => now()->toDateString(),
                'progres_kendala' => 'Draf revisi diperbarui',
            ])
            ->assertOk()->assertJsonPath('entry_id', $first);

        $this->assertSame($before, LogbookEntry::where('mahasiswa_ta_id', $this->ta->id)->count());
        $this->assertSame('Draf revisi diperbarui', LogbookEntry::find($first)->progres_kendala);
        $this->assertSame(LogbookEntry::STATUS_REVISION_IN_PROGRESS, LogbookEntry::find($first)->status);
        $this->assertSame(1, LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->count());
    }

    public function test_create_wizard_renders_draft_save_control_and_resume_link(): void
    {
        $draft = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => null,
            'topik' => 'Draf tersimpan sebelumnya',
            'tanggal_bimbingan' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_DRAFT,
        ]);

        $html = $this->actingAs($this->mhs)->get(route('logbook.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-draft-save', $html);
        $this->assertStringContainsString('data-draft-status', $html);
        $this->assertStringContainsString('Lanjutkan draf terakhir', $html);
        $this->assertStringContainsString('draft_id='.$draft->id, $html);
        // Tidak ada tombol Simpan Draft kedua di langkah 4.
        $this->assertSame(1, substr_count($html, '<button type="submit" data-draft-save'));

        // Saat sudah melanjutkan draf (?draft_id=), tautan resume tidak tampil.
        $resumed = $this->actingAs($this->mhs)
            ->get(route('logbook.create', ['draft_id' => $draft->id]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('Lanjutkan draf terakhir', $resumed);
    }

    public function test_create_revisi_renders_resume_link_when_orphan_draft_exists(): void
    {
        $draft = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'sesi_ke' => null,
            'tanggal_pengiriman' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ]);

        $html = $this->actingAs($this->mhs)->get(route('logbook.create-revisi'))->assertOk()->getContent();
        $this->assertStringContainsString('Lanjutkan draf terakhir', $html);
        $this->assertStringContainsString('draft_id='.$draft->id, $html);
        // Kartu kosong tidak tampil saat masih ada draf yang bisa dilanjutkan.
        $this->assertStringNotContainsString('Tidak ada yang perlu direvisi', $html);
    }

    public function test_create_revisi_hides_resume_link_when_active_revision_box_shown(): void
    {
        // Induk terkunci (punya anak aktif) → kotak "Lanjutkan draf #id" tampil,
        // tautan resume header disembunyikan agar tidak dobel.
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $child = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'sesi_ke' => null,
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ]);

        $html = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Lanjutkan draf #'.$child->id, $html);
        $this->assertStringNotContainsString('Lanjutkan draf terakhir', $html);
    }

    public function test_create_revisi_hides_resume_link_when_resuming_with_draft_id(): void
    {
        $draft = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'sesi_ke' => null,
            'tanggal_pengiriman' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ]);

        $html = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['draft_id' => $draft->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Melanjutkan draf #'.$draft->id, $html);
        $this->assertStringNotContainsString('Lanjutkan draf terakhir', $html);
    }

    public function test_orphan_draft_can_be_linked_to_parent_on_submit(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $draft = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'sesi_ke' => null,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran_path' => 'lampiran/revisi.pdf',
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ]);

        $this->actingAs($this->mhs)->postJson(route('logbook.store-revisi'), [
            'draft_id' => $draft->id,
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Jawaban draf yatim.',
        ])->assertOk()->assertJsonPath('entry_id', $draft->id);

        $this->assertSame($this->entrySubmitted->id, $draft->fresh()->parent_entry_id);
        $this->assertSame(LogbookEntry::STATUS_REVISION_IN_PROGRESS, $draft->fresh()->status);
    }
}
