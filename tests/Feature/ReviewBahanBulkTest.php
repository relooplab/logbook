<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman /review-bahan: info waktu kirim + aksi massal setujui/arsipkan.
 */
class ReviewBahanBulkTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;

    private User $dosen;

    private User $dosenLain;

    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = $this->makeUser('Mhs Review Bahan', 'mahasiswa', ['nim' => 'NIM'.substr(md5(uniqid()), 0, 8)]);
        $this->dosen = $this->makeUser('Dosen Review Bahan', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);
        $this->dosenLain = $this->makeUser('Dosen Lain Bahan', 'dosen', ['nidn' => 'NIDN'.substr(md5(uniqid()), 0, 10)]);

        $this->ta = MahasiswaTa::create([
            'user_id' => $this->mhs->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'pembimbing_1_id' => $this->dosen->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'proposal',
        ]);
    }

    private function makeUser(string $name, string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'whatsapp' => '62812'.random_int(100000, 999999),
            'registration_status' => 'active',
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function entry(array $extra = []): LogbookEntry
    {
        return LogbookEntry::create(array_merge([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => (int) LogbookEntry::where('mahasiswa_ta_id', $this->ta->id)->max('sesi_ke') + 1,
            'topik' => 'Topik bimbingan',
            'progres_kendala' => 'Progres.',
            'tanggal_bimbingan' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now()->subHours(3),
        ], $extra));
    }

    public function test_halaman_menampilkan_waktu_kirim(): void
    {
        $entry = $this->entry(['submitted_at' => now()->subHours(5)]);

        $this->actingAs($this->dosen)
            ->get(route('materials-review.index'))
            ->assertOk()
            ->assertSee('Dikirim '.$entry->submitted_at->format('d M Y H:i'))
            ->assertSee('Pilih semua')
            ->assertSee('Setujui')
            ->assertSee('Arsipkan');
    }

    public function test_bulk_setujui_mengubah_status_semua_entri_terpilih(): void
    {
        $a = $this->entry();
        $b = $this->entry();
        $c = $this->entry();

        $this->actingAs($this->dosen)
            ->post(route('materials-review.bulk'), [
                'aksi' => 'approve',
                'ids' => [$a->id, $b->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(LogbookEntry::STATUS_APPROVED, $a->fresh()->status);
        $this->assertSame(LogbookEntry::STATUS_APPROVED, $b->fresh()->status);
        // tidak terpilih → tidak berubah
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $c->fresh()->status);
    }

    public function test_bulk_arsipkan_menyimpan_alasan_dan_pelaku(): void
    {
        $a = $this->entry();
        $b = $this->entry();

        $this->actingAs($this->dosen)
            ->post(route('materials-review.bulk'), [
                'aksi' => 'archive',
                'ids' => [$a->id, $b->id],
                'archive_reason' => 'Sudah dibahas saat bimbingan',
            ])
            ->assertRedirect();

        foreach ([$a, $b] as $entry) {
            $fresh = $entry->fresh();
            $this->assertSame(LogbookEntry::STATUS_ARCHIVED, $fresh->status);
            $this->assertSame($this->dosen->id, (int) $fresh->archived_by);
            $this->assertSame('Sudah dibahas saat bimbingan', $fresh->archive_reason);
            $this->assertNotNull($fresh->archived_at);
        }
    }

    public function test_bulk_melewati_entri_yang_bukan_kewenangannya(): void
    {
        $milikSaya = $this->entry();

        // TA lain dengan pembimbing dosen lain
        $taLain = MahasiswaTa::create([
            'user_id' => $this->makeUser('Mhs Lain Bahan', 'mahasiswa', ['nim' => 'NIM'.substr(md5(uniqid()), 0, 8)])->id,
            'jenis' => MahasiswaTa::JENIS_TA,
            'pembimbing_1_id' => $this->dosenLain->id,
            'target_sesi' => 7,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'proposal',
        ]);
        $milikOrang = LogbookEntry::create([
            'mahasiswa_ta_id' => $taLain->id,
            'dosen_id' => $this->dosenLain->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 1,
            'topik' => 'Bukan kewenangan',
            'tanggal_bimbingan' => now()->toDateString(),
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->dosen)
            ->post(route('materials-review.bulk'), [
                'aksi' => 'approve',
                'ids' => [$milikSaya->id, $milikOrang->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(LogbookEntry::STATUS_APPROVED, $milikSaya->fresh()->status);
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $milikOrang->fresh()->status, 'Entri orang lain tidak boleh berubah.');
    }

    public function test_bulk_melewati_entri_yang_sudah_diproses(): void
    {
        $sudah = $this->entry(['status' => LogbookEntry::STATUS_APPROVED, 'reviewed_at' => now()]);
        $baru = $this->entry();

        $this->actingAs($this->dosen)
            ->post(route('materials-review.bulk'), [
                'aksi' => 'approve',
                'ids' => [$sudah->id, $baru->id],
            ])
            ->assertRedirect();

        $this->assertSame(LogbookEntry::STATUS_APPROVED, $baru->fresh()->status);
    }

    public function test_mahasiswa_tidak_boleh_aksi_massal(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->mhs)
            ->post(route('materials-review.bulk'), ['aksi' => 'approve', 'ids' => [$entry->id]])
            ->assertForbidden();

        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $entry->fresh()->status);
    }

    public function test_validasi_aksi_dan_ids(): void
    {
        $this->actingAs($this->dosen)
            ->post(route('materials-review.bulk'), ['aksi' => 'hapus', 'ids' => [1]])
            ->assertSessionHasErrors('aksi');

        $this->actingAs($this->dosen)
            ->post(route('materials-review.bulk'), ['aksi' => 'approve', 'ids' => []])
            ->assertSessionHasErrors('ids');
    }
}
