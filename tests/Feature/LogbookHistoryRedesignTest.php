<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LogbookHistoryRedesignTest extends TestCase
{
    use DatabaseTransactions;

    private function account(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $suffix = uniqid();
        $user = User::create(['name' => 'History '.$suffix, 'email' => $suffix.'@history.test',
            'password' => bcrypt('password'), 'registration_status' => 'active',
            'nim' => $role === 'mahasiswa' ? 'NIM'.$suffix : null]);
        $user->assignRole($role);

        return $user;
    }

    private function program(User $lecturer): MahasiswaTa
    {
        return MahasiswaTa::create(['user_id' => $this->account('mahasiswa')->id,
            'pembimbing_1_id' => $lecturer->id, 'jenis' => 'ta', 'fase' => 'proposal',
            'status_ta' => 'aktif', 'target_sesi' => 7]);
    }

    private function entry(MahasiswaTa $program, array $attributes = []): LogbookEntry
    {
        return LogbookEntry::create(array_merge(['mahasiswa_ta_id' => $program->id,
            'dosen_id' => $program->pembimbing_1_id, 'jenis' => 'logbook', 'sesi_ke' => (int) $program->entries()->max('sesi_ke') + 1,
            'topik' => 'BAB III METODOLOGI', 'status' => 'submitted',
            'tanggal_bimbingan' => '2026-09-28'], $attributes));
    }

    public function test_student_list_groups_revision_child_under_parent(): void
    {
        $lecturer = $this->account('dosen');
        $program = $this->program($lecturer);
        $parent = $this->entry($program, ['topik' => 'Topik Induk Unik', 'status' => 'revision_in_progress',
            'submitted_at' => now()]);
        $child = $this->entry($program, ['jenis' => 'revisi', 'sesi_ke' => null, 'topik' => 'Jawaban Anak Unik',
            'status' => 'revision_in_progress', 'parent_entry_id' => $parent->id,
            'tanggal_pengiriman' => '2026-10-01']);

        $html = $this->actingAs($program->mahasiswa)->get(route('logbook.index', ['program' => 'ta']))
            ->assertOk()->getContent();

        // Induk selalu di atas anaknya yang menempel, plus tautan lanjutkan draf.
        $this->assertTrue(strpos($html, 'Topik Induk Unik') < strpos($html, 'Jawaban Anak Unik'));
        $this->assertStringContainsString('data-thread="entry-'.$parent->id.'"', $html);
        $this->assertStringContainsString('data-thread-child="'.$parent->id.'"', $html);
        $this->assertStringNotContainsString('jawaban menempel di bawah', $html);
        $this->assertStringContainsString(route('logbook.edit', $child), $html);
    }

    public function test_summary_and_student_options_are_scoped_and_independent_of_filters(): void
    {
        $lecturer = $this->account('dosen');
        $program = $this->program($lecturer);
        $this->entry($program);
        $this->entry($program, ['jenis' => 'revisi', 'status' => 'revisi']);
        $hidden = $this->program($this->account('dosen'));
        $this->entry($hidden);
        $response = $this->actingAs($lecturer)->get(route('logbook.index', ['jenis' => 'revisi']));
        $response->assertOk()->assertViewHas('summary', fn ($s) => (int) $s->total === 2
            && (int) $s->logbook === 1 && (int) $s->revisi === 1
            && (int) $s->pending === 1 && (int) $s->revision_requested === 1)
            ->assertViewHas('students', fn ($students) => $students->pluck('id')->all() === [$program->user_id])
            ->assertViewHas('entries', fn ($entries) => $entries->total() === 1)
            ->assertSee('Pembimbing 1')->assertSee('history-mobile', false)
            ->assertDontSee($hidden->mahasiswa->name);
        // An arbitrary student ID cannot widen the authorized base query.
        $this->get(route('logbook.index', ['mahasiswa_id' => $hidden->user_id]))
            ->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 0)
            ->assertDontSee($hidden->mahasiswa->name);
        $this->get(route('logbook.index', ['keyword' => $hidden->mahasiswa->nim]))
            ->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 0)
            ->assertDontSee($hidden->mahasiswa->name);
    }

    public function test_search_status_student_type_and_existing_date_filters_work_together(): void
    {
        $lecturer = $this->account('dosen');
        $program = $this->program($lecturer);
        $match = $this->entry($program, ['jenis' => 'revisi', 'tanggal_pengiriman' => '2026-09-30',
            'progres_kendala' => 'Unique content needle']);
        $this->entry($program, ['status' => 'approved']);
        $this->entry($this->program($lecturer));
        foreach ([$program->mahasiswa->nim, $program->mahasiswa->name, 'METODOLOGI', 'Unique content needle'] as $keyword) {
            $this->actingAs($lecturer)->get(route('logbook.index', ['keyword' => $keyword,
                'jenis' => 'revisi', 'status' => 'submitted', 'mahasiswa_id' => $program->user_id,
                'date_from' => '2026-09-28', 'date_to' => '2026-09-28']))
                ->assertOk()->assertViewHas('entries', fn ($entries) => $entries->pluck('id')->all() === [$match->id])
                ->assertSee('30 Sep 2026');
        }
        // Filter tanggal memakai tanggal tampil: revisi ikut terfilter lewat
        // tanggal_pengiriman (30 Sep), bukan hilang seperti filter kolom tunggal.
        $this->get(route('logbook.index', ['date_from' => '2026-09-30']))
            ->assertOk()->assertViewHas('entries', fn ($entries) => $entries->pluck('id')->all() === [$match->id]);
    }

    public function test_pagination_tabs_and_empty_states_preserve_query_state(): void
    {
        $lecturer = $this->account('dosen');
        $this->actingAs($lecturer)->get(route('logbook.index'))->assertOk()
            ->assertSee('Belum ada riwayat logbook atau revisi.');
        $program = $this->program($lecturer);
        for ($i = 0; $i < 21; $i++) {
            $this->entry($program);
        }
        $response = $this->get(route('logbook.index', ['keyword' => 'METODOLOGI', 'jenis' => 'logbook']));
        $response->assertOk()->assertViewHas('entries', fn ($entries) => $entries->count() === 20 && $entries->total() === 21)
            ->assertSee('Menampilkan 1–20 dari 21 entri');
        $this->assertStringContainsString('keyword=METODOLOGI', $response->viewData('entries')->nextPageUrl());
        $this->assertStringContainsString('jenis=logbook', $response->viewData('entries')->nextPageUrl());
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//nav[@aria-label="Jenis entri"]/a') as $tab) {
            $url = $tab->getAttribute('href');
            $this->assertStringContainsString('keyword=METODOLOGI', $url);
            $this->assertStringNotContainsString('page=', $url);
            $this->get($url)->assertOk();
        }
        $this->get(route('logbook.index', ['page' => 2]))->assertOk()
            ->assertViewHas('entries', fn ($entries) => $entries->count() === 1);
        foreach ([50, 100] as $size) {
            $this->get(route('logbook.index', ['per_page' => $size]))->assertOk()
                ->assertViewHas('entries', fn ($entries) => $entries->count() === 21 && $entries->perPage() === $size);
        }
        $this->get(route('logbook.index', ['keyword' => 'not-existing']))->assertOk()
            ->assertSee('Tidak ada entri yang cocok dengan pencarian atau filter.');
        $this->getJson(route('logbook.index', ['per_page' => 1000]))->assertUnprocessable();
        $this->getJson(route('logbook.index', ['status' => 'invented']))->assertUnprocessable();
        if ($path = getenv('LOGBOOK_BROWSER_FIXTURE')) {
            file_put_contents($path, $response->getContent());
        }
    }

    public function test_locked_status_uses_exists_projection_without_per_row_queries(): void
    {
        $lecturer = $this->account('dosen');
        $program = $this->program($lecturer);
        $parent = $this->entry($program);
        $this->entry($program, ['jenis' => 'revisi', 'parent_entry_id' => $parent->id]);
        $response = $this->actingAs($lecturer)->get(route('logbook.index'));
        $response->assertOk()->assertSee('Terkunci');
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($response->viewData('entries') as $entry) {
            $this->assertArrayHasKey('revision_children_exists', $entry->getAttributes());
            $entry->statusLabel();
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($parent->fresh()->isLockedByActiveRevision());
    }

    public function test_student_history_keeps_review_indicators_and_actions(): void
    {
        $program = $this->program($this->account('dosen'));
        $entry = $this->entry($program, ['review_opened_at' => now()]);
        $this->actingAs($program->mahasiswa)->get(route('logbook.index'))
            ->assertOk()->assertSee('Sudah')->assertSee(route('logbook.show', $entry), false)
            ->assertSee('+ Entri Revisi')->assertDontSee('history-metrics', false);
    }

    public function test_history_query_count_does_not_grow_with_rows(): void
    {
        $lecturer = $this->account('dosen');
        $program = $this->program($lecturer);
        $this->entry($program);
        $this->actingAs($lecturer);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('logbook.index'))->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 19; $i++) {
            $this->entry($program);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('logbook.index'))->assertOk();
        $expanded = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($baseline + 2, $expanded);
    }
}
