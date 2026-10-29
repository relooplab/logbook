<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UX pengajuan bahan seminar: default jenis mengikuti fase membuat mahasiswa
 * mengira hanya jenis itu yang boleh dikirim. Form kini menampilkan catatan
 * efek tiap jenis terhadap fase, plus hint bahwa jenis lain boleh dipilih.
 *
 * Aturan validasi TIDAK diubah oleh tes ini — hanya kejelasan tampilan.
 */
class SeminarJenisUxTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;

    private User $dosen;

    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();

        // Aset frontend tidak selalu ter-build di lingkungan test; halaman yang
        // memakai @vite cukup dirender tanpa manifest.
        $this->withoutVite();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = User::create([
            'name' => 'Mhs UX Jenis',
            'email' => 'mhs-ux-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen UX Jenis',
            'email' => 'dosen-ux-'.uniqid().'@t.test',
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

    public function test_catatan_per_jenis_menjelaskan_efek_terhadap_fase(): void
    {
        $notes = SeminarSubmission::jenisNotesFor($this->ta);

        $this->assertSame('perbaikan berkas, fase tidak berubah', $notes[SeminarSubmission::JENIS_PROPOSAL]);
        $this->assertSame('sesuai fase saat ini', $notes[SeminarSubmission::JENIS_SEMINAR_HASIL]);
        $this->assertSame('memajukan fase ke Sidang Akhir', $notes[SeminarSubmission::JENIS_SIDANG]);
    }

    public function test_form_menampilkan_pilihan_sidang_dengan_catatan_fase(): void
    {
        $response = $this->actingAs($this->mhs)->get(route('seminar-submission.create', $this->ta));

        $response->assertOk()
            // ketiga jenis tetap tersedia (label boleh mengikuti penamaan prodi)
            ->assertSee('value="'.SeminarSubmission::JENIS_PROPOSAL.'"', false)
            ->assertSee('value="'.SeminarSubmission::JENIS_SEMINAR_HASIL.'"', false)
            ->assertSee('value="'.SeminarSubmission::JENIS_SIDANG.'"', false)
            // catatan efek fase per jenis
            ->assertSee('memajukan fase ke Sidang Akhir', false)
            ->assertSee('sesuai fase saat ini', false)
            ->assertSee('perbaikan berkas, fase tidak berubah', false)
            // hint bahwa default boleh diubah
            ->assertSee('Boleh pilih jenis lain bila sudah siap lebih awal', false)
            ->assertSee('memajukan fase program secara otomatis', false);
    }

    public function test_form_tidak_menampilkan_hint_multi_jenis_untuk_kp(): void
    {
        $this->ta->update(['jenis' => MahasiswaTa::JENIS_KP, 'fase' => 'pelaksanaan']);

        // KP hanya punya satu jenis → tidak ada catatan/hint pilihan.
        $this->assertSame([], SeminarSubmission::jenisNotesFor($this->ta->fresh()));

        $this->actingAs($this->mhs)->get(route('seminar-submission.create', $this->ta))
            ->assertOk()
            ->assertDontSee('Boleh pilih jenis lain bila sudah siap lebih awal', false)
            ->assertDontSee('fase tidak berubah', false);
    }

    public function test_dashboard_mahasiswa_ta_mengingatkan_bisa_ajukan_jenis_lain(): void
    {
        $this->actingAs($this->mhs)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Bisa juga mengajukan jenis lain', false)
            ->assertSee('Sidang', false);
    }

    public function test_dashboard_tidak_membocorkan_kode_blade(): void
    {
        // Regresi: `@php ... @endphp` yang ditulis menyambung di tengah baris HTML
        // membuat Blade mengeluarkan placeholder @__raw_block_N__ dan echo
        // sesudahnya tidak dieksekusi — mahasiswa melihat kode mentah.
        $html = $this->actingAs($this->mhs)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('@__raw_block', $html);
        $this->assertStringNotContainsString('@endphp', $html);
        $this->assertStringNotContainsString('@php', $html);
        $this->assertStringNotContainsString("{{", $html);
    }

    private function submissionForCurrentPhase(): SeminarSubmission
    {
        return SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_SEMINAR_HASIL,
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);
    }

    public function test_dashboard_menyediakan_jalan_ke_form_saat_submission_fase_ini_sudah_ada(): void
    {
        $this->submissionForCurrentPhase();
        $createUrl = route('seminar-submission.create', $this->ta);

        $html = $this->actingAs($this->mhs)->get(route('dashboard'))->assertOk()->getContent();

        // Sebelum perbaikan: tombol berubah "Lihat Detail" dan link create hilang
        // sama sekali, sehingga mahasiswa tidak bisa mengajukan bahan sidang.
        $this->assertStringContainsString($createUrl, $html);
        $this->assertStringContainsString('Ajukan Jenis Lain', $html);
        $this->assertStringContainsString('Lihat Detail', $html);
        $this->assertStringContainsString('Bisa juga mengajukan jenis lain', $html);
    }

    public function test_kp_tidak_mendapat_tombol_ajukan_jenis_lain(): void
    {
        $this->ta->update(['jenis' => MahasiswaTa::JENIS_KP, 'fase' => 'laporan']);
        SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_SEMINAR_KP,
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);

        $html = $this->actingAs($this->mhs->fresh())->get(route('dashboard'))->assertOk()->getContent();

        // KP hanya satu jenis → tidak perlu tombol/hint pilihan jenis lain.
        $this->assertStringNotContainsString('Ajukan Jenis Lain', $html);
        $this->assertStringNotContainsString('Bisa juga mengajukan jenis lain', $html);
    }
}
