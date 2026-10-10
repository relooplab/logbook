<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\User;
use App\Models\WorkspaceFile;
use App\Policies\LogbookEntryPolicy;
use App\Services\StorageUsageService;
use App\Support\Feature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Regression untuk alur "mahasiswa memilih dosen → akses semua menu → disetujui":
 *  1. Mahasiswa fase pending (program pending_approval) bisa mengakses menu & membuat logbook.
 *  2. Kuota sementara 100 MB ditegakkan saat pending.
 *  3. Setelah disetujui, beban kuota dialihkan ke kuota dosen pembimbing.
 *  4. Akun mahasiswa yang pending > 1 bulan dihapus; yang punya program aktif tidak.
 */
class PendingStudentWorkflowTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    /**
     * Buat mahasiswa aktif dengan satu program pending (menunggu persetujuan dosen).
     *
     * @return array{0: \App\Models\User, 1: \App\Models\MahasiswaTa}
     */
    private function pendingStudentWithProgram(string $email, int $daysOld = 0): array
    {
        $mhs = User::create([
            'name' => 'Pending Mahasiswa',
            'email' => $email,
            'password' => bcrypt('password'),
            'registration_status' => 'active',
            'nim' => 'NIM-'.substr($email, 4, 8),
            'whatsapp' => '08123456789',
        ]);
        $mhs->syncRoles(['mahasiswa']);

        $ta = MahasiswaTa::create([
            'user_id' => $mhs->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'pembimbing_1_id' => $this->dosen->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_PENDING_APPROVAL,
            'fase' => 'proposal',
        ]);

        if ($daysOld > 0) {
            $createdAt = now()->subDays($daysOld);
            $ta->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return [$mhs, $ta->fresh()];
    }

    // ------------------------------------------------------------- 1. akses menu

    public function test_pending_mahasiswa_bisa_akses_halaman_logbook(): void
    {
        [$mhs, $ta] = $this->pendingStudentWithProgram('pend-menu@test.com');

        // Policy create memperbolehkan program pending_approval.
        $this->assertTrue(app(LogbookEntryPolicy::class)->create($mhs, $ta));

        $this->actingAs($mhs)
            ->get(route('logbook.create'))
            ->assertOk();
    }

    public function test_pending_mahasiswa_bisa_submit_logbook(): void
    {
        [$mhs, $ta] = $this->pendingStudentWithProgram('pend-logbook@test.com');

        $this->actingAs($mhs)->post(route('logbook.store'), [
            'tanggal_bimbingan' => now()->format('Y-m-d'),
            'topik' => 'Topik bimbingan',
            'progres_kendala' => 'Progres & kendala',
            'riwayat_perbaikan' => [
                ['halaman' => 'Hal. 1', 'komentar_dosen' => 'Perjelas metode', 'perbaikan' => 'Menambah penjelasan metode', 'status' => 'Sudah'],
            ],
            'lampiran' => \Illuminate\Http\UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
            'submit' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('logbook_entries', [
            'mahasiswa_ta_id' => $ta->id,
            'status' => LogbookEntry::STATUS_SUBMITTED,
        ]);
    }

    // -------------------------------------------------------- 2. kuota sementara 100 MB

    public function test_kuota_sementara_mahasiswa_pending_100_mb(): void
    {
        [$mhs, $ta] = $this->pendingStudentWithProgram('pend-quota@test.com');

        $this->assertSame(100, Feature::pendingStudentStorageLimitMb());
        $this->assertSame($mhs->id, $ta->storageChargeTarget()?->id);

        // Sudah terpakai 101 MB (melebihi 100 MB) → upload kecil pun diblokir.
        WorkspaceFile::create([
            'mahasiswa_ta_id' => $ta->id,
            'uploaded_by' => $mhs->id,
            'original_name' => 'big.pdf',
            'path' => 'workspace/'.$ta->id.'/big.pdf',
            'mime_type' => 'application/pdf',
            'size' => 101 * 1048576,
        ]);

        $svc = app(StorageUsageService::class);

        try {
            $svc->withUploadLock($mhs, 1024, fn () => null);
            $this->fail('Upload seharusnya diblokir karena melebihi kuota sementara 100 MB.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('Kuota penyimpanan sementara', $e->getMessage());
        }
    }

    // --------------------------------------------- 3. alih beban kuota ke dosen pasca-approve

    public function test_charge_target_beralih_ke_dosen_setelah_disetujui(): void
    {
        [$mhs, $ta] = $this->pendingStudentWithProgram('pend-charge@test.com');

        // Fase pending → dibebankan ke mahasiswa.
        $this->assertSame($mhs->id, $ta->storageChargeTarget()?->id);

        // Setelah dosen setujui → dialihkan ke dosen pembimbing.
        $ta->update(['status_ta' => MahasiswaTa::STATUS_AKTIF]);

        $this->assertSame($this->dosen->id, $ta->fresh()->storageChargeTarget()?->id);
    }

    // -------------------------------------------- 4. penghapusan pending > 1 bulan

    public function test_delete_inactive_menghapus_pending_diatas_1_bulan(): void
    {
        [$mhsOld, $taOld] = $this->pendingStudentWithProgram('pend-old@test.com', 40);
        [$mhsNew, $taNew] = $this->pendingStudentWithProgram('pend-new@test.com', 0);

        $this->artisan('students:delete-inactive')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'pend-old@test.com']);
        $this->assertDatabaseMissing('mahasiswa_ta', ['id' => $taOld->id]);
        $this->assertDatabaseHas('users', ['email' => 'pend-new@test.com']);
    }

    public function test_delete_inactive_tidak_menghapus_yang_punya_program_aktif(): void
    {
        [$mhs, $taPending] = $this->pendingStudentWithProgram('pend-guard@test.com', 40);

        // Mahasiswa yang sama punya program aktif → jangan dihapus.
        MahasiswaTa::create([
            'user_id' => $mhs->id,
            'jenis' => MahasiswaTa::JENIS_KP,
            'pembimbing_1_id' => $this->dosen->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'pelaksanaan',
        ]);

        $this->artisan('students:delete-inactive')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'pend-guard@test.com']);
    }
}
