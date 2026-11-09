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

    /** Submission dianggap selesai otomatis (kegiatan > 1 hari lalu). */
    private function selesaikan(SeminarSubmission $sub): SeminarSubmission
    {
        $sub->forceFill([
            'tanggal' => now()->subDays(SeminarSubmission::AUTO_SELESAI_HARI + 1)->toDateString(),
            'selesai_dikecualikan' => false,
        ])->save();

        return $sub->fresh();
    }

    /** Setelah selesai, edit butuh izin; bantu setujui langsung (sekali pakai). */
    private function izinkanEdit(SeminarSubmission $sub): SeminarSubmission
    {
        $sub->forceFill([
            'edit_requested_at' => now(),
            'edit_approved_at' => now(),
            'edit_approved_by' => $this->dosen->id,
        ])->save();

        return $sub->fresh();
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
            // Dosen menahan status otomatis agar tetap "belum selesai".
            'selesai_dikecualikan' => true,
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

        // Sudah ditandai SELESAI + izin edit → berkas lama masuk arsip.
        $tanggalLewat = now()->subDays(3)->toDateString();

        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'tanggal' => $tanggalLewat,
            'undangan_path' => $oldPath,
            'undangan_original_name' => 'undangan-lama.pdf',
        ]);
        $s = $this->izinkanEdit($this->selesaikan($s));

        // Tanggal yang sudah lewat boleh dipertahankan saat memperbaiki berkas.
        $this->actingAs($this->mhs)->put(route('seminar-submission.update', $s->fresh()), [
            'tanggal' => $s->fresh()->tanggal->toDateString(),
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

    // ------------------------------------------------ batas 4 berkas historis per field

    public function test_arsip_dibatasi_empat_per_field_dan_yang_terlama_tertimpa(): void
    {
        $awal = 'seminar-materials/'.$this->ta->id.'/undangan-0.pdf';
        Storage::disk('local')->put($awal, 'v0');

        $tanggalLewat = now()->subDays(3)->toDateString();

        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'tanggal' => $tanggalLewat,
            'undangan_path' => $awal,
            'undangan_original_name' => 'undangan-0.pdf',
        ]);
        $this->selesaikan($s);

        $pathLama = [$awal];

        // Ganti undangan 6 kali → total 6 berkas historis, batas 4.
        for ($i = 1; $i <= 6; $i++) {
            // Izin edit bersifat sekali pakai → setujui ulang tiap iterasi.
            $this->actingAs($this->mhs)->put(route('seminar-submission.update', $this->izinkanEdit($s->fresh())), [
                'tanggal' => $s->fresh()->tanggal->toDateString(),
                'waktu' => '10:00',
                'undangan_kepada' => ['pembimbing_1'],
                'undangan' => UploadedFile::fake()->create("undangan-{$i}.pdf", 20, 'application/pdf'),
            ])->assertRedirect();

            $pathLama[] = $s->fresh()->undangan_path;
        }

        $arsip = WorkspaceFile::where('mahasiswa_ta_id', $this->ta->id)
            ->archivedOfField(WorkspaceFile::FIELD_UNDANGAN)
            ->get();

        $this->assertCount(
            WorkspaceFile::MAX_ARSIP_PER_FIELD,
            $arsip,
            'Riwayat arsip per field harus dibatasi '.WorkspaceFile::MAX_ARSIP_PER_FIELD.' berkas.'
        );

        // Berkas paling lama sudah ditimpa (fisik & baris), yang terbaru tetap ada.
        Storage::disk('local')->assertMissing($awal);
        $this->assertDatabaseMissing('workspace_files', ['path' => $awal]);
        foreach ($arsip as $a) {
            Storage::disk('local')->assertExists($a->path);
        }

        // Yang tersisa adalah 4 arsip TERBARU (terbaru lebih dulu).
        // $pathLama berisi: berkas awal + tiap berkas baru tiap iterasi;
        // yang diarsipkan = semua kecuali berkas terakhir (yang aktif).
        $diarsipkan = array_slice($pathLama, 0, count($pathLama) - 1);
        $harapan = array_reverse(array_slice($diarsipkan, -WorkspaceFile::MAX_ARSIP_PER_FIELD));

        $this->assertSame(
            $harapan,
            $arsip->sortByDesc('id')->pluck('path')->values()->all()
        );
    }

    public function test_batas_arsip_dihitung_terpisah_per_field(): void
    {
        $undanganAwal = 'seminar-materials/'.$this->ta->id.'/undangan-awal.pdf';
        $materiAwal = 'seminar-materials/'.$this->ta->id.'/materi-awal.pdf';
        Storage::disk('local')->put($undanganAwal, 'u');
        Storage::disk('local')->put($materiAwal, 'm');

        $tanggalLewat = now()->subDays(3)->toDateString();

        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'tanggal' => $tanggalLewat,
            'undangan_path' => $undanganAwal,
            'undangan_original_name' => 'undangan-awal.pdf',
            'materi_path' => $materiAwal,
            'materi_original_name' => 'materi-awal.pdf',
        ]);
        $this->selesaikan($s);

        // 3x ganti undangan + 2x ganti materi — dua-duanya masih di bawah batas.
        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($this->mhs)->put(route('seminar-submission.update', $this->izinkanEdit($s->fresh())), [
                'tanggal' => $s->fresh()->tanggal->toDateString(),
                'waktu' => '10:00',
                'undangan_kepada' => ['pembimbing_1'],
                'undangan' => UploadedFile::fake()->create("u-{$i}.pdf", 10, 'application/pdf'),
            ])->assertRedirect();
        }

        for ($i = 1; $i <= 2; $i++) {
            $this->actingAs($this->mhs)->put(route('seminar-submission.update', $this->izinkanEdit($s->fresh())), [
                'tanggal' => $s->fresh()->tanggal->toDateString(),
                'waktu' => '10:00',
                'undangan_kepada' => ['pembimbing_1'],
                'materi_upload' => UploadedFile::fake()->create("m-{$i}.pdf", 10, 'application/pdf'),
            ])->assertRedirect();
        }

        $this->assertSame(3, WorkspaceFile::archivedOfField(WorkspaceFile::FIELD_UNDANGAN)->count());
        $this->assertSame(2, WorkspaceFile::archivedOfField(WorkspaceFile::FIELD_MATERI)->count());
        // tidak saling menghapus antar field
        Storage::disk('local')->assertExists($undanganAwal);
        Storage::disk('local')->assertExists($materiAwal);
    }

    public function test_berkas_arsip_yang_masih_dipakai_tidak_ditimpa(): void
    {
        // Materi diambil dari workspace: berkasnya masih dirujuk workspace aktif.
        $path = 'workspace/'.$this->ta->id.'/materi-ws.pdf';
        Storage::disk('local')->put($path, 'locked');
        $ws = WorkspaceFile::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'uploaded_by' => $this->mhs->id,
            'bab' => 'Bab 1',
            'original_name' => 'materi-ws.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 6,
            'is_archived' => false,
        ]);

        $this->assertTrue($ws->canBeDeletedBy($this->mhs));
        Storage::disk('local')->assertExists($path);
    }

    // ------------------------------------------------ sebelum tanggal kegiatan: tanpa arsip

    public function test_selama_belum_selesai_berkas_diganti_tanpa_arsip(): void
    {
        // Belum ditandai selesai oleh dosen — meski jadwalnya sudah lewat,
        // mahasiswa bebas mengganti berkas tanpa meninggalkan arsip.
        $lama = 'seminar-materials/'.$this->ta->id.'/undangan-belum-selesai.pdf';
        Storage::disk('local')->put($lama, 'lama');

        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'tanggal' => now()->subDays(5)->toDateString(),
            'selesai_dikecualikan' => true,
            'undangan_path' => $lama,
            'undangan_original_name' => 'undangan-belum-selesai.pdf',
        ]);

        $this->actingAs($this->mhs)->put(route('seminar-submission.update', $s), [
            'tanggal' => $s->tanggal->toDateString(),
            'waktu' => '10:00',
            'undangan_kepada' => ['pembimbing_1'],
            'undangan' => UploadedFile::fake()->create('undangan-revisi.pdf', 30, 'application/pdf'),
        ])->assertRedirect();

        // Berkas lama langsung diganti, tidak menyisakan arsip.
        Storage::disk('local')->assertMissing($lama);
        $this->assertSame(0, WorkspaceFile::where('is_archived', true)->count());
        $this->assertSame('undangan-revisi.pdf', $s->fresh()->undangan_original_name);
    }

    public function test_tanggal_kegiatan_lampau_boleh_dipertahankan_saat_edit(): void
    {
        $tanggalLewat = now()->subDays(2)->toDateString();
        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'tanggal' => $tanggalLewat,
            'selesai_dikecualikan' => true,
        ]);

        // Tanpa menggeser jadwal: tidak boleh gagal validasi.
        $this->actingAs($this->mhs)->put(route('seminar-submission.update', $s), [
            'tanggal' => $tanggalLewat,
            'waktu' => '11:00',
            'undangan_kepada' => ['pembimbing_1'],
            'catatan_keterangan' => 'perbaikan berkas',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($tanggalLewat, $s->fresh()->tanggal->toDateString());
    }

    public function test_tanggal_lampau_yang_berbeda_tetap_ditolak(): void
    {
        $s = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, [
            'tanggal' => now()->subDays(2)->toDateString(),
            'selesai_dikecualikan' => true,
        ]);

        // Menggeser ke tanggal lampau LAIN (bukan mempertahankan) harus ditolak.
        $this->actingAs($this->mhs)->put(route('seminar-submission.update', $s), [
            'tanggal' => now()->subDays(10)->toDateString(),
            'waktu' => '11:00',
            'undangan_kepada' => ['pembimbing_1'],
        ])->assertSessionHasErrors('tanggal');

        $this->assertSame(now()->subDays(2)->toDateString(), $s->fresh()->tanggal->toDateString());
    }
}
