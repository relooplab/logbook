<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fitur alur bahan seminar/sidang:
 *  - Dashboard: mahasiswa bisa kirim bahan untuk milestone seminar berikutnya
 *    (proposal → seminar_hasil), terkait filter `$seminarSubmission` per jenis.
 *  - Edit dokumen diperbolehkan selama FASE program belum maju (jadwal yang
 *    sudah lewat pun tetap boleh diperbaiki) dan belum dikonversi ke riwayat
 *    sidang. Setelah fase maju, bahan fase sebelumnya menjadi read-only.
 */
class SeminarSubmissionPhaseTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;
    private User $dosen;
    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();

        // Clone lokal tidak selalu punya aset ter-build (@vite manifest).
        $this->withoutVite();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = User::create([
            'name' => 'Mhs Seminar',
            'email' => 'mhs-seminar-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen Seminar',
            'email' => 'dosen-seminar-'.uniqid().'@t.test',
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
            'fase' => 'proposal',
        ]);
    }

    private function makeSubmission(string $jenis, ?string $tanggal = null): SeminarSubmission
    {
        return SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => $jenis,
            'tanggal' => $tanggal ?: now()->addDays(7)->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);
    }

    // --------------------------- A. kirim bahan untuk fase berikutnya ---------------------------

    public function test_tombol_kirim_muncul_saat_belum_ada_submission_fase_aktif(): void
    {
        $this->actingAs($this->mhs)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kirim Bahan')
            ->assertDontSee('Lihat Detail');
    }

    public function test_setelah_kirim_tombol_berubah_menjadi_lihat_detail(): void
    {
        $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL);

        // Setelah kirim: tombol utama jadi "Lihat Detail". Mahasiswa TA tetap
        // punya jalan mengajukan jenis lain lewat "Kirim Bahan Seminar/Sidang".
        $this->actingAs($this->mhs)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Lihat Detail')
            ->assertSee('Kirim Bahan Seminar/Sidang')
            // tombol CTA tunggal "Kirim Bahan" tidak lagi ditampilkan
            ->assertDontSee('>Kirim Bahan<', false);
    }

    public function test_pindah_fase_munculkan_tombol_kirim_lagi(): void
    {
        // Sudah kirim bahan seminar proposal.
        $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL);

        // Pindah fase ke seminar hasil.
        $this->ta->update(['fase' => 'seminar_hasil']);

        $this->actingAs($this->mhs)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kirim Bahan')
            ->assertDontSee('Lihat Detail');
    }

    // --------------------------- B. window edit sebelum jadwal lewat ---------------------------

    public function test_edit_diizinkan_saat_jadwal_belum_lewat(): void
    {
        $sub = $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL, now()->addDays(3)->toDateString());

        $this->assertTrue($sub->isUpdatableByStudent());

        $this->actingAs($this->mhs)
            ->get(route('seminar-submission.edit', $sub))
            ->assertOk();
    }

    public function test_edit_tetap_diizinkan_saat_jadwal_sudah_lewat_selama_fase_belum_maju(): void
    {
        // Perilaku baru: yang mengunci adalah FASE, bukan tanggal. Jadwal yang
        // sudah lewat tetap boleh diperbaiki (mis. berkas salah unggah).
        $sub = $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL, now()->subDays(1)->toDateString());

        $this->assertTrue($sub->isUpdatableByStudent());

        $this->actingAs($this->mhs)
            ->get(route('seminar-submission.edit', $sub))
            ->assertOk();
    }

    public function test_edit_ditolak_saat_fase_program_sudah_maju(): void
    {
        // Fase sekarang: proposal. Setelah maju ke seminar hasil, bahan proposal
        // tidak boleh diedit lagi (berkas lama tetap ada di arsip workspace).
        $sub = $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL, now()->addDays(3)->toDateString());

        $this->ta->update(['fase' => 'seminar_hasil']);

        $this->assertFalse($sub->fresh()->isUpdatableByStudent());

        $this->actingAs($this->mhs)
            ->get(route('seminar-submission.edit', $sub))
            ->assertStatus(403);

        $this->actingAs($this->mhs)
            ->put(route('seminar-submission.update', $sub), [
                'tanggal' => now()->addDays(2)->toDateString(),
                'waktu' => '10:00',
                'undangan_kepada' => ['pembimbing_1'],
                'catatan_keterangan' => 'rev',
            ])
            ->assertStatus(403);
    }

    public function test_edit_ditolak_saat_submission_dikonversi_ke_sidang(): void
    {
        $sub = $this->makeSubmission(SeminarSubmission::JENIS_SIDANG, now()->addDays(7)->toDateString());
        $sidang = \App\Models\Sidang::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'penguji_id' => $this->dosen->id,
            'jenis' => SeminarSubmission::JENIS_SIDANG,
            'tanggal' => now()->subWeek()->toDateString(),
        ]);
        $sub->forceFill(['sidang_id' => $sidang->id])->save();

        $this->assertFalse($sub->fresh()->isUpdatableByStudent());

        $this->actingAs($this->mhs)
            ->get(route('seminar-submission.edit', $sub))
            ->assertStatus(403);
    }
}
