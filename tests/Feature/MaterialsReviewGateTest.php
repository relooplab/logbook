<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Antrean review bahan adalah pengingat, bukan penghalang dashboard dosen.
 */
class MaterialsReviewGateTest extends TestCase
{
    use DatabaseTransactions;

    private User $dosen;

    private User $mhs;

    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = User::create([
            'name' => 'Mhs Review',
            'email' => 'mhs-review-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen Review',
            'email' => 'dosen-review-'.uniqid().'@t.test',
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

    public function test_dashboard_tetap_terbuka_saat_ada_logbook_belum_ditinjau(): void
    {
        $pending = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 1,
            'dosen_id' => $this->dosen->id,
            'topik' => 'Bimbingan 1',
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->dosen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 bahan menunggu review')
            ->assertSeeInOrder([
                route('logbook.index'), 'Riwayat Bimbingan',
                route('materials-review.index'), 'Antrean Review',
            ]);

        // Halaman antrean menampilkan logbook pending.
        $this->actingAs($this->dosen)
            ->get(route('materials-review.index'))
            ->assertOk()
            ->assertSee('Bimbingan 1');
    }

    public function test_antrean_berjalan_fifo_dari_yang_terlama(): void
    {
        $lama = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 11,
            'dosen_id' => $this->dosen->id,
            'topik' => 'Urutan lama '.uniqid(),
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now()->subDays(3),
        ]);
        $baru = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 12,
            'dosen_id' => $this->dosen->id,
            'topik' => 'Urutan baru '.uniqid(),
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $ids = app(\App\Services\MaterialsReviewQueue::class)->pendingLogbook($this->dosen)->pluck('id')->all();
        $this->assertSame([$lama->id, $baru->id], array_values(array_intersect($ids, [$lama->id, $baru->id])));

        $semLama = SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            'tanggal' => now()->addDays(7),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);
        $semLama->update(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $semBaru = SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_SEMINAR_HASIL,
            'tanggal' => now()->addDays(8),
            'waktu' => '10:00',
            'undangan_path' => 'seminar-materials/undangan2.pdf',
            'undangan_original_name' => 'undangan2.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);

        $semIds = app(\App\Services\MaterialsReviewQueue::class)->pendingSeminar($this->dosen)->pluck('id')->all();
        $this->assertSame([$semLama->id, $semBaru->id], array_values(array_intersect($semIds, [$semLama->id, $semBaru->id])));

        // Urutan tampil di halaman ikut FIFO.
        $html = $this->actingAs($this->dosen)->get(route('materials-review.index'))
            ->assertOk()->getContent();
        $this->assertLessThan(strpos($html, $baru->topik), strpos($html, $lama->topik));
    }

    public function test_dashboard_tetap_terbuka_saat_ada_seminar_belum_dibaca(): void
    {
        SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            'tanggal' => now()->addDays(7),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);

        $this->actingAs($this->dosen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 bahan menunggu review');

        $this->actingAs($this->dosen)
            ->get(route('materials-review.index'))
            ->assertOk()
            ->assertSee('Seminar Proposal');
    }

    public function test_materials_review_links_student_name_to_program_detail(): void
    {
        LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 1,
            'dosen_id' => $this->dosen->id,
            'topik' => 'Bimbingan 1',
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->dosen)->get(route('materials-review.index'))
            ->assertOk()
            ->assertSee(route('mahasiswa-ta.show', $this->ta), false)
            ->assertSee('Mhs Review');
    }

    public function test_materials_review_links_seminar_student_name_to_program_detail(): void
    {
        SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            'tanggal' => now()->addDays(7),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);

        $this->actingAs($this->dosen)->get(route('materials-review.index'))
            ->assertOk()
            ->assertSee(route('mahasiswa-ta.show', $this->ta), false)
            ->assertSee('Mhs Review');
    }

    public function test_dashboard_normal_saat_tidak_ada_bahan_pending(): void
    {
        $this->actingAs($this->dosen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('bahan menunggu review');
    }

    public function test_bahan_sudah_ditinjau_tidak_memicu_redirect(): void
    {
        // Logbook sudah disetujui -> tidak pending lagi.
        LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 1,
            'dosen_id' => $this->dosen->id,
            'topik' => 'Sudah disetujui',
            'status' => LogbookEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->dosen)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
