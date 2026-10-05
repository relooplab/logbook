<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\SeminarSubmission;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class DetailWorkspaceTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    public function test_reviewer_sees_logbook_in_the_revision_workspace_layout(): void
    {
        $this->entrySubmitted->update([
            'topik' => 'Pembahasan bab metodologi',
            'progres_kendala' => 'Metode penelitian sudah diperbaiki.',
            'lampiran_path' => 'lampiran/logbook.pdf',
        ]);

        $response = $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted));

        $response->assertOk()
            ->assertSee('class="detail-workspace space-y-6"', false)
            ->assertSee('class="detail-workspace-grid"', false)
            ->assertSee('class="detail-workspace-panel space-y-4"', false)
            ->assertSee('Ringkasan logbook bimbingan')
            ->assertSee('Sesi '.$this->entrySubmitted->sesi_ke)
            ->assertSee('Pembahasan bab metodologi')
            ->assertSee('Tanggal Bimbingan')
            ->assertSee('Metode penelitian sudah diperbaiki.')
            ->assertSee('review-decision-form')
            ->assertSee(route('logbook.pdf-viewer', $this->entrySubmitted))
            ->assertSee(route('logbook.approve', $this->entrySubmitted))
            ->assertSee(route('chat.start', ['user' => $this->mhs->id, 'ta' => $this->ta->id, 'entry' => $this->entrySubmitted->id]))
            ->assertSee(route('logbook.request-revisi', $this->entrySubmitted))
            ->assertDontSee('Status review revisi');
        $this->assertSame(1, substr_count($response->getContent(), 'Buka PDF &amp; Anotasi'));
    }

    public function test_student_sees_logbook_workspace_without_reviewer_decision(): void
    {
        $response = $this->actingAs($this->mhs)->get(route('logbook.show', $this->entryDraft));

        $response->assertOk()
            ->assertSee('class="detail-workspace-grid"', false)
            ->assertSee('Ringkasan logbook bimbingan')
            ->assertSee('Ringkasan Perbaikan')
            ->assertSee(route('logbook.edit', $this->entryDraft))
            ->assertSee(route('chat.start', ['user' => $this->dosen->id, 'ta' => $this->ta->id, 'entry' => $this->entryDraft->id]))
            ->assertDontSee('id="review-decision-form"', false);
    }

    public function test_reviewer_sees_revision_workspace_and_existing_actions(): void
    {
        $this->entryRevisi->update([
            'dosen_id' => $this->dosen->id,
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'lampiran_path' => 'lampiran/revisi.pdf',
            'riwayat_perbaikan' => [
                ['halaman' => 'IV-1', 'komentar_dosen' => 'Perbaiki data tabel.', 'perbaikan' => 'Data tabel diperbaiki.', 'status' => 'Sudah'],
                ['halaman' => 'IV-2', 'komentar_dosen' => 'Periksa gambar.', 'perbaikan' => 'Gambar diperiksa.', 'status' => 'Belum'],
            ],
        ]);

        $response = $this->actingAs($this->dosen)->get(route('logbook.show', $this->entryRevisi));

        $response->assertOk()
            ->assertSee('Catatan Perbaikan')
            ->assertSee('1 dari 2 diperbaiki')
            ->assertSee('Perbaikan yang Dilakukan Mahasiswa')
            ->assertSee('revision-expand-all')
            ->assertSee('action-item-add-toggle')
            ->assertSee('review-decision-form')
            ->assertSee(route('logbook.pdf-viewer', $this->entryRevisi))
            ->assertSee(route('logbook.approve', $this->entryRevisi))
            ->assertSee(route('chat.start', ['user' => $this->mhs->id, 'ta' => $this->ta->id, 'entry' => $this->entryRevisi->id]))
            ->assertSee(route('logbook.request-revisi', $this->entryRevisi));
        $this->assertSame(1, substr_count($response->getContent(), 'Buka PDF &amp; Anotasi'));
    }

    public function test_approval_feedback_is_optional_but_revision_feedback_remains_required(): void
    {
        $this->actingAs($this->dosen)->post(route('logbook.approve', $this->entrySubmitted), [
            'feedback_dosen' => 'Sudah baik, lanjutkan ke bab berikutnya.',
        ])->assertRedirect();
        $this->assertSame('Sudah baik, lanjutkan ke bab berikutnya.', $this->entrySubmitted->fresh()->feedback_dosen);
        $this->actingAs($this->mhs)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()->assertSee('Sudah baik, lanjutkan ke bab berikutnya.');

        $this->entryRevisi->update(['dosen_id' => $this->dosen->id, 'status' => LogbookEntry::STATUS_SUBMITTED]);
        $this->actingAs($this->dosen)->post(route('logbook.approve', $this->entryRevisi))->assertRedirect();
        $this->assertSame(LogbookEntry::STATUS_APPROVED, $this->entryRevisi->fresh()->status);
        $this->assertNull($this->entryRevisi->fresh()->feedback_dosen);

        $this->entryDraft->update(['status' => LogbookEntry::STATUS_SUBMITTED, 'dosen_id' => $this->dosen->id]);
        $this->actingAs($this->dosen)->post(route('logbook.request-revisi', $this->entryDraft), ['feedback_dosen' => 'Pendek'])
            ->assertSessionHasErrors('feedback_dosen');
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $this->entryDraft->fresh()->status);
    }

    public function test_seminar_detail_separates_location_and_meeting_link_without_changing_document_routes(): void
    {
        $submission = SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            'tanggal' => now()->addDays(7)->toDateString(),
            'waktu' => '13:00',
            'lokasi' => 'Ruang Rapat',
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
            'undangan_path' => 'seminar/undangan.pdf',
            'undangan_original_name' => 'surat-undangan-panjang.pdf',
            'materi_path' => 'seminar/proposal.pdf',
            'materi_original_name' => 'proposal.pdf',
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);

        $response = $this->actingAs($this->dosen)->get(route('seminar-submission.show', $submission));

        $response->assertOk()
            ->assertSee('Jadwal Seminar Proposal')
            ->assertSee('Ruang Rapat')
            ->assertSee('Buka Tautan')
            ->assertSee('Video Conference')
            ->assertSee('surat-undangan-panjang.pdf')
            ->assertSee(route('seminar-submission.undangan-download', $submission))
            ->assertSee(route('seminar-submission.materi-preview', $submission))
            ->assertSee(route('seminar-submission.hardcopy-note', $submission))
            ->assertSee(route('dosen-sidang.index', ['submission' => $submission->id]));
        $response->assertDontSee('Zoom Meeting');
        $response->assertDontSee('Buka Zoom');
        $this->assertStringNotContainsString('Ruang Rapat https://', $response->getContent());
    }

    public function test_seminar_detail_falls_back_to_legacy_url_inside_lokasi(): void
    {
        $submission = SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            'tanggal' => now()->addDays(7)->toDateString(),
            'waktu' => '13:00',
            'lokasi' => 'Ruang Rapat https://zoom.us/j/123456',
            'meeting_link' => null,
            'undangan_path' => 'seminar/undangan.pdf',
            'undangan_original_name' => 'surat-undangan.pdf',
            'materi_path' => 'seminar/proposal.pdf',
            'materi_original_name' => 'proposal.pdf',
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);

        $response = $this->actingAs($this->dosen)->get(route('seminar-submission.show', $submission));

        $response->assertOk()
            ->assertSee('Ruang Rapat')
            ->assertSee('Video Conference')
            ->assertSee('Buka Tautan');
        $this->assertStringNotContainsString('Ruang Rapat https://zoom.us', $response->getContent());
    }
}
