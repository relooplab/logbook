<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class LogbookThreadNestingTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    public function test_deep_chain_renders_every_level(): void
    {
        // Isolasi penuh: rantai root → anak → cucu milik program sendiri.
        $mhs = \App\Models\User::create([
            'name' => 'Mhs Rantai', 'email' => 'rantai@test.com',
            'password' => bcrypt('password'), 'nim' => 'RANTAI001',
        ]);
        $mhs->assignRole('mahasiswa');
        $ta = \App\Models\MahasiswaTa::create([
            'user_id' => $mhs->id,
            'pembimbing_1_id' => $this->dosen->id,
            'judul_ta' => 'Rantai',
            'jenis' => \App\Models\MahasiswaTa::JENIS_TA,
            'status_ta' => \App\Models\MahasiswaTa::STATUS_AKTIF,
        ]);
        $mk = fn (?int $parentId, string $status, string $jenis, string $topik) => LogbookEntry::create([
            'mahasiswa_ta_id' => $ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => $jenis,
            'status' => $status,
            'sesi_ke' => $jenis === LogbookEntry::JENIS_LOGBOOK ? 1 : null,
            'tanggal_bimbingan' => $jenis === LogbookEntry::JENIS_LOGBOOK ? now()->toDateString() : null,
            'topik' => $topik,
            'parent_entry_id' => $parentId,
        ]);

        $root = $mk(null, LogbookEntry::STATUS_REVISI, LogbookEntry::JENIS_LOGBOOK, 'Akar rantai');
        $child = $mk($root->id, LogbookEntry::STATUS_REVISION_IN_PROGRESS, LogbookEntry::JENIS_REVISI, 'Anak rantai');
        $grandchild = $mk($child->id, LogbookEntry::STATUS_REVISION_IN_PROGRESS, LogbookEntry::JENIS_REVISI, 'Cucu rantai');

        $html = $this->actingAs($mhs)->get(route('logbook.index'))
            ->assertOk()->getContent();

        // Ketiga level tampil sebagai baris (checkbox = bukti baris ter-render).
        foreach ([$root, $child, $grandchild] as $e) {
            $this->assertStringContainsString('value="'.$e->id.'"', $html);
        }
        // Cucu menjorok dua tingkat di bawah anak.
        $this->assertStringContainsString('↳ ↳ Revisi', $html);
        $this->assertStringContainsString('Jawaban untuk entri #'.$child->id, $html);
        $this->assertStringContainsString('Cucu rantai', $html);
    }
}
