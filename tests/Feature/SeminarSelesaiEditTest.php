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
 * Status "Selesai" oleh dosen + izin edit setelahnya.
 *
 * - Belum selesai  → mahasiswa bebas mengedit/mengganti berkas, tanpa arsip.
 * - Sudah selesai  → edit perlu persetujuan minimal salah satu dosen
 *                    (pembimbing/penguji), dan berkas lama masuk arsip.
 */
class SeminarSelesaiEditTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;

    private User $pembimbing;

    private User $penguji;

    private User $dosenLain;

    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = $this->makeUser('Mhs Selesai', 'mahasiswa', ['nim' => 'NIM'.substr(md5(uniqid()), 0, 8)]);
        $this->pembimbing = $this->makeUser('Pembimbing Selesai', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);
        $this->penguji = $this->makeUser('Penguji Selesai', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);
        $this->dosenLain = $this->makeUser('Dosen Lain', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);

        $this->ta = MahasiswaTa::create([
            'user_id' => $this->mhs->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'pembimbing_1_id' => $this->pembimbing->id,
            'penguji_1_id' => $this->penguji->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'seminar_hasil',
        ]);
    }

    private function makeUser(string $name, string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'whatsapp' => '6281234'.random_int(10000, 99999),
            'registration_status' => 'active',
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function submission(?string $undanganPath = null): SeminarSubmission
    {
        $path = $undanganPath ?? 'seminar-materials/'.$this->ta->id.'/undangan.pdf';
        Storage::disk('local')->put($path, 'isi');

        return SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_SEMINAR_HASIL,
            // Hari ini: aturan otomatis (2 hari setelah kegiatan) belum berlaku.
            'tanggal' => now()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => $path,
            'undangan_original_name' => basename($path),
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);
    }

    private function payload(SeminarSubmission $sub, ?string $undanganName = 'undangan-baru.pdf'): array
    {
        $data = [
            'tanggal' => $sub->tanggal->toDateString(),
            'waktu' => '10:00',
            'undangan_kepada' => ['pembimbing_1'],
        ];

        if ($undanganName) {
            $data['undangan'] = UploadedFile::fake()->create($undanganName, 20, 'application/pdf');
        }

        return $data;
    }

    /** Jadikan selesai lewat aturan otomatis (kegiatan > 2 hari lalu). */
    private function buatSelesai(SeminarSubmission $sub): SeminarSubmission
    {
        $sub->forceFill([
            'tanggal' => now()->subDays(SeminarSubmission::AUTO_SELESAI_HARI + 1)->toDateString(),
            'selesai_dikecualikan' => false,
        ])->save();

        return $sub->fresh();
    }

    // ------------------------------------------------ status otomatis

    public function test_sebelum_selesai_mahasiswa_bebas_ganti_berkas_tanpa_arsip(): void
    {
        $awal = 'seminar-materials/'.$this->ta->id.'/undangan-bebas.pdf';
        $sub = $this->submission($awal);

        $this->assertTrue($sub->isUpdatableByStudent());

        $this->actingAs($this->mhs)
            ->put(route('seminar-submission.update', $sub), $this->payload($sub, 'revisi-bebas.pdf'))
            ->assertRedirect();

        Storage::disk('local')->assertMissing($awal);
        $this->assertSame(0, WorkspaceFile::where('is_archived', true)->count());
    }

    // ------------------------------------------------ sudah selesai = butuh izin

    public function test_setelah_selesai_mahasiswa_tidak_bisa_edit_tanpa_izin(): void
    {
        $sub = $this->buatSelesai($this->submission());

        $sub->refresh();
        $this->assertFalse($sub->isUpdatableByStudent());

        $this->actingAs($this->mhs)->get(route('seminar-submission.edit', $sub))->assertForbidden();
        $this->actingAs($this->mhs)->put(route('seminar-submission.update', $sub), $this->payload($sub))->assertForbidden();
    }

    public function test_mahasiswa_meminta_izin_edit_dan_salah_satu_dosen_menyetujui(): void
    {
        $sub = $this->buatSelesai($this->submission());

        // minta izin
        $this->actingAs($this->mhs)
            ->post(route('seminar-submission.minta-edit', $sub), ['edit_request_note' => 'Salah unggah berkas'])
            ->assertRedirect();

        $sub->refresh();
        $this->assertTrue($sub->isMenungguPersetujuanEdit());
        $this->assertSame('Salah unggah berkas', $sub->edit_request_note);

        // minimal salah satu dosen: penguji (bukan pembimbing) menyetujui
        $this->actingAs($this->penguji)
            ->post(route('seminar-submission.setujui-edit', $sub))
            ->assertRedirect();

        $sub->refresh();
        $this->assertTrue($sub->isEditApproved());
        $this->assertSame($this->penguji->id, $sub->edit_approved_by);
        $this->assertTrue($sub->isUpdatableByStudent());
        $this->actingAs($this->mhs)->get(route('seminar-submission.edit', $sub))->assertOk();
    }

    public function test_edit_setelah_disetujui_mengarsipkan_berkas_dan_izin_berlaku_7_hari(): void
    {
        $awal = 'seminar-materials/'.$this->ta->id.'/undangan-sebelum-selesai.pdf';
        $sub = $this->submission($awal);

        $sub = $this->buatSelesai($sub);
        $this->actingAs($this->mhs)->post(route('seminar-submission.minta-edit', $sub));
        $this->actingAs($this->pembimbing)->post(route('seminar-submission.setujui-edit', $sub));

        $this->actingAs($this->mhs)
            ->put(route('seminar-submission.update', $sub), $this->payload($sub, 'perbaikan.pdf'))
            ->assertRedirect();

        // berkas lama masuk arsip (bukan hilang)
        Storage::disk('local')->assertExists($awal);
        $arsip = WorkspaceFile::where('path', $awal)->first();
        $this->assertNotNull($arsip);
        $this->assertTrue($arsip->isArchived());
        $this->assertSame(WorkspaceFile::FIELD_UNDANGAN, $arsip->archive_field);

        // izin TIDAK dikonsumsi: masih berlaku sampai 7 hari ke depan
        $sub->refresh();
        $this->assertNotNull($sub->edit_approved_at);
        $this->assertTrue($sub->isEditApproved());
        $this->assertTrue($sub->isUpdatableByStudent());
        // Masa berlaku izin = 7 hari (keputusan produk, sengaja literal).
        $this->assertSame(7, SeminarSubmission::EDIT_APPROVAL_DAYS);
        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $sub->editApprovalExpiresAt()->toDateString()
        );

        // edit kedua tanpa minta izin ulang
        $this->actingAs($this->mhs)
            ->put(route('seminar-submission.update', $sub->fresh()), $this->payload($sub, 'perbaikan-2.pdf'))
            ->assertRedirect();
    }

    public function test_izin_kedaluwarsa_setelah_7_hari_dan_bisa_diajukan_lagi(): void
    {
        $sub = $this->buatSelesai($this->submission());

        // izin disetujui 8 hari lalu → sudah kedaluwarsa
        $sub->forceFill([
            'edit_requested_at' => now()->subDays(8),
            'edit_approved_at' => now()->subDays(8),
            'edit_approved_by' => $this->pembimbing->id,
        ])->save();
        $sub->refresh();

        $this->assertFalse($sub->isEditApproved());
        $this->assertFalse($sub->isUpdatableByStudent());
        $this->assertTrue($sub->bisaMintaEdit(), 'Mahasiswa harus bisa mengajukan izin baru setelah kedaluwarsa.');

        $this->actingAs($this->mhs)->get(route('seminar-submission.edit', $sub))->assertForbidden();

        // mengajukan lagi menghapus izin lama
        $this->actingAs($this->mhs)
            ->post(route('seminar-submission.minta-edit', $sub))
            ->assertRedirect();

        $sub->refresh();
        $this->assertTrue($sub->isMenungguPersetujuanEdit());
        $this->assertNull($sub->edit_approved_at);
    }

    public function test_dosen_menolak_permintaan_edit(): void
    {
        $sub = $this->buatSelesai($this->submission());
        $this->actingAs($this->mhs)->post(route('seminar-submission.minta-edit', $sub));

        $this->actingAs($this->dosenLain)
            ->post(route('seminar-submission.tolak-edit', $sub))
            ->assertForbidden();

        $this->actingAs($this->pembimbing)
            ->post(route('seminar-submission.tolak-edit', $sub))
            ->assertRedirect();

        $sub->refresh();
        $this->assertNull($sub->edit_requested_at);
        $this->assertFalse($sub->isEditApproved());
        $this->assertFalse($sub->isUpdatableByStudent());
        // boleh mengajukan lagi
        $this->assertTrue($sub->bisaMintaEdit());
    }

    public function test_batal_selesai_membuat_mahasiswa_bebas_edit_lagi(): void
    {
        $sub = $this->buatSelesai($this->submission());

        $this->actingAs($this->pembimbing)
            ->post(route('seminar-submission.batal-selesai', $sub))
            ->assertRedirect();

        $sub->refresh();
        $this->assertFalse($sub->isSelesai());
        $this->assertTrue($sub->isUpdatableByStudent());

        $this->actingAs($this->mhs)
            ->put(route('seminar-submission.update', $sub), $this->payload($sub))
            ->assertRedirect();

        // belum selesai → tetap tanpa arsip
        $this->assertSame(0, WorkspaceFile::where('is_archived', true)->count());
    }

    public function test_mahasiswa_bukan_anggota_tidak_bisa_minta_izin(): void
    {
        $sub = $this->buatSelesai($this->submission());

        $orangLain = $this->makeUser('Mhs Lain', 'mahasiswa', ['nim' => 'NIM'.substr(md5(uniqid()), 0, 8)]);

        $this->actingAs($orangLain)
            ->post(route('seminar-submission.minta-edit', $sub))
            ->assertForbidden();
    }

    // ------------------------------------------------ selesai otomatis 2 hari

    public function test_otomatis_selesai_dua_hari_setelah_kegiatan(): void
    {
        $sub = $this->submission();
        $sub->forceFill(['tanggal' => now()->subDays(3)->toDateString()])->save();
        $sub->refresh();

        $this->assertTrue($sub->isSelesai(), 'Lewat 2 hari dari kegiatan → otomatis selesai.');
        $this->assertFalse($sub->isUpdatableByStudent(), 'Setelah selesai, edit perlu izin dosen.');
    }

    public function test_belum_otomatis_sebelum_dua_hari(): void
    {
        $sub = $this->submission();
        $sub->forceFill(['tanggal' => now()->subDay()->toDateString()])->save();
        $sub->refresh();

        $this->assertFalse($sub->isSelesai());
        $this->assertTrue($sub->isUpdatableByStudent());
    }

    public function test_dosen_dapat_menahan_status_otomatis(): void
    {
        $sub = $this->submission();
        $sub->forceFill(['tanggal' => now()->subDays(5)->toDateString()])->save();
        $sub->refresh();
        $this->assertTrue($sub->isSelesai());

        // Tandai "belum selesai" secara manual → otomatis ditahan.
        $this->actingAs($this->pembimbing)
            ->post(route('seminar-submission.batal-selesai', $sub))
            ->assertRedirect();

        $sub->refresh();
        $this->assertFalse($sub->isSelesai());
        $this->assertTrue($sub->selesai_dikecualikan);
        $this->assertSame($this->pembimbing->id, $sub->selesai_dikecualikan_by);
        $this->assertTrue($sub->isUpdatableByStudent());

        // Bebas ganti berkas tanpa arsip.
        $this->actingAs($this->mhs)
            ->put(route('seminar-submission.update', $sub), $this->payload($sub, 'bebas-lagi.pdf'))
            ->assertRedirect();

        $this->assertSame(0, WorkspaceFile::where('is_archived', true)->count());
    }

    public function test_ikuti_otomatis_melepas_penahanan(): void
    {
        $sub = $this->submission();
        $sub->forceFill([
            'tanggal' => now()->subDays(5)->toDateString(),
            'selesai_dikecualikan' => true,
            'selesai_dikecualikan_at' => now(),
            'selesai_dikecualikan_by' => $this->pembimbing->id,
        ])->save();
        $this->assertFalse($sub->fresh()->isSelesai());

        $this->actingAs($this->pembimbing)
            ->post(route('seminar-submission.ikuti-otomatis', $sub))
            ->assertRedirect();

        $sub->refresh();
        $this->assertFalse($sub->selesai_dikecualikan);
        $this->assertTrue($sub->isSelesai());
    }


}
