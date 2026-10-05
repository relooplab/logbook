<?php

namespace Tests\Feature;

use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeminarMeetingLinkTest extends TestCase
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
            'name' => 'Mhs Meeting',
            'email' => 'mhs-meeting-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ]);
        $this->mhs->assignRole('mahasiswa');

        $this->dosen = User::create([
            'name' => 'Dosen Meeting',
            'email' => 'dosen-meeting-'.uniqid().'@t.test',
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

    public function test_store_saves_separate_location_and_meeting_link(): void
    {
        $response = $this->actingAs($this->mhs)->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
            'lokasi' => 'Gedung A, Ruang Sidang 2',
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ]));

        $response->assertRedirect();
        $submission = SeminarSubmission::latest('id')->firstOrFail();
        $this->assertSame('Gedung A, Ruang Sidang 2', $submission->lokasi);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $submission->meeting_link);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $submission->effectiveMeetingLink());
    }

    public function test_store_rejects_invalid_meeting_link(): void
    {
        $response = $this->actingAs($this->mhs)->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
            'lokasi' => 'Gedung A',
            'meeting_link' => 'bukan-url',
        ]));

        $response->assertSessionHasErrors('meeting_link');
        $this->assertSame(0, SeminarSubmission::count());
    }

    public function test_update_replaces_meeting_link(): void
    {
        $this->actingAs($this->mhs)->post(route('seminar-submission.store', $this->ta), array_merge($this->basePayload(), [
            'lokasi' => 'Gedung A',
            'meeting_link' => 'https://zoom.us/j/111',
        ]));
        $submission = SeminarSubmission::latest('id')->firstOrFail();

        $response = $this->actingAs($this->mhs)->put(route('seminar-submission.update', $submission), [
            'tanggal' => now()->addWeeks(2)->toDateString(),
            'waktu' => '10:00',
            'lokasi' => 'Gedung B, Ruang 1',
            'meeting_link' => 'https://teams.microsoft.com/l/meetup/222',
            'undangan_kepada' => ['pembimbing_1'],
        ]);

        $response->assertRedirect();
        $submission->refresh();
        $this->assertSame('Gedung B, Ruang 1', $submission->lokasi);
        $this->assertSame('https://teams.microsoft.com/l/meetup/222', $submission->meeting_link);
    }

    public function test_effective_meeting_link_falls_back_to_legacy_lokasi_url(): void
    {
        $submission = new SeminarSubmission([
            'lokasi' => 'Ruang Rapat https://zoom.us/j/123456',
            'meeting_link' => null,
        ]);

        $this->assertSame('https://zoom.us/j/123456', $submission->effectiveMeetingLink());
        $this->assertSame('Ruang Rapat', $submission->locationText());
    }
}
