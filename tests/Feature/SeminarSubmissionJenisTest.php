<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use App\Notifications\SeminarSubmissionNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeminarSubmissionJenisTest extends TestCase
{
    use DatabaseTransactions;

    private User $mhs;
    private User $dosen;
    private MahasiswaTa $ta;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['mahasiswa', 'dosen'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->mhs = User::create([
            'name' => 'Mhs Jenis',
            'email' => 'mhs-jenis-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen Jenis',
            'email' => 'dosen-jenis-'.uniqid().'@t.test',
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'jenis' => SeminarSubmission::JENIS_SEMINAR_HASIL,
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan' => UploadedFile::fake()->create('undangan.pdf', 100, 'application/pdf'),
            'undangan_kepada' => ['pembimbing_1'],
            'materi_upload' => UploadedFile::fake()->create('materi.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    private function makeSubmission(string $jenis, ?string $fase = null): SeminarSubmission
    {
        if ($fase !== null) {
            $this->ta->update(['fase' => $fase]);
        }

        return SeminarSubmission::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'jenis' => $jenis,
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'seminar-materials/undangan.pdf',
            'undangan_original_name' => 'undangan.pdf',
            'undangan_kepada' => ['pembimbing_1'],
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);
    }

    public function test_mahasiswa_memilih_jenis_dan_fase_maju_mengikuti(): void
    {
        $this->actingAs($this->mhs)
            ->post(route('seminar-submission.store', $this->ta), $this->basePayload())
            ->assertRedirect();

        $sub = SeminarSubmission::where('mahasiswa_ta_id', $this->ta->id)->latest('id')->firstOrFail();
        $this->assertSame(SeminarSubmission::JENIS_SEMINAR_HASIL, $sub->jenis);
        $this->assertSame('seminar_hasil', $this->ta->fresh()->fase);
    }

    public function test_jenis_lama_tidak_menurunkan_fase(): void
    {
        $this->ta->update(['fase' => 'seminar_hasil']);

        $this->actingAs($this->mhs)
            ->post(route('seminar-submission.store', $this->ta), $this->basePayload([
                'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            ]))
            ->assertRedirect();

        $this->assertSame(SeminarSubmission::JENIS_PROPOSAL, SeminarSubmission::latest('id')->first()->jenis);
        $this->assertSame('seminar_hasil', $this->ta->fresh()->fase);
    }

    public function test_jenis_lintas_program_ditolak(): void
    {
        $this->actingAs($this->mhs)
            ->post(route('seminar-submission.store', $this->ta), $this->basePayload([
                'jenis' => SeminarSubmission::JENIS_SEMINAR_KP,
            ]))
            ->assertSessionHasErrors('jenis');

        $this->assertSame(0, SeminarSubmission::where('mahasiswa_ta_id', $this->ta->id)->count());
    }

    public function test_kirim_ganda_jenis_aktif_ditolak(): void
    {
        $this->actingAs($this->mhs)
            ->post(route('seminar-submission.store', $this->ta), $this->basePayload())
            ->assertRedirect();

        $this->actingAs($this->mhs)
            ->post(route('seminar-submission.store', $this->ta), $this->basePayload())
            ->assertSessionHasErrors('jenis');

        $this->assertSame(1, SeminarSubmission::where('mahasiswa_ta_id', $this->ta->id)->count());
    }

    public function test_dosen_bisa_mengoreksi_jenis_dan_fase_mengikuti(): void
    {
        Notification::fake();
        $sub = $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL);

        $this->actingAs($this->dosen)
            ->put(route('seminar-submission.koreksi-jenis', $sub), ['jenis' => SeminarSubmission::JENIS_SEMINAR_HASIL])
            ->assertRedirect();

        $this->assertSame(SeminarSubmission::JENIS_SEMINAR_HASIL, $sub->fresh()->jenis);
        $this->assertSame('seminar_hasil', $this->ta->fresh()->fase);

        Notification::assertSentTo(
            $this->dosen,
            SeminarSubmissionNotification::class,
            fn ($n) => $n->isUpdate === true && in_array('Jenis bahan', array_map(fn ($c) => explode(' (', $c)[0], $n->changedFields), true)
        );
    }

    public function test_dosen_bisa_memundurkan_fase_melalui_koreksi(): void
    {
        $this->ta->update(['fase' => 'seminar_hasil']);
        $sub = $this->makeSubmission(SeminarSubmission::JENIS_SEMINAR_HASIL, 'seminar_hasil');

        $this->actingAs($this->dosen)
            ->put(route('seminar-submission.koreksi-jenis', $sub), ['jenis' => SeminarSubmission::JENIS_PROPOSAL])
            ->assertRedirect();

        $this->assertSame('proposal', $this->ta->fresh()->fase);
    }

    public function test_non_reviewer_tidak_bisa_koreksi_jenis(): void
    {
        $sub = $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL);

        $this->actingAs($this->mhs)
            ->put(route('seminar-submission.koreksi-jenis', $sub), ['jenis' => SeminarSubmission::JENIS_SEMINAR_HASIL])
            ->assertForbidden();
    }

    public function test_form_create_menampilkan_dropdown_jenis(): void
    {
        $html = $this->actingAs($this->mhs)
            ->get(route('seminar-submission.create', $this->ta))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="jenis"', $html);
        $this->assertStringContainsString('Seminar Proposal', $html);
        $this->assertStringContainsString('Anda akan mengajukan:', $html);
    }

    public function test_opsi_jenis_dibatasi_per_program(): void
    {
        $taOpts = array_keys(SeminarSubmission::jenisOptionsFor($this->ta));
        $this->assertEquals(
            [SeminarSubmission::JENIS_PROPOSAL, SeminarSubmission::JENIS_SEMINAR_HASIL, SeminarSubmission::JENIS_SIDANG],
            $taOpts
        );

        $kpTa = MahasiswaTa::create([
            'user_id' => $this->mhs->id,
            'jenis' => MahasiswaTa::JENIS_KP,
            'pembimbing_1_id' => $this->dosen->id,
            'status_ta' => MahasiswaTa::STATUS_AKTIF,
            'fase' => 'seminar_kp',
        ]);
        $this->assertSame(
            [SeminarSubmission::JENIS_SEMINAR_KP],
            array_keys(SeminarSubmission::jenisOptionsFor($kpTa))
        );
        $this->assertSame('seminar_kp', SeminarSubmission::faseFromJenis(SeminarSubmission::JENIS_SEMINAR_KP));
        $this->assertNull(SeminarSubmission::faseFromJenis('tidak-ada'));
    }

    public function test_dashboard_menampilkan_bahan_jenis_lain_dan_tetap_tawarkan_kirim(): void
    {
        // Fase seminar_hasil tapi yang terkirim jenis proposal (kasus koreksi mundur).
        $this->makeSubmission(SeminarSubmission::JENIS_PROPOSAL, 'seminar_hasil');

        $html = $this->actingAs($this->mhs)->get(route('dashboard'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Lihat Seminar Proposal', $html);
        $this->assertStringContainsString('Kirim Bahan', $html);
    }
}
