<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman riwayat seminar/sidang: rekap pengajuan, jalan ke detail/edit, dan
 * tombol tambah bahan. Menggantikan tautan sidebar yang sebelumnya langsung
 * membuka form pengajuan.
 */
class SeminarRiwayatTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;

    private User $dosen;

    private User $penguji;

    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = User::create([
            'name' => 'Mhs Riwayat',
            'email' => 'mhs-riwayat-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234500001',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen Riwayat',
            'email' => 'dosen-riwayat-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10),
            'registration_status' => 'active',
        ]);
        $this->dosen->assignRole('dosen');

        $this->penguji = User::create([
            'name' => 'Penguji Riwayat',
            'email' => 'penguji-riwayat-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10),
            'registration_status' => 'active',
        ]);
        $this->penguji->assignRole('dosen');

        $this->ta = MahasiswaTa::create([
            'user_id' => $this->mhs->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'pembimbing_1_id' => $this->dosen->id,
            'penguji_1_id' => $this->penguji->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'seminar_hasil',
        ]);
    }

    private function submission(string $jenis, ?string $tanggal = null): SeminarSubmission
    {
        return SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => $jenis,
            'tanggal' => $tanggal ?: now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/'.$this->ta->id.'/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);
    }

    public function test_halaman_riwayat_menampilkan_rekap_dan_daftar_bahan(): void
    {
        $this->submission(SeminarSubmission::JENIS_PROPOSAL);
        $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL, now()->addDays(3)->toDateString());

        $response = $this->actingAs($this->mhs)->get(route('seminar-submission.index'));

        $response->assertOk()
            ->assertSee('Riwayat Seminar/Sidang')
            ->assertSee('Seminar Proposal')
            ->assertSee('Seminar Hasil')
            ->assertSee('Dikirim')
            ->assertSee('Total pengajuan')
            ->assertSee('Berkas di arsip');
    }

    public function test_mahasiswa_dapat_tombol_tambah_dan_edit_sesuai_fase(): void
    {
        // Fase sekarang seminar hasil → proposal read-only, seminar hasil bisa diedit.
        $proposal = $this->submission(SeminarSubmission::JENIS_PROPOSAL);
        $hasil = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL);

        $html = $this->actingAs($this->mhs)
            ->get(route('seminar-submission.index'))
            ->assertOk()
            ->getContent();

        // tombol tambah bahan tersedia
        $this->assertStringContainsString(route('seminar-submission.create', $this->ta), $html);
        $this->assertStringContainsString('Kirim Bahan Seminar/Sidang', $html);

        // edit tersedia untuk bahan fase sekarang, tidak untuk fase lampau
        $this->assertStringContainsString(route('seminar-submission.edit', $hasil), $html);
        $this->assertStringNotContainsString(route('seminar-submission.edit', $proposal), $html);

        // detail selalu bisa dibuka
        $this->assertStringContainsString(route('seminar-submission.show', $proposal), $html);
    }

    public function test_dosen_melihat_riwayat_tanpa_tombol_tambah_atau_edit(): void
    {
        $sub = $this->submission(SeminarSubmission::JENIS_SEMINAR_HASIL);

        $html = $this->actingAs($this->dosen)
            ->get(route('seminar-submission.index', ['mahasiswa_ta' => $this->ta->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Riwayat Seminar/Sidang', $html);
        $this->assertStringContainsString(route('seminar-submission.show', $sub), $html);
        $this->assertStringNotContainsString('Kirim Bahan Seminar/Sidang', $html);
        $this->assertStringNotContainsString(route('seminar-submission.edit', $sub), $html);
    }

    public function test_dosen_tanpa_parameter_mahasiswa_diarahkan_ke_workspace(): void
    {
        $this->actingAs($this->dosen)
            ->get(route('seminar-submission.index'))
            ->assertRedirect(route('workspace.role'));
    }

    public function test_mahasiswa_tanpa_program_diarahkan_ke_dashboard(): void
    {
        $baru = User::create([
            'name' => 'Mhs Tanpa Program Riwayat',
            'email' => 'mhs-noprog-r-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234500002',
            'registration_status' => 'active',
        ]);
        $baru->assignRole('mahasiswa');

        $this->actingAs($baru)
            ->get(route('seminar-submission.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_riwayat_kosong_menampilkan_ajakan_kirim_bahan(): void
    {
        $this->actingAs($this->mhs)
            ->get(route('seminar-submission.index'))
            ->assertOk()
            ->assertSee('Belum ada bahan yang diajukan')
            ->assertSee('Kirim Bahan Seminar/Sidang');
    }

    public function test_sidebar_mengarah_ke_halaman_riwayat(): void
    {
        $html = $this->actingAs($this->mhs)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Seminar/sidang', $html);
        $this->assertStringContainsString('href="'.route('seminar-submission.index').'"', $html);
    }
}
