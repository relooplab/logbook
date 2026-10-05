<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\Message;
use App\Models\SeminarSubmission;
use App\Models\User;
use App\Models\WorkspaceFile;
use App\Services\StorageUsageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    private function account(string $role, string $label): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $suffix = uniqid();
        $user = User::create([
            'name' => $label.' '.$suffix, 'email' => $suffix.'@chat.test',
            'password' => bcrypt('password'), 'registration_status' => 'active',
            'nim' => $role === 'mahasiswa' ? 'NIM'.$suffix : null,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function program(User $student, User $lecturer, string $role = 'pembimbing_1_id'): MahasiswaTa
    {
        return MahasiswaTa::create(['user_id' => $student->id, 'jenis' => 'ta',
            'fase' => 'proposal', 'status_ta' => 'aktif', 'target_sesi' => 7,
            'judul_ta' => 'Judul rahasia panjang untuk program '.$student->id, $role => $lecturer->id]);
    }

    private function thread(User $student, User $lecturer, ?MahasiswaTa $program): Conversation
    {
        return Conversation::create([
            'user_one_id' => min($student->id, $lecturer->id),
            'user_two_id' => max($student->id, $lecturer->id),
            'mahasiswa_ta_id' => $program?->id,
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4 chat workspace test');
    }

    public function test_student_supervisor_and_examiner_can_upload_multiple_files_without_duplicate_storage(): void
    {
        Storage::fake('local');
        $student = $this->account('mahasiswa', 'StudentUpload');
        $supervisor = $this->account('dosen', 'SupervisorUpload');
        $examiner = $this->account('dosen', 'ExaminerUpload');
        $program = $this->program($student, $supervisor);
        $program->update(['penguji_1_id' => $examiner->id]);

        $supervisorThread = $this->thread($student, $supervisor, $program);
        $examinerThread = $this->thread($student, $examiner, $program);
        foreach ([[$student, $supervisor, $supervisorThread], [$supervisor, $student, $supervisorThread], [$examiner, $student, $examinerThread]] as $index => [$sender, $recipient, $thread]) {
            $this->actingAs($sender)->get(route('chat.show', $thread))->assertOk()
                ->assertSee('Unggah file baru ke workspace mahasiswa')
                ->assertSee('Sematkan referensi karya (bukan unggah file)')
                ->assertSee('bookmark_add')->assertSee('upload_file');
            $first = "chat-{$index}-a.pdf";
            $second = "chat-{$index}-b.pdf";
            $this->post(route('chat.store', $thread), [
                'files' => [$this->pdf($first), $this->pdf($second)],
            ])->assertRedirect(route('chat.show', $thread));

            $message = Message::where('conversation_id', $thread->id)->orderByDesc('id')->firstOrFail();
            $this->assertSame('', $message->body);
            $this->assertSame(2, $message->workspaceFiles()->count());
            foreach ($message->workspaceFiles as $attachment) {
                $this->assertSame($sender->id, $attachment->file->uploaded_by);
                $this->assertSame($program->id, $attachment->file->mahasiswa_ta_id);
                Storage::disk('local')->assertExists($attachment->file->path);
            }
            $this->actingAs($recipient)->get(route('chat.show', $thread))->assertOk()
                ->assertSee($first)->assertSee($second);
        }
        $this->assertSame(6, WorkspaceFile::where('mahasiswa_ta_id', $program->id)->count());
        $this->assertSame(6 * strlen('%PDF-1.4 chat workspace test'), app(StorageUsageService::class)->totalBytes($student));
    }

    public function test_deleted_workspace_upload_remains_as_non_downloadable_chat_entry(): void
    {
        Storage::fake('local');
        $student = $this->account('mahasiswa', 'DeleteStudent');
        $lecturer = $this->account('dosen', 'DeleteLecturer');
        $program = $this->program($student, $lecturer);
        $thread = $this->thread($student, $lecturer, $program);
        $this->actingAs($student)->post(route('chat.store', $thread), [
            'body' => 'Silakan periksa', 'files' => [$this->pdf('draft-hapus.pdf')],
        ])->assertRedirect();
        $attachment = Message::where('conversation_id', $thread->id)->firstOrFail()->workspaceFiles()->firstOrFail();
        $file = $attachment->file;
        $url = route('workspace.preview', $file);
        $this->actingAs($lecturer)->get(route('chat.show', $thread))->assertOk()->assertSee($url);
        $this->actingAs($student)->delete(route('workspace.destroy', $file))->assertRedirect();
        $this->assertNull($attachment->fresh()->file);
        Storage::disk('local')->assertMissing($file->path);
        $this->actingAs($lecturer)->get(route('chat.show', $thread))->assertOk()
            ->assertSee('draft-hapus.pdf · File telah dihapus')->assertDontSee($url)
            ->assertSee('Silakan periksa');
    }

    public function test_existing_workspace_reference_is_linked_once_and_can_show_deleted_placeholder(): void
    {
        Storage::fake('local');
        $student = $this->account('mahasiswa', 'ReferenceStudent');
        $lecturer = $this->account('dosen', 'ReferenceLecturer');
        $program = $this->program($student, $lecturer);
        $thread = $this->thread($student, $lecturer, $program);
        $file = WorkspaceFile::create([
            'mahasiswa_ta_id' => $program->id, 'uploaded_by' => $student->id,
            'original_name' => 'existing.pdf', 'path' => 'workspace/existing.pdf',
            'mime_type' => 'application/pdf', 'size' => 25,
        ]);
        Storage::disk('local')->put($file->path, '%PDF-1.4 existing');
        $this->actingAs($student)->post(route('chat.store', $thread), [
            'body' => 'Referensi lama', 'attachable_type' => 'workspace', 'attachable_id' => $file->id,
        ])->assertRedirect();
        $message = Message::where('conversation_id', $thread->id)->firstOrFail();
        $this->assertSame($file->id, $message->attachable_id);
        $this->assertSame(1, $message->workspaceFiles()->count());
        $this->assertSame(1, WorkspaceFile::where('mahasiswa_ta_id', $program->id)->count());
        $this->actingAs($lecturer)->get(route('chat.show', $thread))->assertOk()->assertSee('existing.pdf');
        $this->actingAs($student)->delete(route('workspace.destroy', $file))->assertRedirect();
        $this->actingAs($lecturer)->get(route('chat.show', $thread))->assertOk()
            ->assertSee('existing.pdf · File telah dihapus');
    }

    public function test_upload_rejects_unlinked_conversations_nonparticipants_and_pending_programs(): void
    {
        Storage::fake('local');
        $student = $this->account('mahasiswa', 'AccessStudent');
        $lecturer = $this->account('dosen', 'AccessLecturer');
        $outsider = $this->account('mahasiswa', 'AccessOutsider');
        $program = $this->program($student, $lecturer);
        $thread = $this->thread($student, $lecturer, $program);
        $unlinked = $this->thread($student, $lecturer, null);
        $this->actingAs($outsider)->post(route('chat.store', $thread), [
            'files' => [$this->pdf('outside.pdf')],
        ])->assertForbidden();
        $this->actingAs($student)->post(route('chat.store', $unlinked), [
            'files' => [$this->pdf('unlinked.pdf')],
        ])->assertForbidden();
        $foreignStudent = $this->account('mahasiswa', 'ForeignStudent');
        $foreignProgram = $this->program($foreignStudent, $lecturer);
        $crossProgramThread = $this->thread($student, $lecturer, $foreignProgram);
        $this->post(route('chat.store', $crossProgramThread), [
            'files' => [$this->pdf('cross-program.pdf')],
        ])->assertForbidden();
        $program->update(['status_ta' => MahasiswaTa::STATUS_PENDING_APPROVAL]);
        $this->get(route('chat.show', $thread))->assertOk()->assertDontSee('id="upload-btn"', false);
        $this->post(route('chat.store', $thread), [
            'files' => [$this->pdf('pending.pdf')],
        ])->assertForbidden();
        $this->assertSame(0, WorkspaceFile::where('mahasiswa_ta_id', $program->id)->count());
        $this->assertSame(0, WorkspaceFile::where('mahasiswa_ta_id', $foreignProgram->id)->count());
    }

    public function test_upload_validates_file_count_type_size_and_empty_message(): void
    {
        Storage::fake('local');
        $student = $this->account('mahasiswa', 'ValidateStudent');
        $lecturer = $this->account('dosen', 'ValidateLecturer');
        $program = $this->program($student, $lecturer);
        $thread = $this->thread($student, $lecturer, $program);
        $this->actingAs($student)->post(route('chat.store', $thread), [])->assertSessionHasErrors('body');
        $this->post(route('chat.store', $thread), [
            'files' => array_map(fn ($i) => $this->pdf("many-{$i}.pdf"), range(1, 6)),
        ])->assertSessionHasErrors('files');
        $this->post(route('chat.store', $thread), [
            'files' => [UploadedFile::fake()->create('unsafe.exe', 1)],
        ])->assertSessionHasErrors('files.0');
        $this->post(route('chat.store', $thread), [
            'files' => [UploadedFile::fake()->create('large.pdf', 51201, 'application/pdf')],
        ])->assertSessionHasErrors('files.0');
        $this->assertSame(0, $thread->messages()->count());
        $this->assertSame(0, WorkspaceFile::where('mahasiswa_ta_id', $program->id)->count());
    }

    public function test_quota_failure_creates_neither_message_nor_workspace_file(): void
    {
        Storage::fake('local');
        $student = $this->account('mahasiswa', 'QuotaStudent');
        $lecturer = $this->account('dosen', 'QuotaLecturer');
        $program = $this->program($student, $lecturer);
        $thread = $this->thread($student, $lecturer, $program);
        WorkspaceFile::create([
            'mahasiswa_ta_id' => $program->id, 'uploaded_by' => $student->id,
            'original_name' => 'existing.pdf', 'path' => 'workspace/existing.pdf',
            'mime_type' => 'application/pdf', 'size' => 100000000000,
        ]);
        // Existing Workspace usage is included in the same quota check.
        $this->actingAs($student)->post(route('chat.store', $thread), [
            'files' => [UploadedFile::fake()->create('quota.pdf', 51200, 'application/pdf')],
        ])->assertStatus(422);
        $this->assertSame(0, $thread->messages()->count());
        $this->assertSame(1, WorkspaceFile::where('mahasiswa_ta_id', $program->id)->count());
    }

    public function test_lecturer_list_has_authorized_contacts_filters_search_and_empty_thread(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'VisibleStudent');
        $outsider = $this->account('mahasiswa', 'HiddenStudent');
        $program = $this->program($student, $lecturer, 'penguji_1_id');

        $this->actingAs($lecturer)->get(route('chat.index'))
            ->assertOk()->assertSee('Pilih percakapan')->assertSee($student->name)
            ->assertSee('Penguji 1')->assertDontSee($outsider->name)->assertDontSee($program->judul_ta)
            ->assertSee(route('chat.start', ['user' => $student->id, 'ta' => $program->id]));
        $this->get(route('chat.index', ['filter' => 'dibimbing']))->assertOk()->assertDontSee($student->name);
        $this->get(route('chat.index', ['filter' => 'diuji', 'search' => $student->nim]))->assertOk()->assertSee($student->name);
        $this->get(route('chat.index', ['search' => 'no-match-chat-xyz']))->assertOk()->assertSee('Tidak ada mahasiswa atau percakapan yang cocok.');

        $this->get(route('chat.start', ['user' => $student->id, 'ta' => $program->id]))->assertRedirect();
        $thread = Conversation::where('mahasiswa_ta_id', $program->id)->firstOrFail();
        $this->get(route('chat.show', $thread))->assertOk()->assertSee('Belum ada percakapan.')
            ->assertSee('Kembali ke daftar percakapan')->assertSee('Tulis pesan...')
            ->assertSee('Sematkan referensi karya (bukan unggah file)');
        $this->get(route('chat.show', ['conversation' => $thread, 'search' => $student->nim]))
            ->assertOk()->assertSee('action="'.route('chat.index').'"', false)
            ->assertSee(route('chat.index', ['filter' => 'diuji', 'search' => $student->nim]));
    }

    public function test_student_can_start_with_assigned_lecturer_and_cannot_open_or_start_outside_relationship(): void
    {
        $lecturer = $this->account('dosen', 'AssignedLecturer');
        $student = $this->account('mahasiswa', 'Student');
        $other = $this->account('mahasiswa', 'OtherStudent');
        $program = $this->program($student, $lecturer);
        $foreign = $this->program($other, $lecturer);

        $this->actingAs($student)->get(route('chat.index'))->assertOk()->assertSee($lecturer->name)
            ->assertSee(route('chat.start', ['user' => $lecturer->id, 'ta' => $program->id]));
        $this->get(route('chat.start', ['user' => $lecturer->id, 'ta' => $foreign->id]))->assertForbidden();
        $this->get(route('chat.start', ['user' => $lecturer->id, 'ta' => $program->id]))->assertRedirect();
        $thread = Conversation::where('mahasiswa_ta_id', $program->id)->firstOrFail();
        $this->get(route('chat.show', $thread))->assertOk();
        $this->post(route('chat.store', $thread), ['body' => 'Halo dosen'])->assertRedirect(route('chat.show', $thread));
        $this->assertDatabaseHas('messages', ['conversation_id' => $thread->id, 'body' => 'Halo dosen']);
        $this->actingAs($other)->get(route('chat.show', $thread))->assertForbidden();
        $this->post(route('chat.store', $thread), ['body' => 'Tidak boleh'])->assertForbidden();
    }

    public function test_unread_preview_read_transition_and_edit_authorization(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'Student');
        $program = $this->program($student, $lecturer);
        $thread = Conversation::create(['user_one_id' => min($student->id, $lecturer->id),
            'user_two_id' => max($student->id, $lecturer->id), 'mahasiswa_ta_id' => $program->id]);
        $message = Message::create(['conversation_id' => $thread->id, 'sender_id' => $student->id, 'body' => 'Pesan belum dibaca']);

        $this->actingAs($lecturer)->get(route('chat.index', ['filter' => 'belum-dibaca']))
            ->assertOk()->assertSee('Pesan belum dibaca')->assertSee('1 pesan belum dibaca');
        $this->get(route('chat.show', $thread))->assertOk()->assertSee('Pesan belum dibaca');
        $this->assertNotNull($message->fresh()->read_at);
        $this->get(route('chat.index', ['filter' => 'belum-dibaca']))->assertOk()->assertDontSee($student->name);
        $this->put(route('chat.update', [$thread, $message]), ['body' => 'Not mine'])->assertForbidden();
    }

    public function test_multiple_roles_share_one_contact_and_history_is_bounded(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'DoubleRoleStudent');
        $program = $this->program($student, $lecturer);
        $program->update(['penguji_2_id' => $lecturer->id]);

        $this->actingAs($lecturer)->get(route('chat.index'))
            ->assertOk()->assertViewHas('counts', fn ($counts) => $counts['semua'] === 1
                && $counts['dibimbing'] === 1 && $counts['diuji'] === 1);
        $thread = Conversation::create(['user_one_id' => min($student->id, $lecturer->id),
            'user_two_id' => max($student->id, $lecturer->id), 'mahasiswa_ta_id' => $program->id]);
        for ($i = 0; $i < 102; $i++) {
            Message::create(['conversation_id' => $thread->id, 'sender_id' => $student->id,
                'body' => 'Pesan ke-'.$i]);
        }

        $this->get(route('chat.show', $thread))->assertOk()
            ->assertViewHas('messages', fn ($messages) => $messages->count() === 100)
            ->assertSee('Pesan ke-101')->assertDontSee('Pesan ke-0</p>', false)
            ->assertSee('Lihat pesan sebelumnya');
        $this->get(route('chat.show', ['conversation' => $thread, 'before' => $thread->messages()->orderByDesc('id')->skip(99)->first()->id]))
            ->assertOk()->assertSee('Pesan ke-0')->assertSee('Kembali ke pesan terbaru');
        $this->get(route('chat.index'))->assertOk()
            ->assertViewHas('counts', fn ($counts) => $counts['semua'] === 1);
    }

    public function test_attachment_reference_picker_remains_participant_only(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'Student');
        $outsider = $this->account('mahasiswa', 'Outsider');
        $program = $this->program($student, $lecturer);
        $thread = Conversation::create(['user_one_id' => min($student->id, $lecturer->id),
            'user_two_id' => max($student->id, $lecturer->id), 'mahasiswa_ta_id' => $program->id]);

        $this->actingAs($lecturer)->postJson(route('chat.attach-options', $thread))->assertOk()->assertJsonStructure(['categories']);
        $this->actingAs($outsider)->postJson(route('chat.attach-options', $thread))->assertForbidden();
        $this->actingAs($student)->post(route('chat.store', $thread), ['body' => 'Pesan',
            'attachable_type' => 'workspace', 'attachable_id' => 99999999])->assertRedirect();
        $this->assertDatabaseHas('messages', ['conversation_id' => $thread->id,
            'body' => 'Pesan', 'attachable_type' => null, 'attachable_id' => null]);
    }

    public function test_entry_context_prefills_reference_without_sending_and_can_be_sent_to_correct_thread(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'Student');
        $program = $this->program($student, $lecturer);
        foreach (['logbook', 'revisi'] as $kind) {
            $entry = LogbookEntry::create([
                'mahasiswa_ta_id' => $program->id, 'dosen_id' => $lecturer->id,
                'jenis' => $kind, 'sesi_ke' => $kind === 'revisi' ? 3 : 2,
                'revision_round' => $kind === 'revisi' ? 1 : null,
                'status' => LogbookEntry::STATUS_SUBMITTED,
            ]);
            $this->actingAs($student)->get(route('chat.start', ['user' => $lecturer->id, 'ta' => $program->id, 'entry' => $entry->id]))
                ->assertRedirect();
            $thread = Conversation::where('mahasiswa_ta_id', $program->id)->firstOrFail();
            $this->get(route('chat.show', ['conversation' => $thread, 'entry' => $entry->id]))
                ->assertOk()->assertSee('value="logbook"', false)->assertSee('value="'.$entry->id.'"', false)
                ->assertSee($kind === 'revisi' ? 'Referensi: Revisi r1' : 'Referensi: Entri #2')
                ->assertSee('Hapus referensi terpilih');
            $this->assertSame($kind === 'revisi' ? 1 : 0, $thread->messages()->count());
            $this->post(route('chat.store', $thread), [
                'body' => 'Mari bahas entri ini', 'attachable_type' => 'logbook', 'attachable_id' => $entry->id,
            ])->assertRedirect();
            $this->assertDatabaseHas('messages', [
                'conversation_id' => $thread->id, 'attachable_type' => LogbookEntry::class, 'attachable_id' => $entry->id,
            ]);
        }
    }

    public function test_entry_context_rejects_cross_program_and_nonparticipants(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'Student');
        $outsider = $this->account('mahasiswa', 'Outsider');
        $program = $this->program($student, $lecturer);
        $foreign = $this->program($outsider, $lecturer);
        $entry = LogbookEntry::create(['mahasiswa_ta_id' => $foreign->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK, 'status' => LogbookEntry::STATUS_SUBMITTED]);
        $thread = Conversation::create(['user_one_id' => min($student->id, $lecturer->id),
            'user_two_id' => max($student->id, $lecturer->id), 'mahasiswa_ta_id' => $program->id]);

        $this->actingAs($student)->get(route('chat.start', ['user' => $lecturer->id, 'ta' => $program->id, 'entry' => $entry->id]))
            ->assertForbidden();
        $this->get(route('chat.show', ['conversation' => $thread, 'entry' => $entry->id]))->assertForbidden();
        $this->actingAs($lecturer)->post(route('chat.store', $thread), ['body' => 'Salah program',
            'attachable_type' => 'logbook', 'attachable_id' => $entry->id])->assertForbidden();
        $this->assertDatabaseMissing('messages', ['conversation_id' => $thread->id, 'body' => 'Salah program']);
        $this->actingAs($outsider)->get(route('chat.show', ['conversation' => $thread, 'entry' => $entry->id]))
            ->assertForbidden();
    }

    public function test_seminar_note_reply_prefills_quote_and_reference(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'Student');
        $program = $this->program($student, $lecturer);
        $submission = SeminarSubmission::create([
            'mahasiswa_ta_id' => $program->id,
            'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'x.pdf',
            'undangan_original_name' => 'x.pdf',
            'undangan_sebagai' => 'pembimbing_1',
            'catatan_hardcopy' => '-',
            'catatan_keterangan' => 'Mohon review materi Bab 1 dan 2.',
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);

        // chat.start meneruskan konteks seminar ke chat.show.
        $this->actingAs($lecturer)
            ->get(route('chat.start', ['user' => $student->id, 'ta' => $program->id, 'seminar' => $submission->id, 'quote' => 'catatan']))
            ->assertRedirect();
        $thread = Conversation::where('mahasiswa_ta_id', $program->id)->firstOrFail();

        // Komposer terisi kutipan catatan + referensi seminar.
        $this->get(route('chat.show', ['conversation' => $thread, 'seminar' => $submission->id, 'quote' => 'catatan']))
            ->assertOk()
            ->assertSee('value="seminar"', false)
            ->assertSee('value="'.$submission->id.'"', false)
            ->assertSee('Referensi: Seminar · Seminar Proposal', false)
            ->assertSee('&gt; Mohon review materi Bab 1 dan 2.', false);
        $this->assertSame(0, $thread->messages()->count());

        // Pesan terkirim dengan lampiran submission.
        $this->post(route('chat.store', $thread), [
            'body' => "> Mohon review materi Bab 1 dan 2.\n\nSiap, akan saya review besok.",
            'attachable_type' => 'seminar',
            'attachable_id' => $submission->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $thread->id,
            'attachable_type' => SeminarSubmission::class,
            'attachable_id' => $submission->id,
        ]);
    }

    public function test_seminar_context_rejects_cross_program_and_nonparticipants(): void
    {
        $lecturer = $this->account('dosen', 'Lecturer');
        $student = $this->account('mahasiswa', 'Student');
        $outsider = $this->account('mahasiswa', 'Outsider');
        $program = $this->program($student, $lecturer);
        $foreign = $this->program($outsider, $lecturer);
        $submission = SeminarSubmission::create([
            'mahasiswa_ta_id' => $foreign->id,
            'jenis' => SeminarSubmission::JENIS_PROPOSAL,
            'tanggal' => now()->addWeek()->toDateString(),
            'waktu' => '09:00',
            'undangan_path' => 'x.pdf',
            'undangan_original_name' => 'x.pdf',
            'undangan_sebagai' => 'pembimbing_1',
            'catatan_hardcopy' => '-',
            'catatan_keterangan' => 'Catatan asing.',
            'status' => SeminarSubmission::STATUS_SUBMITTED,
        ]);
        $thread = Conversation::create(['user_one_id' => min($student->id, $lecturer->id),
            'user_two_id' => max($student->id, $lecturer->id), 'mahasiswa_ta_id' => $program->id]);

        $this->actingAs($student)->get(route('chat.start', ['user' => $lecturer->id, 'ta' => $program->id, 'seminar' => $submission->id, 'quote' => 'catatan']))
            ->assertForbidden();
        $this->get(route('chat.show', ['conversation' => $thread, 'seminar' => $submission->id, 'quote' => 'catatan']))->assertForbidden();
        $this->actingAs($lecturer)->post(route('chat.store', $thread), ['body' => 'Salah program',
            'attachable_type' => 'seminar', 'attachable_id' => $submission->id])->assertForbidden();
        $this->assertDatabaseMissing('messages', ['conversation_id' => $thread->id, 'body' => 'Salah program']);
        $this->actingAs($outsider)->get(route('chat.show', ['conversation' => $thread, 'seminar' => $submission->id]))
            ->assertForbidden();
    }
}
