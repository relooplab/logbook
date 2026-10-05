<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class LogbookProgramContextTest extends AuditSmokeTest
{
    private User $dosenKp;
    private MahasiswaTa $kp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dosenKp = User::firstOrCreate(['email' => 'audit-dosen-kp@test.com'], ['name' => 'Audit Dosen KP', 'password' => bcrypt('password')]);
        if (!$this->dosenKp->hasRole('dosen')) $this->dosenKp->assignRole('dosen');

        $this->kp = MahasiswaTa::firstOrCreate(
            ['user_id' => $this->mhs->id, 'jenis' => MahasiswaTa::JENIS_KP],
            ['pembimbing_1_id' => $this->dosenKp->id, 'tempat_kp' => 'PT. Audit', 'target_sesi' => 7, 'status_ta' => MahasiswaTa::STATUS_AKTIF, 'fase' => 'pelaksanaan']
        );
    }

    public function test_logbook_store_targets_selected_program_not_active_one(): void
    {
        $response = $this->actingAs($this->mhs)->post(route('logbook.store'), [
            'program' => 'kp',
            'addressed_dosen_id' => $this->dosenKp->id,
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Topik KP',
            'progres_kendala' => 'Progres KP',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('logbook_entries', [
            'mahasiswa_ta_id' => $this->kp->id,
            'dosen_id' => $this->dosenKp->id,
            'topik' => 'Topik KP',
        ]);
    }

    public function test_logbook_store_rejects_dosen_of_other_program(): void
    {
        $response = $this->actingAs($this->mhs)->from(route('logbook.create', ['program' => 'kp']))->post(route('logbook.store'), [
            'program' => 'kp',
            'addressed_dosen_id' => $this->dosen->id, // pembimbing TA, bukan KP
            'tanggal_bimbingan' => now()->toDateString(),
            'topik' => 'Topik',
            'progres_kendala' => 'Progres',
        ]);

        $response->assertSessionHasErrors('addressed_dosen_id');
    }

    public function test_revisi_draft_accepts_parent_of_selected_program(): void
    {
        $parent = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->kp->id,
            'dosen_id' => $this->dosenKp->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'topik' => 'Topik KP',
            'progres_kendala' => 'Progres',
            'tanggal_bimbingan' => now(),
            'status' => LogbookEntry::STATUS_REVISI,
        ]);

        $response = $this->actingAs($this->mhs)->post(route('logbook.store-revisi'), [
            'program' => 'kp',
            'parent_entry_id' => $parent->id,
            'tanggal_pengiriman' => now()->toDateString(),
            'riwayat_perbaikan' => [
                ['halaman' => 'Bab 1', 'komentar_dosen' => 'Perbaiki.', 'perbaikan' => 'Sudah.', 'status' => 'Sudah'],
            ],
            'lampiran' => UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('logbook_entries', [
            'parent_entry_id' => $parent->id,
            'mahasiswa_ta_id' => $this->kp->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
        ]);
    }

    public function test_create_revisi_follows_parent_program_even_with_wrong_program_query(): void
    {
        $parent = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'topik' => 'Topik TA',
            'progres_kendala' => 'Progres',
            'tanggal_bimbingan' => now(),
            'status' => LogbookEntry::STATUS_REVISI,
        ]);

        $response = $this->actingAs($this->mhs)->get(route('logbook.create-revisi', [
            'parent_entry_id' => $parent->id,
            'program' => 'kp', // sengaja salah: harus tetap jatuh ke program TA milik parent
        ]));

        $response->assertOk();
        $this->assertSame($this->ta->id, $response->viewData('ta')->id);
    }
}

