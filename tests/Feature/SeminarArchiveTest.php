<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use App\Models\WorkspaceFile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aturan edit bahan seminar/sidang berbasis FASE + arsip berkas lama.
 *
 * - Mahasiswa boleh mengedit selama fase program belum maju (jadwal lewat pun
 *   tetap boleh) dan belum dikonversi menjadi riwayat sidang.
 * - Berkas yang diganti TIDAK dihapus: pindah ke grup "Arsip" workspace.
 * - Hanya dosen pembimbing/penguji (atau admin) yang boleh memusnahkan arsip.
 */
class SeminarArchiveTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;

    private User $dosen;

    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = User::create([
            'name' => 'Mhs Arsip',
            'email' => 'mhs-arsip-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567800',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen Arsip',
            'email' => 'dosen-arsip-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10),
            'registration_status' => 'active',
        ]);
        $this->dosen->assignRole('dosen');

        $this->ta = MahasiswaTa::create([
            'user_id' => $this->mhs->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'pembimbing_1_id' => $this->dosen->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'seminar_hasil',
        ]);
    }

    private function submission(string $jenis = SeminarSubmission::JENIS_SEMINAR_HASIL, array $override = []): SeminarSubmission
    {
        return SeminarSubmission::create(array_merge([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => $jenis,
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/'.$this->ta->id.'/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ], $override));
    }

    // ------------------------------------------------ aturan fase

    public function test_bahan_fase_lampau_terkunci(): void
    {
        // Fase sekarang: seminar_hasil.
        $proposal = $this->submission(SeminarSubmission::JENIS_PROPOSAL);
        $hasil = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL);
        $sidang = $this->submission(SeminarSubmission::JENIS_SIDANG);

        $this->assertFalse($proposal->isUpdatableByStudent(), 'Bahan proposal harus terkunci saat fase sudah seminar hasil.');
        $this->assertTrue($hasil->isUpdatableByStudent(), 'Bahan fase saat ini harus tetap bisa diedit.');
        $this->assertTrue($sidang->isUpdatableByStudent(), 'Bahan fase yang lebih maju tetap bisa diedit.');
    }

    public function test_jadwal_lewat_tetap_bisa_diedit_selama_fase_belum_maju(): void
    {
        $lampau = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'tanggal' => now()->subMonth()->toDateString(),
        ]);

        $this->assertTrue(
            $lampau->fresh()->isUpdatableByStudent(),
            'Jadwal lewat tidak boleh mengunci selama fase program belum maju.'
        );
    }

    public function test_submission_yang_sudah_menjadi_riwayat_sidang_terkunci(): void
    {
        $sidang = \App\Models\Sidang::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'penguji_id' => $this->dosen->id,
            'jenis' => SeminarSubmission::JENIS_SEMINAR_HASIL,
            'tanggal' => now()->subWeek()->toDateString(),
        ]);

        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, ['sidang_id' => $sidang->id]);

        $this->assertFalse($s->isUpdatableByStudent());
    }

    // ------------------------------------------------ arsip berkas

    public function test_undangan_lama_diarsipkan_bukan_dihapus_saat_diedit(): void
    {
        $oldPath = 'seminar-materials/'.$this->ta->id.'/undangan-lama.pdf';
        Storage::disk('local')->put($oldPath, 'isi-pdf-lama');

        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'undangan_path' => $oldPath,
            'undangan_original_name' => 'undangan-lama.pdf',
        ]);

        $this->actingAs($this->mhs)->put(route('seminar-submission.update', $s), [
            'tanggal' => now()->addDays(3)->toDateString(),
            'waktu' => '10:00',
            'undangan_kepada' => ['pembimbing_1'],
            'undangan' => UploadedFile::fake()->create('undangan-baru.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        // berkas fisik lama tetap ada
        Storage::disk('local')->assertExists($oldPath);

        // dan tercatat sebagai arsip di workspace mahasiswa
        $arsip = WorkspaceFile::where('path', $oldPath)->first();
        $this->assertNotNull($arsip, 'Berkas lama harus tercatat di workspace.');
        $this->assertTrue($arsip->isArchived());
        $this->assertSame(WorkspaceFile::BAB_ARSIP, $arsip->bab);
        $this->assertStringContainsString('Undangan', (string) $arsip->archive_note);

        // submission sudah memakai berkas baru
        $s->refresh();
        $this->assertNotSame($oldPath, $s->undangan_path);
        $this->assertSame('undangan-baru.pdf', $s->undangan_original_name);
    }

    // ------------------------------------------------ siapa boleh hapus arsip

    public function test_mahasiswa_tidak_boleh_menghapus_berkas_arsip(): void
    {
        $arsip = WorkspaceFile::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'uploaded_by' => $this->mhs->id,
            'bab' => WorkspaceFile::BAB_ARSIP,
            'original_name' => 'undangan-lama.pdf',
            'path' => 'seminar-materials/'.$this->ta->id.'/arsip.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->mhs)
            ->delete(route('workspace.destroy', $arsip))
            ->assertForbidden();

        $this->assertDatabaseHas('workspace_files', ['id' => $arsip->id]);
    }

    public function test_dosen_pembimbing_boleh_menghapus_berkas_arsip(): void
    {
        $path = 'seminar-materials/'.$this->ta->id.'/arsip-dosen.pdf';
        Storage::disk('local')->put($path, 'x');

        $arsip = WorkspaceFile::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'uploaded_by' => $this->mhs->id,
            'bab' => WorkspaceFile::BAB_ARSIP,
            'original_name' => 'arsip-dosen.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 1,
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->dosen)
            ->delete(route('workspace.destroy', $arsip))
            ->assertRedirect();

        $this->assertDatabaseMissing('workspace_files', ['id' => $arsip->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_berkas_biasa_tetap_boleh_dihapus_mahasiswa(): void
    {
        WorkspaceFile::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'uploaded_by' => $this->mhs->id,
            'bab' => 'Bab 1',
            'original_name' => 'bab1.docx',
            'path' => 'workspace/'.$this->ta->id.'/bab1.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 10,
            'is_archived' => false,
        ]);

        $file = WorkspaceFile::where('original_name', 'bab1.docx')->firstOrFail();

        $this->actingAs($this->mhs)
            ->delete(route('workspace.destroy', $file))
            ->assertRedirect();

        $this->assertDatabaseMissing('workspace_files', ['id' => $file->id]);
    }

    public function test_workspace_menampilkan_grup_arsip_dan_menyembunyikan_tombol_hapus_untuk_mahasiswa(): void
    {
        WorkspaceFile::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'uploaded_by' => $this->mhs->id,
            'bab' => WorkspaceFile::BAB_ARSIP,
            'original_name' => 'undangan-lama.pdf',
            'path' => 'seminar-materials/'.$this->ta->id.'/arsip2.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'is_archived' => true,
            'archived_at' => now(),
            'archive_note' => 'Undangan Seminar Hasil #1 — diganti',
        ]);

        $html = $this->actingAs($this->mhs)
            ->get(route('workspace.index', $this->ta))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Arsip', $html);
        $this->assertStringContainsString('Undangan Seminar Hasil #1 — diganti', $html);
        // form hapus tidak dirender untuk mahasiswa pada berkas arsip
        // (URL download berbagi prefiks, jadi yang dicek adalah atribut action form)
        $arsip = WorkspaceFile::where('is_archived', true)->firstOrFail();
        $this->assertStringNotContainsString('action="'.route('workspace.destroy', $arsip).'"', $html);
    }
}
