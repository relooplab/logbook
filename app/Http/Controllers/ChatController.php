<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\LogbookEntry;
use App\Models\LogbookHarianKp;
use App\Models\MahasiswaTa;
use App\Models\Message;
use App\Models\SeminarSubmission;
use App\Models\ThesisFinalization;
use App\Models\User;
use App\Models\WorkspaceFile;
use App\Services\StorageUsageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChatController extends Controller
{
    /**
     * Daftar percakapan user.
     */
    public function index(Request $request): View
    {
        return $this->workspace($request);
    }

    /**
     * Tampilkan thread percakapan.
     */
    public function show(Request $request, Conversation $conversation): View
    {
        $user = $request->user();
        abort_unless($conversation->hasUser($user->id), 403, 'Anda bukan peserta percakapan ini.');

        $contextEntry = null;
        if ($request->query('entry') !== null) {
            $contextEntry = LogbookEntry::findOrFail($request->query('entry'));
            $this->authorizeEntryContext($contextEntry, $conversation, $user);
        }

        // Tandai semua pesan sebagai dibaca.
        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Load one window at a time; older messages remain accessible via ?before=ID.
        $before = filter_var($request->query('before'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $query = $conversation->messages()->with('sender', 'attachable', 'workspaceFiles.file');
        if ($before) {
            $query->where('id', '<', $before);
        }
        $messages = $query->reorder('id', 'desc')->limit(100)->get()->reverse()->values();
        $hasOlder = $messages->isNotEmpty() && $conversation->messages()->where('id', '<', $messages->first()->id)->exists();

        return $this->workspace($request, $conversation, $messages, $hasOlder, (bool) $before, $contextEntry);
    }

    private function workspace(Request $request, ?Conversation $conversation = null, ?Collection $messages = null, bool $hasOlder = false, bool $viewingOlder = false, ?LogbookEntry $contextEntry = null): View
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $search = mb_substr($search, 0, 100);
        $filter = in_array($request->query('filter'), ['semua', 'dibimbing', 'diuji', 'belum-dibaca'], true)
            ? $request->query('filter') : 'semua';

        $threads = Conversation::query()
            ->where(fn ($q) => $q->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id))
            ->with(['userOne', 'userTwo', 'mahasiswaTa.mahasiswa', 'latestMessage'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_id', '!=', $user->id)->whereNull('read_at')])
            ->orderByDesc('updated_at')->get()
            ->sortByDesc(fn ($thread) => $thread->latestMessage?->created_at?->timestamp ?? 0)->values();

        $programs = $user->isDosen()
            ? MahasiswaTa::bimbinganOleh($user)->with('mahasiswa')->get()
            : ($user->isMahasiswa() ? MahasiswaTa::where('user_id', $user->id)->get() : collect());

        $contacts = collect();
        if ($user->isDosen()) {
            foreach ($programs as $program) {
                if (! $program->mahasiswa) {
                    continue;
                }
                $contacts->push(['other' => $program->mahasiswa, 'program' => $program]);
            }
        } elseif ($user->isMahasiswa()) {
            $ids = $programs->flatMap(fn ($program) => $program->allDosenIds())->unique()->values();
            $lecturers = User::whereIn('id', $ids)->get()->keyBy('id');
            foreach ($programs as $program) {
                foreach ($program->allDosenIds() as $id) {
                    if ($lecturers->has($id)) {
                        $contacts->push(['other' => $lecturers[$id], 'program' => $program]);
                    }
                }
            }
        }

        $rows = $threads->map(function ($thread) use ($user, $programs) {
            $other = $thread->other($user);
            $program = $thread->mahasiswaTa;
            if ($program && (! $this->canAccess($program, $user) || ! $this->canAccess($program, $other))) {
                $program = null;
            }
            if (! $program) {
                $program = $programs->first(fn ($ta) => $user->isDosen()
                    ? $ta->user_id === $other->id
                    : in_array($other->id, $ta->allDosenIds(), true));
            }

            return (object) ['conversation' => $thread, 'other' => $other, 'program' => $program,
                'unread' => $thread->unread_count, 'latest' => $thread->latestMessage,
                'url' => route('chat.show', $thread)];
        });

        foreach ($contacts as $contact) {
            // Do not duplicate an existing thread for the same contact and program.
            if ($rows->contains(fn ($row) => $row->other->id === $contact['other']->id
                && ($row->conversation->mahasiswa_ta_id === $contact['program']->id
                    || $row->conversation->mahasiswa_ta_id === null))) {
                continue;
            }
            $rows->push((object) ['conversation' => null, 'other' => $contact['other'],
                'program' => $contact['program'], 'unread' => 0, 'latest' => null,
                'url' => route('chat.start', ['user' => $contact['other']->id, 'ta' => $contact['program']->id])]);
        }

        $counts = [
            'semua' => $rows->count(),
            'dibimbing' => $rows->filter(fn ($row) => $row->program && $user->isDosen() && $row->program->isPembimbing($user))->count(),
            'diuji' => $rows->filter(fn ($row) => $row->program && $user->isDosen() && $row->program->isPenguji($user))->count(),
            'belum-dibaca' => $rows->filter(fn ($row) => $row->unread > 0)->count(),
        ];

        $rows = $rows->filter(function ($row) use ($user, $filter, $search) {
            if ($filter === 'belum-dibaca' && ! $row->unread) {
                return false;
            }
            if ($filter === 'dibimbing' && (! $row->program || ! $row->program->isPembimbing($user))) {
                return false;
            }
            if ($filter === 'diuji' && (! $row->program || ! $row->program->isPenguji($user))) {
                return false;
            }

            return $search === '' || str_contains(mb_strtolower($row->other->name.' '.($row->other->nim ?? '').' '.($row->other->nidn ?? '')), mb_strtolower($search));
        })->values();

        $active = $conversation ? $rows->first(fn ($row) => $row->conversation?->id === $conversation->id) : null;
        // A filtered list must not hide a thread opened through its direct, authorized URL.
        if ($conversation && ! $active) {
            $other = $conversation->other($user);
            $program = $conversation->mahasiswaTa;
            if ($program && (! $this->canAccess($program, $user) || ! $this->canAccess($program, $other))) {
                $program = null;
            }
            $active = (object) ['conversation' => $conversation, 'other' => $other,
                'program' => $program, 'url' => route('chat.show', $conversation)];
        }

        $canUploadFiles = $conversation && $this->canUploadFiles($conversation, $user);

        return view('chat.index', compact('user', 'rows', 'counts', 'filter', 'search', 'conversation', 'messages', 'active', 'hasOlder', 'viewingOlder', 'contextEntry', 'canUploadFiles'));
    }

    /**
     * Cari/ciptakan percakapan dengan user lain, atau buka thread dari detail mahasiswa.
     */
    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        $otherId = (int) $request->query('user', $request->input('user_id', 0));
        $taId = $request->query('ta') ?: $request->input('mahasiswa_ta_id');
        $other = User::find($otherId);
        abort_unless($other, 404, 'User tidak ditemukan.');
        $this->authorizeChat($user, $other);

        if ($taId) {
            $ta = MahasiswaTa::findOrFail($taId);
            abort_unless($this->canAccess($ta, $user) && $this->canAccess($ta, $other), 403);
            abort_unless($ta->user_id === $user->id || $ta->user_id === $other->id
                || ($user->isDosen() && $other->isDosen()
                    && in_array($user->id, $ta->allDosenIds(), true)
                    && in_array($other->id, $ta->allDosenIds(), true)), 403);
        }
        $contextEntry = null;
        if ($request->query('entry') !== null) {
            $contextEntry = LogbookEntry::findOrFail($request->query('entry'));
            abort_unless($taId && (int) $contextEntry->mahasiswa_ta_id === (int) $taId
                && $this->canAccess($contextEntry->mahasiswaTa, $user)
                && $this->canAccess($contextEntry->mahasiswaTa, $other), 403);
        }
        $conversation = $this->findOrCreate($user, $other, $taId ?: null);

        return redirect()->route('chat.show', $contextEntry
            ? ['conversation' => $conversation, 'entry' => $contextEntry->id]
            : $conversation);
    }

    /**
     * Kirim pesan (dari halaman chat).
     */
    public function store(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($conversation->hasUser($user->id), 403);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachable_type' => ['nullable', 'in:workspace,logbook,logbook_harian,seminar,finalization'],
            'attachable_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:51200'],
        ]);

        $uploads = $request->file('files', []);
        $body = trim($validated['body'] ?? '');
        if ($body === '' && count($uploads) === 0) {
            throw ValidationException::withMessages(['body' => 'Tulis pesan atau pilih file untuk dikirim.']);
        }

        $ta = $conversation->mahasiswaTa;
        if (count($uploads) > 0) {
            abort_unless($this->canUploadFiles($conversation, $user), 403);
        }

        $attach = null;
        if (! empty($validated['attachable_type']) && ! empty($validated['attachable_id'])) {
            $attach = $this->resolveAttachable($validated['attachable_type'], (int) $validated['attachable_id'], $user);
            if ($attach) {
                abort_unless((! $conversation->mahasiswa_ta_id || $attach->mahasiswa_ta_id === $conversation->mahasiswa_ta_id)
                    && $this->canAccess($attach->mahasiswaTa, $conversation->other($user)), 403);
            }
        }
        if ($body === '' && count($uploads) === 0 && ! $attach) {
            throw ValidationException::withMessages(['body' => 'Tulis pesan atau pilih file untuk dikirim.']);
        }

        $storedPaths = [];
        $save = function () use ($conversation, $user, $body, $attach, $ta, $uploads, &$storedPaths) {
            return DB::transaction(function () use ($conversation, $user, $body, $attach, $ta, $uploads, &$storedPaths) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'body' => $body,
                    'attachable_type' => $attach ? get_class($attach) : null,
                    'attachable_id' => $attach ? $attach->id : null,
                ]);

                if ($attach instanceof WorkspaceFile) {
                    $message->workspaceFiles()->create([
                        'workspace_file_id' => $attach->id,
                        'original_name' => $attach->original_name,
                    ]);
                }

                foreach ($uploads as $file) {
                    $path = $file->store('workspace/'.$ta->id, 'local');
                    if (! $path) {
                        throw new \RuntimeException('Gagal menyimpan file ke workspace.');
                    }
                    $storedPaths[] = $path;
                    $workspaceFile = WorkspaceFile::create([
                        'mahasiswa_ta_id' => $ta->id,
                        'uploaded_by' => $user->id,
                        'original_name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'mime_type' => $file->getClientMimeType(),
                        'size' => $file->getSize(),
                    ]);
                    $message->workspaceFiles()->create([
                        'workspace_file_id' => $workspaceFile->id,
                        'original_name' => $workspaceFile->original_name,
                    ]);
                }

                $conversation->touch();

                return $message;
            });
        };

        try {
            $chargeTo = count($uploads) ? $ta->storageChargeTarget() : null;
            $message = $chargeTo
                ? app(StorageUsageService::class)->withUploadLock($chargeTo, collect($uploads)->sum(fn ($file) => $file->getSize()), $save)
                : $save();
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        $this->bestEffort(fn () => broadcast(new MessageSent($message, $conversation)));

        return redirect()->route('chat.show', $conversation);
    }

    private function canUploadFiles(Conversation $conversation, User $user): bool
    {
        $ta = $conversation->mahasiswaTa;
        if (! $ta || ! $conversation->hasUser($user->id) || ! $ta->dosenHasGrantedAccess()) {
            return false;
        }

        $other = $conversation->other($user);
        $related = fn (User $participant) => $ta->isMember($participant)
            || ($participant->isDosen() && ($ta->isPembimbing($participant) || $ta->isPenguji($participant)));

        return $related($user) && $related($other)
            && $user->can('viewWorkspace', $ta) && $other->can('viewWorkspace', $ta);
    }

    /**
     * Edit pesan (hanya 15 menit pertama).
     */
    public function update(Request $request, Conversation $conversation, Message $message): RedirectResponse
    {
        abort_unless($message->conversation_id === $conversation->id, 404);
        abort_unless($conversation->hasUser($request->user()->id), 403);
        abort_unless($message->sender_id === $request->user()->id, 403);
        abort_unless($message->isEditable(), 403, 'Waktu edit pesan telah habis.');

        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $message->update(['body' => $validated['body'], 'edited_at' => now()]);

        return redirect()->route('chat.show', $conversation);
    }

    /**
     * Daftar karya mahasiswa yang bisa disematkan (workspace, logbook,
     * revisi, logbook harian KP, seminar/sidang, finalisasi).
     */
    public function attachOptions(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        abort_unless($conversation->hasUser($user->id), 403);

        // Batasi ke program percakapan (pekerjaan mahasiswa) bila ada.
        $ta = $conversation->mahasiswaTa;
        if ($ta) {
            abort_unless($this->canAccess($ta, $user) && $this->canAccess($ta, $conversation->other($user)), 403);
        }
        $scope = function ($q) use ($user, $ta) {
            if ($ta) {
                $q->where('id', $ta->id);

                return;
            }
            $this->scopeForUser($q, $user);
        };

        $files = WorkspaceFile::whereHas('mahasiswaTa', $scope)
            ->with('mahasiswaTa.mahasiswa')->orderByDesc('created_at')->limit(10)->get();

        $entries = LogbookEntry::whereHas('mahasiswaTa', $scope)
            ->with('mahasiswaTa.mahasiswa')->orderByDesc('created_at')->limit(20)->get();

        $logbooks = $entries->where('jenis', LogbookEntry::JENIS_LOGBOOK)->take(10)->values();
        $revisis = $entries->where('jenis', LogbookEntry::JENIS_REVISI)->take(10)->values();

        $harian = LogbookHarianKp::whereHas('mahasiswaTa', $scope)
            ->with('mahasiswaTa.mahasiswa')->orderByDesc('tanggal')->limit(10)->get();

        $seminars = SeminarSubmission::whereHas('mahasiswaTa', $scope)
            ->with('mahasiswaTa.mahasiswa')->orderByDesc('created_at')->limit(10)->get();

        $finals = ThesisFinalization::whereHas('mahasiswaTa', $scope)
            ->with('mahasiswaTa.mahasiswa')->orderByDesc('updated_at')->limit(5)->get();

        return response()->json([
            'categories' => [
                $this->attachCategory('workspace', 'Workspace', 'description', $files->map(fn ($f) => [
                    'type' => 'workspace',
                    'id' => $f->id,
                    'label' => $f->original_name,
                    'student' => $f->mahasiswaTa?->mahasiswa?->name,
                    'url' => $f->isPdf() ? route('workspace.preview', $f) : route('workspace.download', $f),
                ])),
                $this->attachCategory('logbook', 'Logbook', 'assignment', $logbooks->map(fn ($e) => [
                    'type' => 'logbook',
                    'id' => $e->id,
                    'label' => 'Entri #'.$e->sesi_ke.($e->topik ? ' — '.$e->topik : ''),
                    'student' => $e->mahasiswaTa?->mahasiswa?->name,
                    'url' => route('logbook.show', $e),
                ])),
                $this->attachCategory('revisi', 'Revisi', 'sync', $revisis->map(fn ($e) => [
                    'type' => 'logbook',
                    'id' => $e->id,
                    'label' => 'Revisi r'.$e->revision_round.($e->topik ? ' — '.$e->topik : ''),
                    'student' => $e->mahasiswaTa?->mahasiswa?->name,
                    'url' => route('logbook.show', $e),
                ])),
                $this->attachCategory('logbook_harian', 'Logbook Harian KP', 'calendar_month', $harian->map(fn ($h) => [
                    'type' => 'logbook_harian',
                    'id' => $h->id,
                    'label' => 'KP '.$h->tanggal->format('d M Y').($h->kegiatan ? ' — '.mb_strimwidth($h->kegiatan, 0, 40, '…') : ''),
                    'student' => $h->mahasiswaTa?->mahasiswa?->name,
                    'url' => $h->mahasiswaTa ? route('logbook-harian.index', $h->mahasiswaTa) : '#',
                ])),
                $this->attachCategory('seminar', 'Seminar / Sidang', 'school', $seminars->map(fn ($s) => [
                    'type' => 'seminar',
                    'id' => $s->id,
                    'label' => $s->jenisLabel(),
                    'student' => $s->mahasiswaTa?->mahasiswa?->name,
                    'url' => route('seminar-submission.show', $s),
                ])),
                $this->attachCategory('finalization', 'Finalisasi', 'task_alt', $finals->map(fn ($fz) => [
                    'type' => 'finalization',
                    'id' => $fz->id,
                    'label' => 'Finalisasi'.($fz->full_file_original_name ? ' — '.$fz->full_file_original_name : ''),
                    'student' => $fz->mahasiswaTa?->mahasiswa?->name,
                    'url' => $fz->mahasiswaTa ? route('finalization.index', $fz->mahasiswaTa) : '#',
                ])),
            ],
        ]);
    }

    private function attachCategory(string $key, string $title, string $icon, iterable $items): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'icon' => $icon,
            'items' => $items->values()->all(),
        ];
    }

    // ----------------------------------------------------------- helpers

    private function authorizeChat(User $user, User $other): void
    {
        if ($user->isAdmin()) {
            // Admin bisa chat dengan semua user di scope tenant-nya.
            if ($other->institution_id && $other->institution_id !== $user->institution_id) {
                abort(403);
            }

            return;
        }

        if ($user->isDosen()) {
            // Dosen dengan mahasiswa bimbingannya / penguji, atau admin.
            if ($other->isDosen() && ! $other->isAdmin()) {
                // Dosen boleh chat dengan dosen lain jika ada hubungan langsung
                // (TA bersama atau grup yang sama).
                abort_unless($user->hasDirectRelation($other), 403, 'Anda tidak memiliki hubungan langsung dengan dosen ini.');
            }
            if ($other->isMahasiswa() && ! $this->isRelatedTo($user, $other)) {
                abort(403);
            }

            return;
        }

        // Mahasiswa: hanya pembimbing/penguji atau admin.
        if ($other->isMahasiswa()) {
            abort(403, 'Mahasiswa tidak bisa chat dengan mahasiswa lain.');
        }
        if ($other->isDosen() && ! $this->isRelatedTo($user, $other) && ! $other->isAdmin()) {
            abort(403);
        }
    }

    private function isRelatedTo(User $a, User $b): bool
    {
        // a = dosen, b = mahasiswa (atau sebaliknya): cek TA di mana mereka terhubung.
        return MahasiswaTa::where(function ($q) use ($a, $b) {
            $q->where('user_id', $b->id)
                ->where(function ($w) use ($a) {
                    $w->where('pembimbing_1_id', $a->id)->orWhere('pembimbing_2_id', $a->id)
                        ->orWhere('penguji_1_id', $a->id)->orWhere('penguji_2_id', $a->id);
                });
        })->orWhere(function ($q) use ($a, $b) {
            $q->where('user_id', $a->id)
                ->where(function ($w) use ($b) {
                    $w->where('pembimbing_1_id', $b->id)->orWhere('pembimbing_2_id', $b->id)
                        ->orWhere('penguji_1_id', $b->id)->orWhere('penguji_2_id', $b->id);
                });
        })->exists();
    }

    private function findOrCreate(User $a, User $b, ?int $taId): Conversation
    {
        // Konsisten: user_one_id selalu ID lebih kecil.
        [$one, $two] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return Conversation::firstOrCreate(
            ['mahasiswa_ta_id' => $taId, 'user_one_id' => $one, 'user_two_id' => $two],
            ['user_one_id' => $one, 'user_two_id' => $two]
        );
    }

    private function resolveAttachable(string $type, int $id, User $user): ?Model
    {
        $model = match ($type) {
            'workspace' => WorkspaceFile::find($id),
            'logbook' => LogbookEntry::find($id),
            'logbook_harian' => LogbookHarianKp::find($id),
            'seminar' => SeminarSubmission::find($id),
            'finalization' => ThesisFinalization::find($id),
            default => null,
        };

        if ($model && $this->canAccess($model->mahasiswaTa, $user)) {
            return $model;
        }

        return null;
    }

    private function authorizeEntryContext(LogbookEntry $entry, Conversation $conversation, User $user): void
    {
        abort_unless($conversation->mahasiswa_ta_id
            && $entry->mahasiswa_ta_id === $conversation->mahasiswa_ta_id
            && $this->canAccess($entry->mahasiswaTa, $user)
            && $this->canAccess($entry->mahasiswaTa, $conversation->other($user)), 403);
    }

    private function canAccess(?MahasiswaTa $ta, User $user): bool
    {
        if (! $ta) {
            return false;
        }

        if ($user->isAdmin()) {
            return $user->isSystemAdmin() || $user->institution_id === null || $ta->institution_id === $user->institution_id;
        }

        return $ta->isMember($user)
            || $ta->isPembimbing($user)
            || $ta->isPenguji($user);
    }

    private function scopeForUser($q, User $user): void
    {
        if ($user->isAdmin()) {
            if (! $user->isSystemAdmin() && $user->institution_id) {
                $q->where('institution_id', $user->institution_id);
            }

            return;
        }

        $memberProgramIds = \DB::table('mahasiswa_ta_members')
            ->where('user_id', $user->id)
            ->pluck('mahasiswa_ta_id');

        $q->where(function ($w) use ($user, $memberProgramIds) {
            $w->where('user_id', $user->id)
                ->orWhereIn('id', $memberProgramIds)
                ->orWhere('pembimbing_1_id', $user->id)
                ->orWhere('pembimbing_2_id', $user->id)
                ->orWhere('penguji_1_id', $user->id)
                ->orWhere('penguji_2_id', $user->id);
        });
    }
}
