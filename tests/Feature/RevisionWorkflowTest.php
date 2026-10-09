<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\PdfComment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;

class RevisionWorkflowTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    public function test_create_workspaces_render_with_one_file_input_each(): void
    {
        $logbook = $this->actingAs($this->mhs)->get(route('logbook.create'));
        $logbook->assertOk()
            ->assertSee('Form Entri Logbook')
            ->assertSee('Ringkasan Entri')
            ->assertSee('data-autosave-panel="lb-create"', false);

        $revisi = $this->actingAs($this->mhs)->get(route('revisi.create'));
        $revisi->assertOk()
            ->assertSee('Ringkasan Revisi')
            ->assertSee('data-autosave-panel="lb-revisi"', false)
            ->assertSee('data-step-status="4"', false);

        foreach ([$logbook, $revisi] as $response) {
            $this->assertSame(1, preg_match_all('/<input\s+type="file"(?=\s|>)/', $response->getContent()));
        }
    }

    public function test_revision_is_linked_to_parent_and_copies_review_assignment(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_REVISI,
            'feedback_dosen' => 'Perbaiki metodologi dan jelaskan hasil pengujian dengan lebih rinci.',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Metodologi dan hasil pengujian sudah diperbaiki.',
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Perbaiki metodologi.',
                    'perbaikan' => 'Metodologi sudah diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('logbook_entries', [
            'parent_entry_id' => $this->entrySubmitted->id,
            'revision_round' => 1,
            'dosen_id' => $this->dosen->id,
            'topik' => $this->entrySubmitted->topik,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ]);
    }

    public function test_only_one_active_revision_is_allowed_per_parent(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $payload = [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Perbaikan sudah dikerjakan dengan ringkasan yang jelas.',
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Perbaiki metodologi.',
                    'perbaikan' => 'Metodologi sudah diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), $payload)->assertRedirect();
        // Submit ulang sebelum dikirim memakai ulang draf yang sama
        // (tetap satu revisi aktif per induk, tanpa duplikat).
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), $payload)->assertRedirect();
        $this->assertEquals(1, LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->count());
    }

    public function test_new_manual_card_defaults_to_draft_status(): void
    {
        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi'))
            ->assertOk()
            ->assertSee('<option value="">— Pilih —</option>', false)
            ->assertDontSee('<option value="Sudah" selected', false)
            ->assertDontSee('<option value="Draf"', false);
    }

    public function test_submit_without_reupload_keeps_draft_file(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $draftResponse = $this->actingAs($this->mhs)->post(route('logbook.store-revisi-draft'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);
        $draftResponse->assertStatus(201);
        $draftId = $draftResponse->json('entry_id');
        $draftFile = LogbookEntry::findOrFail($draftId)->lampiran_path;
        $this->assertNotNull($draftFile);

        // Kirim tanpa upload ulang (simulasi refresh wizard): file draf dipakai.
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Perbaiki metodologi.',
                    'perbaikan' => 'Metodologi sudah diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'submit' => '1',
        ])->assertRedirect(route('logbook.show', $draftId));

        $this->assertDatabaseHas('logbook_entries', [
            'id' => $draftId,
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'lampiran_path' => $draftFile,
        ]);
        $this->assertNotEmpty(LogbookEntry::findOrFail($draftId)->riwayat_perbaikan);
    }

    public function test_edit_save_button_is_inside_update_form(): void
    {
        // Regresi: form hapus-lampiran yang bersarang menutup form utama lebih
        // awal sehingga tombol Simpan jatuh di luar form (klik mati di browser).
        // Draf logbook (editor lama) dengan lampiran.
        // Isolasi ke mahasiswa+program baru: gerbang revisi-pending tidak ikut.
        $freshMhs = \App\Models\User::create([
            'name' => 'Mhs Isolasi Edit', 'email' => 'isolasi-edit@test.com',
            'password' => bcrypt('password'), 'nim' => 'ISOLASI002',
        ]);
        $freshMhs->assignRole('mahasiswa');
        \App\Models\MahasiswaTa::create([
            'user_id' => $freshMhs->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Isolasi Edit',
            'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);
        $this->actingAs($freshMhs)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Topik draf berfile',
            'progres_kendala' => 'Ringkasan awal.',
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $draft = LogbookEntry::where('jenis', LogbookEntry::JENIS_LOGBOOK)
            ->where('status', LogbookEntry::STATUS_DRAFT)->latest('id')->firstOrFail();
        $this->assertNotNull($draft->lampiran_path);

        $html = $this->actingAs($freshMhs)->get(route('logbook.edit', $draft))->assertOk()->getContent();

        $actionPos = strpos($html, 'action="'.route('logbook.update', $draft).'"');
        $this->assertNotFalse($actionPos);
        $formStart = strrpos(substr($html, 0, $actionPos), '<form');
        $openEnd = strpos($html, '>', $formStart);
        $simpanPos = strpos($html, '>Simpan</button>', $openEnd);
        $this->assertNotFalse($simpanPos, 'Tombol Simpan tidak ditemukan setelah form update.');
        $between = substr($html, $openEnd, $simpanPos - $openEnd);
        $this->assertStringNotContainsString('<form', $between, 'Ada <form> bersarang sebelum tombol Simpan.');
        $this->assertStringNotContainsString('</form', $between, 'Form utama tertutup sebelum tombol Simpan.');

        // Form hapus lampiran tetap ada namun terpisah dari form utama.
        $this->assertStringContainsString('id="form-hapus-lampiran"', $html);
        $this->assertStringContainsString('form="form-hapus-lampiran"', $html);
    }

    public function test_edit_flags_incomplete_rows_and_allows_completion_then_submit(): void
    {
        // Skenario entri 32: baris ada tapi tak lengkap. Draf revisi kini
        // dilengkapi lewat wizard (edit redirect), simpan via PUT tetap jalan.
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();
        $draft->update(['riwayat_perbaikan' => [
            ['halaman' => 'Hal. 1', 'komentar_dosen' => 'pas mantap', 'perbaikan' => null, 'status' => 'Draf'],
        ]]);

        // Wizard menampilkan tabel draf + penanda belum lengkap.
        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['draft_id' => $draft->id]))
            ->assertOk()
            ->assertSee('Melanjutkan draf #'.$draft->id, false);

        // Lengkapi lalu simpan → kirim ke dosen berhasil.
        $this->actingAs($this->mhs)->put(route('logbook.update', $draft), [
            'tanggal_pengiriman' => now()->toDateString(),
            'riwayat_perbaikan' => [
                ['halaman' => 'Hal. 1', 'komentar_dosen' => 'pas mantap', 'perbaikan' => 'Sudah ditambahkan.', 'status' => 'Sudah'],
            ],
        ])->assertRedirect(route('logbook.show', $draft));
        $this->assertSame(1, $draft->fresh()->completePerbaikanRows()->count());

        $this->actingAs($this->mhs)->post(route('logbook.submit', $draft))->assertRedirect();
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $draft->fresh()->status);
    }

    public function test_deleting_active_child_restores_parent_to_revisi(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $child = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();
        $this->assertSame(LogbookEntry::STATUS_REVISION_IN_PROGRESS, $this->entrySubmitted->fresh()->status);

        $this->actingAs($this->mhs)->delete(route('logbook.destroy', $child))
            ->assertRedirect(route('logbook.index'));
        $this->assertSame(LogbookEntry::STATUS_REVISI, $this->entrySubmitted->fresh()->status);

        // Induk pulih: tombol buat revisi muncul lagi.
        $this->actingAs($this->mhs)->get(route('logbook.show', $this->entrySubmitted->id))
            ->assertOk()
            ->assertSee('Buat Revisi dari Umpan Balik Ini', false);
    }

    public function test_bulk_delete_restores_stranded_parent(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $child = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        $this->actingAs($this->mhs)->post(route('logbook.bulk-destroy'), ['ids' => [$child->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(LogbookEntry::STATUS_REVISI, $this->entrySubmitted->fresh()->status);
    }

    public function test_show_lists_resume_cta_for_editable_child(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $child = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        $this->actingAs($this->mhs)->get(route('logbook.show', $this->entrySubmitted->id))
            ->assertOk()
            ->assertSee('Lanjutkan draf', false)
            ->assertSee(route('logbook.edit', $child), false);
    }

    public function test_repair_service_fixes_stranded_row(): void
    {
        // Simulasi warisan: anak dihapus langsung tanpa pemulihan induk.
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $childId = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail()->id;
        LogbookEntry::whereKey($childId)->delete();
        $this->assertSame(LogbookEntry::STATUS_REVISION_IN_PROGRESS, $this->entrySubmitted->fresh()->status);

        $this->assertSame(1, \App\Services\RestoreStrandedParents::run());
        $this->assertSame(LogbookEntry::STATUS_REVISI, $this->entrySubmitted->fresh()->status);
        // Idempoten: jalan kedua tidak mengubah apa-apa.
        $this->assertSame(0, \App\Services\RestoreStrandedParents::run());
    }

    public function test_submit_with_comment_status_marks_comments_addressed(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $comment = $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id,
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 2,
            'comment' => 'Perjelas rumusan masalah.',
            'resolution_status' => PdfComment::STATUS_OPEN,
            'is_resolved' => false,
        ]);

        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'addressed_comment_status' => [$comment->id => 'Sebagian'],
            'tanggal_pengiriman' => now()->toDateString(),
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 1',
                    'komentar_dosen' => 'Perjelas rumusan masalah.',
                    'perbaikan' => 'Rumusan masalah diperjelas.',
                    'status' => 'Sebagian',
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('revisi.pdf', 100, 'application/pdf'),
            'submit' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('pdf_comments', [
            'id' => $comment->id,
            'resolution_status' => PdfComment::STATUS_ADDRESSED,
        ]);
    }

    public function test_comment_status_rejects_unknown_value(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'addressed_comment_status' => [999 => 'Ngawur'],
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('addressed_comment_status.999');
    }

    public function test_create_revisi_renders_comment_status_radios_and_three_status_options(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $comment = $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id,
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 3,
            'comment' => 'Tambahkan sitasi primer.',
            'resolution_status' => PdfComment::STATUS_OPEN,
            'is_resolved' => false,
        ]);

        $html = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()
            ->getContent();

        foreach (['Sudah', 'Sebagian', 'Belum'] as $s) {
            $this->assertStringContainsString('name="addressed_comment_status['.$comment->id.']" value="'.$s.'"', $html);
        }
        $this->assertStringContainsString('data-komentar-select', $html);
        $this->assertStringContainsString('Tambahkan sitasi primer', $html);
        $this->assertStringNotContainsString('<option value="Sudah" selected', $html);
        // Urutan kartu: komentar dulu, lalu halaman+perbaikan sebaris.
        $this->assertTrue(strpos($html, 'riwayat-komentar-0') < strpos($html, 'riwayat-halaman-0'));
        $this->assertTrue(strpos($html, 'riwayat-halaman-0') < strpos($html, 'riwayat-perbaikan-0'));
    }

    public function test_edit_redirects_revision_draft_to_wizard_but_keeps_logbook_edit(): void
    {
        // Draf revisi → wizard.
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        $this->actingAs($this->mhs)->get(route('logbook.edit', $draft))
            ->assertRedirect(route('logbook.create-revisi', ['draft_id' => $draft->id, 'program' => $draft->fresh()->mahasiswaTa->jenis]));

        // Draf logbook biasa → halaman edit lama.
        $this->actingAs($this->mhs)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Topik draf logbook',
            'progres_kendala' => 'Ringkasan awal.',
            'program' => $draft->fresh()->mahasiswaTa->jenis,
        ])->assertRedirect();
        $logbookDraft = LogbookEntry::where('mahasiswa_ta_id', $this->entrySubmitted->mahasiswa_ta_id)
            ->where('jenis', LogbookEntry::JENIS_LOGBOOK)->where('status', LogbookEntry::STATUS_DRAFT)
            ->latest('id')->firstOrFail();

        $this->actingAs($this->mhs)->get(route('logbook.edit', $logbookDraft))->assertOk();
    }

    public function test_wizard_loads_draft_table_and_file(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Pesan draf.',
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();
        $draft->update(['riwayat_perbaikan' => [
            ['halaman' => 'Hal. 2', 'komentar_dosen' => 'Perbaiki kutipan.', 'perbaikan' => null, 'status' => 'Belum'],
        ]]);

        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['draft_id' => $draft->id, 'program' => $draft->fresh()->mahasiswaTa->jenis]))
            ->assertOk()
            ->assertSee('Melanjutkan draf #'.$draft->id, false)
            ->assertSee('Perbaiki kutipan.', false)
            ->assertSee('name="draft_id" value="'.$draft->id.'"', false);
    }

    public function test_submit_with_draft_id_updates_mandiri_draft_without_new_row_or_file(): void
    {
        // Draf mandiri (tanpa induk) + file.
        $draftResponse = $this->actingAs($this->mhs)->post(route('logbook.store-revisi-draft'), [
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);
        $draftResponse->assertStatus(201);
        $draftId = $draftResponse->json('entry_id');
        $draftFile = LogbookEntry::findOrFail($draftId)->lampiran_path;
        $taId = LogbookEntry::findOrFail($draftId)->mahasiswa_ta_id;
        $before = LogbookEntry::where('mahasiswa_ta_id', $taId)->count();

        // Kirim tanpa induk tetap ditolak: revisi wajib menempel ke satu entri.
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'draft_id' => $draftId,
            'tanggal_pengiriman' => now()->toDateString(),
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 1',
                    'komentar_dosen' => 'Perbaiki latar.',
                    'perbaikan' => 'Latar diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'submit' => '1',
        ])->assertSessionHasErrors('parent_entry_id');

        // Tautkan ke induk yang meminta revisi, kirim tanpa upload ulang:
        // draf dipakai ulang (tanpa baris/file baru).
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'draft_id' => $draftId,
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 1',
                    'komentar_dosen' => 'Perbaiki latar.',
                    'perbaikan' => 'Latar diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'submit' => '1',
        ])->assertRedirect(route('logbook.show', $draftId));

        $this->assertSame($before, LogbookEntry::where('mahasiswa_ta_id', $taId)->count());
        $this->assertDatabaseHas('logbook_entries', [
            'id' => $draftId,
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'lampiran_path' => $draftFile,
        ]);
    }

    public function test_unknown_draft_id_is_rejected(): void
    {
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'draft_id' => 999999,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('draft_id');
    }

    public function test_comment_options_are_scoped_to_selected_parent(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $commentA = $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 1, 'comment' => 'Komentar induk A unik.',
            'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
        ]);
        $other = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->entrySubmitted->mahasiswa_ta_id, 'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => (int) LogbookEntry::where('mahasiswa_ta_id', $this->entrySubmitted->mahasiswa_ta_id)->max('sesi_ke') + 1,
            'topik' => 'Topik lain',
            'tanggal_bimbingan' => now(), 'status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now(),
        ]);
        $other->comments()->create([
            'user_id' => $this->dosen->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 2, 'comment' => 'Komentar induk B unik.',
            'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
        ]);

        // Mandiri (tanpa induk): tidak ada opsi komentar id.
        $mandiri = $this->actingAs($this->mhs)->get(route('logbook.create-revisi'))->assertOk()->getContent();
        $doc = new \DOMDocument;
        @$doc->loadHTML($mandiri);
        $xpath = new \DOMXPath($doc);
        $this->assertSame(0, $xpath->query('//option[@data-comment-id]')->length);

        // Induk A terpilih: hanya komentar A di select kartu.
        $scoped = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()->getContent();
        $doc2 = new \DOMDocument;
        @$doc2->loadHTML($scoped);
        $xpath2 = new \DOMXPath($doc2);
        $options = $xpath2->query('//select[@data-komentar-select]//option[@data-comment-id]');
        $this->assertGreaterThan(0, $options->length);
        foreach ($options as $opt) {
            $this->assertSame((string) $commentA->id, $opt->getAttribute('data-comment-id'));
        }
    }

    public function test_open_draft_is_excluded_from_parent_dropdown(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['draft_id' => $draft->id]))
            ->assertOk()
            ->assertDontSee('<option value="'.$draft->id.'" data-dosen-id', false);
    }

    public function test_step_two_offers_skip_annotation_button(): void
    {
        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi'))
            ->assertOk()
            ->assertSee('id="lanjut-tanpa-anotasi"', false)
            ->assertSee('Lanjut tanpa anotasi', false);
    }

    public function test_show_places_feedback_card_above_compact_revision_notes(): void
    {
        $this->entryRevisi->update([
            'dosen_id' => $this->dosen->id,
            'feedback_dosen' => 'Perbaiki Bab 3 dengan tajam dan rinci.',
            'reviewed_at' => now(),
            'riwayat_perbaikan' => [
                ['halaman' => 'Bab 3', 'komentar_dosen' => 'Perbaiki.', 'perbaikan' => 'Diperbaiki.', 'status' => 'Sudah'],
            ],
        ]);
        $manual = $this->entryRevisi->comments()->create([
            'user_id' => $this->dosen->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 2, 'comment' => 'Catatan manual dosen.',
            'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
        ]);
        $manual->replies()->create(['user_id' => $this->mhs->id, 'body' => 'Siap, segera diperbaiki.']);
        $this->entryRevisi->comments()->create([
            'user_id' => $this->dosen->id, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 9, 'comment' => 'Komentar anotasi berhalaman.',
            'payload' => ['type' => 'highlight', 'page' => 9],
            'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
        ]);

        $html = $this->actingAs($this->mhs)->get(route('logbook.show', $this->entryRevisi))
            ->assertOk()->getContent();

        $fb = strpos($html, 'Umpan Balik Dosen');
        $ct = strpos($html, 'Catatan Perbaikan');
        $this->assertNotFalse($fb);
        $this->assertNotFalse($ct);
        $this->assertTrue($fb < $ct, 'Kartu umpan balik harus di atas catatan perbaikan.');
        $this->assertStringContainsString('bg-status-pending/10', $html);
        $this->assertStringContainsString('Perbaiki Bab 3 dengan tajam dan rinci.', $html);
        // Daftar manual: tanpa Hal., plus balasan; anotasi berhalaman tidak ikut.
        $this->assertStringContainsString('(Sesi ini · dosen) Catatan manual dosen.', $html);
        $this->assertStringContainsString('→ dibalas mahasiswa: Siap, segera diperbaiki.', $html);
        $this->assertStringNotContainsString('Komentar anotasi berhalaman.</li>', $html);
    }

    public function test_pull_is_idempotent_and_includes_member_annotations(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        $mk = function ($userId, $text) use ($draft) {
            return $draft->comments()->create([
                'user_id' => $userId, 'file_type' => PdfComment::FILE_TYPE_DRAFT,
                'page_number' => 4, 'comment' => $text,
                'payload' => ['type' => 'highlight', 'page' => 4],
                'resolution_status' => PdfComment::STATUS_OPEN, 'is_resolved' => false,
            ]);
        };
        $mk($this->mhs->id, 'Anotasi pemilik.');
        $member = \App\Models\User::create(['name' => 'Anggota Kelompok', 'email' => 'anggota-kelompok@test.com',
            'password' => bcrypt('password'), 'nim' => 'A002']);
        $member->assignRole('mahasiswa');
        $draft->mahasiswaTa->members()->attach($member->id);
        $mk($member->id, 'Anotasi anggota.');

        // Pull pertama menarik milik pemilik + anggota; pull kedua tidak duplikat.
        $first = $this->actingAs($this->mhs)->postJson(route('logbook.annotations.pull', $draft))->assertOk();
        $this->assertSame(2, $first->json('pulled'));
        $second = $this->actingAs($this->mhs)->postJson(route('logbook.annotations.pull', $draft))->assertOk();
        $this->assertSame(0, $second->json('pulled'));
        $this->assertCount(2, array_filter($draft->fresh()->riwayat_perbaikan ?? []));
    }

    public function test_new_logbook_is_rejected_while_revision_pending_without_confirmation(): void
    {
        // Dosen meminta revisi pada entri yang sudah dikirim.
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        // Tanpa centang konfirmasi: sesi logbook baru ditolak agar jawaban
        // revisi tidak dikirim diam-diam lewat thread baru.
        $response = $this->actingAs($this->mhs)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Sesi baru penghindar revisi',
            'progres_kendala' => 'Isi revisi yang seharusnya lewat jalur revisi.',
        ]);
        $response->assertSessionHasErrors('confirm_new_despite_revision');

        // Form create menampilkan banner + tombol Lanjutkan/Buat Revisi.
        $this->actingAs($this->mhs)->get(route('logbook.create'))
            ->assertOk()
            ->assertSee('Masih ada revisi yang belum selesai', false)
            ->assertSee('confirm_new_despite_revision', false);

        // Dashboard mengangkat CTA revisi jadi primer.
        $this->actingAs($this->mhs)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Revisi perlu ditanggapi dulu', false);

        // Daftar logbook menampilkan banner yang sama.
        $this->actingAs($this->mhs)->get(route('logbook.index'))
            ->assertOk()
            ->assertSee('Revisi perlu ditanggapi dulu', false);
    }

    public function test_new_logbook_passes_with_explicit_new_session_confirmation(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        // Dengan pernyataan eksplisit "sesi baru": sesi bimbingan yang sah
        // tetap bisa dibuat (gerbang lunak, bukan blokir keras).
        $this->actingAs($this->mhs)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Bimbingan bab baru yang sah',
            'progres_kendala' => 'Bimbingan baru, bukan jawaban revisi.',
            'confirm_new_despite_revision' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('logbook_entries', ['topik' => 'Bimbingan bab baru yang sah']);
    }

    public function test_new_logbook_passes_when_no_revision_pending(): void
    {
        // Isolasi: mahasiswa + program baru tanpa entri apa pun.
        $freshMhs = \App\Models\User::create([
            'name' => 'Mhs Isolasi Gate', 'email' => 'isolasi-gate@test.com',
            'password' => bcrypt('password'), 'nim' => 'ISOLASI001',
        ]);
        $freshMhs->assignRole('mahasiswa');
        $fresh = \App\Models\MahasiswaTa::create([
            'user_id' => $freshMhs->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Isolasi Gate',
            'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);

        // Tanpa revisi pending: tidak ada syarat konfirmasi tambahan.
        $response = $this->actingAs($freshMhs)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Sesi normal',
            'progres_kendala' => 'Progres normal.',
        ]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('logbook_entries', [
            'mahasiswa_ta_id' => $fresh->id,
            'topik' => 'Sesi normal',
        ]);
    }

    public function test_submit_revision_requires_parent_but_draft_may_be_orphan(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $row = [[
            'halaman' => 'Bab 3',
            'komentar_dosen' => 'Perbaiki metodologi.',
            'perbaikan' => 'Metodologi sudah diperbaiki.',
            'status' => 'Sudah',
        ]];

        // Draf yatim (tanpa induk, tanpa submit): tetap lolos agar alur
        // anotasi-dulu tidak rusak.
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Draf pribadi dulu.',
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $orphan = LogbookEntry::where('jenis', LogbookEntry::JENIS_REVISI)
            ->whereNull('parent_entry_id')->latest('id')->firstOrFail();

        // Kirim draf yatim tanpa induk: ditolak dengan pesan pemilihan induk.
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'draft_id' => $orphan->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Perbaikan sudah dikerjakan.',
            'riwayat_perbaikan' => $row,
            'submit' => '1',
        ])->assertSessionHasErrors('parent_entry_id');

        // Tautkan ke induk lalu kirim: lolos, ronde terhitung, induk terkunci.
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'draft_id' => $orphan->id,
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Perbaikan sudah dikerjakan.',
            'riwayat_perbaikan' => $row,
            'submit' => '1',
        ])->assertRedirect();
        $orphan->refresh();
        $this->assertSame($this->entrySubmitted->id, $orphan->parent_entry_id);
        $this->assertSame(1, $orphan->revision_round);
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $orphan->status);

        // Wizard mode lanjutkan draf yatim menampilkan banner penautan.
        $freshOrphan = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran_path' => 'lampiran/orphan.pdf',
        ]);
        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['draft_id' => $freshOrphan->id]))
            ->assertOk()
            ->assertSee('Draf ini belum menjawab entri mana pun', false);
    }

    public function test_parent_dropdown_shows_topik_and_status(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi'))
            ->assertOk()
            ->assertSee($this->entrySubmitted->topik, false)
            ->assertSee('Revisi Diminta', false);
    }

    public function test_wizard_draft_then_submit_reuses_draft_instead_of_conflict(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        // Langkah 2 wizard: simpan draf anotasi-dulu (tanpa tabel).
        $draftResponse = $this->actingAs($this->mhs)->post(route('logbook.store-revisi-draft'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);
        $draftResponse->assertStatus(201);
        $draftId = $draftResponse->json('entry_id');
        $this->assertNotNull($draftId);

        // Langkah 4: kirim dengan induk yang sama → draf dipakai ulang, bukan error.
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Perbaikan sudah dikerjakan dengan ringkasan yang jelas.',
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Perbaiki metodologi.',
                    'perbaikan' => 'Metodologi sudah diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('revisi.pdf', 100, 'application/pdf'),
            'submit' => '1',
        ])->assertRedirect(route('logbook.show', $draftId));

        $this->assertEquals(1, LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->count());
        $this->assertDatabaseHas('logbook_entries', [
            'id' => $draftId,
            'status' => LogbookEntry::STATUS_SUBMITTED,
        ]);

        // Kirim ulang setelah submitted → ditolak dengan pesan actionable.
        $retry = $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Perbaiki metodologi.',
                    'perbaikan' => 'Metodologi sudah diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('revisi2.pdf', 100, 'application/pdf'),
            'submit' => '1',
        ]);
        $retry->assertSessionHasErrors('parent_entry_id');
        $this->assertStringContainsString('draf #'.$draftId, session('errors')->get('parent_entry_id')[0]);
    }

    public function test_active_revision_conflict_names_draft_and_offers_resume_link(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $payload = [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Perbaikan sudah dikerjakan dengan ringkasan yang jelas.',
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Perbaiki metodologi.',
                    'perbaikan' => 'Metodologi sudah diperbaiki.',
                    'status' => 'Sudah',
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), $payload)->assertRedirect();
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        // Induk terkunci: halaman create-revisi langsung menawarkan "Lanjutkan draf".
        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()
            ->assertSee('Lanjutkan draf #'.$draft->id, false);

        // Setelah draf dikirim, submit baru ditolak dengan pesan menyebut draf.
        $payload['submit'] = '1';
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), $payload)->assertRedirect();
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), $payload)
            ->assertSessionHasErrors('parent_entry_id');
        $this->assertStringContainsString(
            'draf #'.$draft->id,
            session('errors')->get('parent_entry_id')[0]
        );
        $this->assertEquals($draft->id, session('active_revision')['id']);

        // Kotak aksi kini menjelaskan draf menunggu review (tidak bisa dilanjutkan).
        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()
            ->assertSee('menunggu review', false);
    }

    public function test_revision_draft_can_be_saved_without_table_then_pulled_from_annotations(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        // 1) Draf tersimpan tanpa tabel perbaikan (alur anotasi-dulu).
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Draf tanpa tabel, mau anotasi dulu.',
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();
        $this->assertNull($draft->riwayat_perbaikan);

        // 2) Mahasiswa membuat anotasi perbaikan di PDF drafnya.
        $this->actingAs($this->mhs)->postJson(route('logbook.pdf.store-comment', $draft), [
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'comment' => 'Bagian metode sudah dilengkapi.',
            'page_number' => 3,
            'pos_x' => 0.1,
            'pos_y' => 0.1,
            'x2' => 0.4,
            'y2' => 0.2,
        ])->assertCreated();

        // 3) Tarik anotasi menjadi tabel tanpa isi manual.
        $this->actingAs($this->mhs)->postJson(route('logbook.annotations.pull', $draft))
            ->assertOk()->assertJsonPath('pulled', 1);

        $rows = $draft->fresh()->riwayat_perbaikan;
        $this->assertCount(1, $rows);
        $this->assertSame('Hal. 3', $rows[0]['halaman']);
        $this->assertSame('Bagian metode sudah dilengkapi.', $rows[0]['perbaikan']);

        // 4) Idempoten: tarik ulang tidak menggandakan baris.
        $this->actingAs($this->mhs)->postJson(route('logbook.annotations.pull', $draft))
            ->assertOk()->assertJsonPath('pulled', 0);
        $this->assertCount(1, $draft->fresh()->riwayat_perbaikan);
    }

    public function test_revision_draft_redirects_to_edit_for_annotation_flow(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $response = $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);

        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('logbook.edit', $draft));
        $this->assertNull($draft->riwayat_perbaikan);
    }

    public function test_viewer_back_button_returns_to_wizard_when_from_create_revisi(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        // Dari wizard create: kembali ke wizard langkah 3 + autopull (konteks tidak hilang).
        // @json meng-escape slash (\/); cocokkan bentuk escape-nya.
        $wizardBase = str_replace('/', '\/', route('logbook.create-revisi', [], false));
        $this->actingAs($this->mhs)->get(route('logbook.pdf-viewer', ['logbook' => $draft, 'from' => 'create-revisi']))
            ->assertOk()
            ->assertSee($wizardBase, false)
            ->assertSee('step', false)
            ->assertSee('autopull', false)
            ->assertSee('Kembali \u0026 Lengkapi Form', false);

        // Tanpa param: tetap ke detail entri seperti sebelumnya.
        $showUrl = str_replace('/', '\/', route('logbook.show', $draft, false));
        $this->actingAs($this->mhs)->get(route('logbook.pdf-viewer', $draft))
            ->assertOk()
            ->assertSee($showUrl, false);
    }

    public function test_create_revisi_supports_step_and_autopull_deep_link(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $html = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id, 'step' => 3, 'autopull' => 1]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('var initialStep = 3', $html);
        $this->assertStringContainsString('var autoPullOnLoad = true', $html);
    }

    public function test_draft_endpoint_saves_file_and_returns_viewer_urls(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $response = $this->actingAs($this->mhs)->postJson(route('logbook.store-revisi-draft'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);
        $entryId = $response->json('entry_id');
        $this->assertNotNull($entryId);
        $this->assertStringContainsString('/pdf/viewer', $response->json('viewer_url'));
        $this->assertStringContainsString('/annotations/pull', $response->json('pull_url'));
        $this->assertDatabaseHas('logbook_entries', ['id' => $entryId, 'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS]);
        $this->assertNotNull(LogbookEntry::find($entryId)->lampiran_path);

        // Wizard create memakai tombol Lanjut-ke-anotasi (bukan wizard-next biasa).
        $html = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('id="lanjut-anotasi"', $html);
        $this->assertStringContainsString('Lanjut ke anotasi PDF', $html);
    }

    public function test_draft_endpoint_requires_file(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $this->actingAs($this->mhs)->postJson(route('logbook.store-revisi-draft'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
        ])->assertUnprocessable();
    }

    public function test_draft_endpoint_reuses_recent_draft_for_double_tab(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $payload = [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ];

        $first = $this->actingAs($this->mhs)->postJson(route('logbook.store-revisi-draft'), $payload)
            ->assertCreated()->assertJsonPath('ok', true);
        // Tab kedua menekan Lanjut lagi → pakai ulang draf (200 + reused), bukan entri baru.
        $second = $this->actingAs($this->mhs)->postJson(route('logbook.store-revisi-draft'), $payload)
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('reused', true);

        $this->assertSame($first->json('entry_id'), $second->json('entry_id'));
        $this->assertSame(1, LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->count());
    }

    public function test_viewer_flags_non_pdf_for_download_notice(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();
        $draft->update(['lampiran_original_name' => 'revisi.docx']);

        $this->actingAs($this->mhs)->get(route('logbook.pdf-viewer', $draft))
            ->assertOk()->assertSee('isDraftPdf: false', false);
    }

    public function test_audit_fixes_single_complete_definition_and_honest_wizard(): void
    {
        // FIX 2: helper tunggal - kartu kosong berstatus Sudah TIDAK lengkap.
        $this->assertFalse(LogbookEntry::isPerbaikanRowComplete([
            'halaman' => 'Hal. 1', 'komentar_dosen' => 'X', 'perbaikan' => '', 'status' => LogbookEntry::PERBAIKAN_SUDAH,
        ]));
        $this->assertFalse(LogbookEntry::isPerbaikanRowComplete([
            'halaman' => 'Hal. 1', 'komentar_dosen' => 'X', 'perbaikan' => 'Y', 'status' => LogbookEntry::PERBAIKAN_DRAF,
        ]));
        $this->assertTrue(LogbookEntry::isPerbaikanRowComplete([
            'halaman' => 'Hal. 1', 'komentar_dosen' => 'X', 'perbaikan' => 'Y', 'status' => LogbookEntry::PERBAIKAN_SUDAH,
        ]));

        // FIX 1: wizard create tidak menjanjikan tombol aktif tanpa draf.
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $html = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-anotasi-open', $html);
        $this->assertStringContainsString('Draf tersimpan otomatis saat klik Lanjut', $html);

        // FIX 5: gate kirim nonaktif sejak muat (bukan menunggu ketikan).
        $this->assertStringContainsString('id="btn-kirim"', $html);
        $this->assertStringContainsString('id="kirim-hint"', $html);

        // Tombol Lanjut langkah 1 terkabel + ada peringatan bila belum memilih.
        $this->assertStringContainsString('class="wizard-next ', $html);
        $this->assertStringContainsString('id="step1-hint"', $html);
        $this->assertStringContainsString('tryAdvance', $html);
    }

    public function test_review_round3_field_order_copy_and_readiness(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $html = $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()->getContent();

        // F1+F2: urutan Komentar -> Halaman + Perbaikan sebaris -> Status (status terakhir).
        $posHalaman = strpos($html, 'name="riwayat_perbaikan[0][halaman]"');
        $posKomentar = strpos($html, 'name="riwayat_perbaikan[0][komentar_dosen]"');
        $posPerbaikan = strpos($html, 'name="riwayat_perbaikan[0][perbaikan]"');
        $posStatus = strpos($html, 'name="riwayat_perbaikan[0][status]"');
        $this->assertNotFalse($posHalaman);
        $this->assertTrue($posKomentar < $posHalaman && $posHalaman < $posPerbaikan && $posPerbaikan < $posStatus);

        // F3: panel review read-only + tombol Ubah kembali ke langkah 2.
        $this->assertStringContainsString('Pesan untuk Dosen', $html);
        $this->assertStringContainsString('data-ubah-pesan', $html);

        // R3+R4: heading hasil + anchor kartu.
        $this->assertStringContainsString('Hasil Tandaan', $html);
        $this->assertStringContainsString('id="kartu-perbaikan"', $html);

        // R5: label tanggal jujur + R12/R13 kesiapan + R8 live-region + F5 tombol bawah.
        $this->assertStringContainsString('Tanggal revisi', $html);
        $this->assertStringContainsString('perlu dilengkapi', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-isi-otomatis-bawah', $html);
        $this->assertStringContainsString('badge-draft', $html);

        // R11: placeholder kontekstual (bukan generik).
        $this->assertStringContainsString('mis. Hal. 5', $html);
        $this->assertStringContainsString('mis. Jelaskan dasar pemilihan metode', $html);

        // F4: hapus minimal 1 kartu + konfirmasi (create) — dijaga di JS.
        $this->assertStringContainsString('Minimal 1 kartu perbaikan harus ada.', $html);
        $this->assertStringContainsString('Hapus kartu ini', $html);

        // R1: kunci draf ganda + R9: guard nomor langkah.
        $this->assertStringContainsString('savingDraft', $html);
        $this->assertStringContainsString('progress_activity', $html);
    }

    public function test_pull_reports_skipped_empty_annotations(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        // Anotasi kosong: ditandai + dilaporkan, tidak mengganda.
        $empty = $draft->comments()->create([
            'user_id' => $this->mhs->id,
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 1,
            'comment' => '',
            'resolution_status' => PdfComment::STATUS_OPEN,
        ]);
        $this->actingAs($this->mhs)->postJson(route('logbook.annotations.pull', $draft))
            ->assertOk()->assertJsonPath('pulled', 0)->assertJsonPath('skipped_empty', 1);
        $this->actingAs($this->mhs)->postJson(route('logbook.annotations.pull', $draft))
            ->assertOk()->assertJsonPath('pulled', 0)->assertJsonPath('skipped_empty', 0);
        $this->assertTrue((bool) $empty->fresh()->payload['pulled_to_riwayat']);
    }

    public function test_pulled_rows_use_draft_status_and_do_not_count_as_complete(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);
        $draft = LogbookEntry::where('parent_entry_id', $this->entrySubmitted->id)->latest('id')->firstOrFail();

        $this->actingAs($this->mhs)->postJson(route('logbook.pdf.store-comment', $draft), [
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'comment' => 'Perbaiki bagian ini.',
            'page_number' => 2,
            'pos_x' => 0.1, 'pos_y' => 0.1, 'x2' => 0.3, 'y2' => 0.2,
        ])->assertCreated();

        $this->actingAs($this->mhs)->postJson(route('logbook.annotations.pull', $draft))
            ->assertOk()->assertJsonPath('pulled', 1);

        $rows = $draft->fresh()->riwayat_perbaikan;
        $this->assertSame(LogbookEntry::PERBAIKAN_DRAF, $rows[0]['status']);

        // Status Draf belum dihitung lengkap -> submit tetap ditolak.
        $this->actingAs($this->mhs)->post(route('logbook.submit', $draft))
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_revision_submit_requires_complete_table(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);

        $draft = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->entrySubmitted->mahasiswa_ta_id,
            'parent_entry_id' => $this->entrySubmitted->id,
            'revision_round' => 1,
            'sesi_ke' => null,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'dosen_id' => $this->dosen->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ]);

        // Draf tanpa tabel tidak boleh dikirim ke dosen.
        $this->actingAs($this->mhs)->post(route('logbook.submit', $draft))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(LogbookEntry::STATUS_REVISION_IN_PROGRESS, $draft->fresh()->status);
    }

    public function test_saving_revision_draft_does_not_mark_comments_addressed(): void
    {
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_REVISI, 'reviewed_at' => now()]);
        $comment = $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id,
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 1,
            'comment' => 'Tambahkan penjelasan pada bagian ini.',
            'resolution_status' => PdfComment::STATUS_OPEN,
            'is_resolved' => false,
        ]);

        $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'addressed_comment_status' => [$comment->id => 'Sebagian'],
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => 'Perbaikan sedang disiapkan dalam draft.',
            'riwayat_perbaikan' => [
                [
                    'halaman' => 'Bab 3',
                    'komentar_dosen' => 'Tambahkan penjelasan pada bagian ini.',
                    'perbaikan' => 'Penjelasan sedang disiapkan.',
                    'status' => 'Sebagian',
                ],
            ],
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('pdf_comments', [
            'id' => $comment->id,
            'resolution_status' => PdfComment::STATUS_OPEN,
        ]);
    }

    public function test_comment_moves_from_open_to_addressed_then_resolved(): void
    {
        $comment = $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id,
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 1,
            'comment' => 'Jelaskan sumber data pada halaman ini.',
            'resolution_status' => PdfComment::STATUS_OPEN,
            'is_resolved' => false,
        ]);

        $this->actingAs($this->mhs)
            ->postJson(route('pdf-comments.resolve', $comment))
            ->assertJsonPath('resolution_status', PdfComment::STATUS_ADDRESSED);

        $this->actingAs($this->dosen)
            ->postJson(route('pdf-comments.resolve', $comment))
            ->assertJsonPath('resolution_status', PdfComment::STATUS_RESOLVED);
    }

    public function test_create_revisi_prefills_rows_from_parent_comments(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_REVISI,
            'reviewed_at' => now(),
        ]);

        $this->entrySubmitted->comments()->create([
            'user_id' => $this->dosen->id,
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 3,
            'comment' => 'Perbaiki diagram pada bab ini.',
            'resolution_status' => PdfComment::STATUS_OPEN,
            'is_resolved' => false,
        ]);

        $this->actingAs($this->mhs)
            ->get(route('logbook.create-revisi', ['parent_entry_id' => $this->entrySubmitted->id]))
            ->assertOk()
            ->assertSee('Hal. 3')
            ->assertSee('Perbaiki diagram pada bab ini.');
    }

    public function test_submit_revisi_without_pesan_does_not_500(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_REVISI,
            'reviewed_at' => now(),
        ]);

        // "Pesan untuk Dosen" (progres_kendala) opsional — dikosongkan.
        $response = $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'parent_entry_id' => $this->entrySubmitted->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => '',
            'riwayat_perbaikan' => [
                ['halaman' => 'Hal. 3', 'komentar_dosen' => 'Perbaiki diagram.', 'perbaikan' => 'Sudah.', 'status' => 'Sudah'],
            ],
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
            'submit' => '1',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('logbook_entries', [
            'parent_entry_id' => $this->entrySubmitted->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'progres_kendala' => null,
        ]);
    }

    public function test_update_revisi_without_pesan_does_not_500(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_REVISI,
            'reviewed_at' => now(),
        ]);

        $draft = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->entrySubmitted->mahasiswa_ta_id,
            'parent_entry_id' => $this->entrySubmitted->id,
            'revision_round' => 1,
            'sesi_ke' => null, // revisi: sesi tidak dipakai (null)
            'jenis' => LogbookEntry::JENIS_REVISI,
            'dosen_id' => $this->dosen->id,
            'topik' => $this->entrySubmitted->topik,
            'progres_kendala' => 'Pesan awal',
            'tanggal_pengiriman' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ]);

        $response = $this->actingAs($this->mhs)->put(route('logbook.update', $draft), [
            'tanggal_pengiriman' => now()->toDateString(),
            'progres_kendala' => '',
            'riwayat_perbaikan' => [
                ['halaman' => 'Hal. 3', 'komentar_dosen' => 'Perbaiki diagram.', 'perbaikan' => 'Sudah.', 'status' => 'Sudah'],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('logbook_entries', [
            'id' => $draft->id,
            'progres_kendala' => null,
        ]);
    }

    public function test_revision_feedback_must_be_meaningful(): void
    {
        $this->actingAs($this->dosen)
            ->post(route('logbook.request-revisi', $this->entrySubmitted), ['feedback_dosen' => 'Perbaiki.'])
            ->assertSessionHasErrors('feedback_dosen');
    }

    public function test_dosen_can_reopen_approved_to_submitted(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->dosen)
            ->post(route('logbook.reopen', $this->entrySubmitted))
            ->assertRedirect();

        $this->assertDatabaseHas('logbook_entries', [
            'id' => $this->entrySubmitted->id,
            'status' => LogbookEntry::STATUS_SUBMITTED,
        ]);
        $this->assertNull($this->entrySubmitted->fresh()->reviewed_at);
    }

    public function test_dosen_can_request_revisi_again_on_approved(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $feedback = 'Ternyata masih ada bagian metodologi yang perlu diperbaiki lagi dengan detail.';

        $this->actingAs($this->dosen)
            ->post(route('logbook.reopen-revisi', $this->entrySubmitted), ['feedback_dosen' => $feedback])
            ->assertRedirect();

        $this->assertDatabaseHas('logbook_entries', [
            'id' => $this->entrySubmitted->id,
            'status' => LogbookEntry::STATUS_REVISI,
            'feedback_dosen' => $feedback,
        ]);
    }

    public function test_reopen_revisi_requires_meaningful_feedback(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->dosen)
            ->post(route('logbook.reopen-revisi', $this->entrySubmitted), ['feedback_dosen' => 'Perbaiki.'])
            ->assertSessionHasErrors('feedback_dosen');
    }

    public function test_mahasiswa_cannot_reopen_approved(): void
    {
        $this->entrySubmitted->update([
            'status' => LogbookEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->mhs)
            ->post(route('logbook.reopen', $this->entrySubmitted))
            ->assertForbidden();

        $this->actingAs($this->mhs)
            ->post(route('logbook.reopen-revisi', $this->entrySubmitted), ['feedback_dosen' => 'Mahasiswa mencoba membuka kembali entri yang disetujui.'])
            ->assertForbidden();
    }

    public function test_reopen_only_from_approved_status(): void
    {
        // entrySubmitted masih berstatus submitted → reopen harus 403 via policy.
        $this->actingAs($this->dosen)
            ->post(route('logbook.reopen', $this->entrySubmitted))
            ->assertForbidden();
    }
}
