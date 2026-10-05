<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeminarSubmissionDocumentsTest extends TestCase
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
            'name' => 'Mhs Dokumen',
            'email' => 'mhs-dokumen-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen Dokumen',
            'email' => 'dosen-dokumen-'.uniqid().'@t.test',
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

    private function basePayload(): array
    {
        return [
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan' => UploadedFile::fake()->create('undangan.pdf', 100, 'application/pdf'),
            'undangan_kepada' => ['pembimbing_1'],
            'materi_upload' => UploadedFile::fake()->create('materi.pdf', 100, 'application/pdf'),
        ];
    }

    public function test_store_with_files_and_links(): void
    {
        $response = $this->actingAs($this->mhs)->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
            'dokumen_tambahan' => [
                UploadedFile::fake()->create('lampiran-a.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('data.xlsx', 200, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ],
            'tautan' => ['https://drive.google.com/file/d/abc', 'https://example.com/data'],
        ]));

        $response->assertRedirect();
        $submission = SeminarSubmission::latest('id')->firstOrFail();
        $this->assertSame(2, $submission->documents()->where('type', 'file')->count());
        $this->assertSame(2, $submission->documents()->where('type', 'link')->count());
        $this->assertDatabaseHas('seminar_submission_documents', [
            'seminar_submission_id' => $submission->id,
            'type' => 'link',
            'url' => 'https://drive.google.com/file/d/abc',
        ]);

        // Tampil di halaman detail.
        $this->actingAs($this->mhs)->get(route('seminar-submission.show', $submission))
            ->assertOk()
            ->assertSee('Dokumen Tambahan')
            ->assertSee('lampiran-a.pdf')
            ->assertSee('https://drive.google.com/file/d/abc');
    }

    public function test_store_rejects_more_than_three_files(): void
    {
        $response = $this->actingAs($this->mhs)->from(route('seminar-submission.create', $this->ta))
            ->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
                'dokumen_tambahan' => [
                    UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('d.pdf', 10, 'application/pdf'),
                ],
            ]));

        $response->assertSessionHasErrors('dokumen_tambahan');
        $this->assertSame(0, SeminarSubmission::count());
    }

    public function test_store_rejects_total_over_10mb(): void
    {
        $response = $this->actingAs($this->mhs)->from(route('seminar-submission.create', $this->ta))
            ->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
                'dokumen_tambahan' => [
                    UploadedFile::fake()->create('besar-a.pdf', 6000, 'application/pdf'),
                    UploadedFile::fake()->create('besar-b.pdf', 6000, 'application/pdf'),
                ],
            ]));

        $response->assertSessionHasErrors('dokumen_tambahan');
        $this->assertSame(0, SeminarSubmission::count());
    }

    public function test_store_rejects_disallowed_mime(): void
    {
        $response = $this->actingAs($this->mhs)->from(route('seminar-submission.create', $this->ta))
            ->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
                'dokumen_tambahan' => [UploadedFile::fake()->create('jahat.exe', 10, 'application/x-msdownload')],
            ]));

        $response->assertSessionHasErrors('dokumen_tambahan.0');
        $this->assertSame(0, SeminarSubmission::count());
    }

    public function test_update_can_replace_files_and_links(): void
    {
        $this->actingAs($this->mhs)->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
            'dokumen_tambahan' => [UploadedFile::fake()->create('lama.pdf', 100, 'application/pdf')],
            'tautan' => ['https://example.com/lama'],
        ]));
        $submission = SeminarSubmission::latest('id')->firstOrFail();
        $oldFile = $submission->documents()->where('type', 'file')->firstOrFail();

        $response = $this->actingAs($this->mhs)->put(route('seminar-submission.update', $submission), [
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '10:00',
            'undangan_kepada' => ['pembimbing_1'],
            'hapus_dokumen' => [$oldFile->id],
            'dokumen_tambahan' => [UploadedFile::fake()->create('baru.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
            'tautan' => ['https://example.com/baru'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('seminar_submission_documents', ['id' => $oldFile->id]);
        $this->assertDatabaseHas('seminar_submission_documents', [
            'seminar_submission_id' => $submission->id, 'type' => 'file', 'original_name' => 'baru.docx',
        ]);
        $this->assertDatabaseMissing('seminar_submission_documents', [
            'seminar_submission_id' => $submission->id, 'url' => 'https://example.com/lama',
        ]);
        $this->assertDatabaseHas('seminar_submission_documents', [
            'seminar_submission_id' => $submission->id, 'url' => 'https://example.com/baru',
        ]);
    }

    public function test_download_dokumen_for_owner_and_dosen(): void
    {
        $this->actingAs($this->mhs)->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
            'dokumen_tambahan' => [UploadedFile::fake()->create('unduh.pdf', 100, 'application/pdf')],
        ]));
        $submission = SeminarSubmission::latest('id')->firstOrFail();
        $doc = $submission->documents()->where('type', 'file')->firstOrFail();

        $this->actingAs($this->mhs)->get(route('seminar-submission.dokumen-download', [$submission, $doc]))->assertOk();
        $this->actingAs($this->dosen)->get(route('seminar-submission.dokumen-download', [$submission, $doc]))->assertOk();
    }
}
