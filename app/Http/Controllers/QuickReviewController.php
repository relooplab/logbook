<?php

namespace App\Http\Controllers;

use App\Events\EntryStatusChanged;
use App\Models\FeedbackTemplate;
use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\PdfComment;
use App\Services\AchievementService;
use App\Services\LogbookReviewTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuickReviewController extends Controller
{
    /**
     * Quick review: tampilkan entry antrean berikutnya + tombol Approve & Next.
     */
    public function index(Request $request): View
    {
        // Ambil ID saja untuk navigasi; detail hanya dimuat untuk item aktif.
        $queueIds = $this->reviewQueue($request)->pluck('id');
        $queueCount = $queueIds->count();
        $selectedId = $request->query('item');
        if ($selectedId !== null) {
            abort_unless(ctype_digit((string) $selectedId) && $queueIds->contains((int) $selectedId), 404);
        }
        $queueIndex = $selectedId === null ? 0 : $queueIds->search((int) $selectedId);
        $entryId = $queueIds->get($queueIndex);
        $entry = $entryId ? LogbookEntry::with(['mahasiswaTa.mahasiswa', 'mahasiswaTa.pembimbing1', 'dosen', 'comments', 'parentEntry.comments', 'revisionChildren'])
            ->findOrFail($entryId) : null;

        if ($entry) {
            $this->authorize('review', $entry);
        }

        $templates = $entry ? FeedbackTemplate::where('user_id', $request->user()->id)->get() : collect();
        $lastFeedback = $entry ? $this->lastFeedbackForStudent($entry) : null;

        // Draft dari viewer PDF hanya berlaku untuk entri tempat komentar dibuat.
        $feedbackDraft = $request->session()->get('feedback_draft_entry_id') === $entryId
            ? $request->session()->pull('feedback_draft') : null;
        if ($feedbackDraft !== null) {
            $request->session()->forget('feedback_draft_entry_id');
        }

        $previousId = $queueIndex > 0 ? $queueIds->get($queueIndex - 1) : null;
        $nextId = $queueIds->get($queueIndex + 1);

        // Pratinjau kartu "Berikutnya dalam antrean" (tanpa otorisasi tampilan
        // penuh — tautannya tetap dijaga antrean saat dibuka).
        $nextEntry = $nextId ? LogbookEntry::with(['mahasiswaTa.mahasiswa'])
            ->find($nextId) : null;

        return view('logbook.quick-review', compact('entry', 'templates', 'lastFeedback', 'feedbackDraft', 'queueCount', 'queueIndex', 'previousId', 'nextId', 'nextEntry'));
    }

    /**
     * Approve & next: setujui entry lalu redirect ke antrean berikutnya.
     */
    public function approveNext(Request $request, LogbookEntry $logbook, LogbookReviewTransition $transition): RedirectResponse
    {
        $this->authorize('review', $logbook);

        // Hanya program aktif yang bisa di-review.
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        $validated = $request->validate(['feedback_dosen' => ['nullable', 'string', 'max:5000']]);

        $transition->apply($logbook, LogbookEntry::STATUS_APPROVED, [
            'feedback_dosen' => $validated['feedback_dosen'] ?? null,
            'reviewed_at' => now(),
        ]);
        $this->resolveCommentsOnApproval($logbook);

        $this->bestEffort(fn () => EntryStatusChanged::dispatch($logbook, 'Entri Anda telah disetujui.'));
        $logbook->notifyParties('Entri '.($logbook->jenis === 'revisi' ? 'revisi' : 'logbook sesi '.$logbook->sesi_ke).' telah disetujui.', route('logbook.show', $logbook), 'Entri Disetujui');
        if ($owner = $logbook->mahasiswaTa?->mahasiswa) {
            app(AchievementService::class)->evaluateForUser($owner);
        }

        return redirect()->route('quick-review.index', $this->nextQueueItem($request, $logbook))
            ->with('success', 'Entri disetujui. Lanjut ke berikutnya.');
    }

    /**
     * Revisi & next: simpan feedback (dengan template/build dari komentar) lalu next.
     */
    public function revisiNext(Request $request, LogbookEntry $logbook, LogbookReviewTransition $transition): RedirectResponse
    {
        $this->authorize('review', $logbook);

        // Hanya program aktif yang bisa di-review.
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        $validated = $request->validate([
            'feedback_dosen' => ['required', 'string', 'min:20'],
        ]);

        $transition->apply($logbook, LogbookEntry::STATUS_REVISI, [
            'feedback_dosen' => $validated['feedback_dosen'],
            'reviewed_at' => now(),
        ]);

        $this->bestEffort(fn () => EntryStatusChanged::dispatch($logbook, 'Entri Anda diminta revisi.'));
        $logbook->notifyParties('Entri Anda diminta revisi: '.$validated['feedback_dosen'], route('logbook.show', $logbook), 'Permintaan Revisi');

        return redirect()->route('quick-review.index', $this->nextQueueItem($request, $logbook))
            ->with('success', 'Entri dikembalikan untuk revisi. Lanjut ke berikutnya.');
    }

    public function archiveNext(Request $request, LogbookEntry $logbook, \App\Services\ArchiveLogbookReview $archive): RedirectResponse
    {
        $this->authorize('review', $logbook);
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        $validated = $request->validate(['archive_reason' => ['nullable', 'string', 'max:5000']]);
        $archive->archive($logbook, $request->user(), $validated['archive_reason'] ?? null);

        return redirect()->route('quick-review.index', $this->nextQueueItem($request, $logbook))
            ->with('success', 'Entri diarsipkan. Lanjut ke berikutnya.');
    }

    /**
     * Simpan template feedback (snippet).
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'body' => ['required', 'string'],
        ]);

        $tpl = FeedbackTemplate::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?: null,
            'body' => $validated['body'],
        ]);

        return response()->json($tpl, 201);
    }

    public function destroyTemplate(Request $request, FeedbackTemplate $template): JsonResponse
    {
        if ($template->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }
        $template->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Compile feedback otomatis dari komentar PDF yang belum resolve,
     * mencakup SEMUA peran penulis (dosen + mahasiswa) agar anotasi
     * perbaikan mahasiswa tidak hilang, lalu simpan ke session untuk
     * dipakai di quick review.
     */
    public function buildFeedbackFromComments(Request $request, LogbookEntry $logbook): JsonResponse
    {
        $this->authorize('review', $logbook);

        $entryIds = [$logbook->id];
        $cursor = $logbook->parentEntry;
        while ($cursor) {
            $entryIds[] = $cursor->id;
            $cursor = $cursor->parentEntry;
        }

        $comments = PdfComment::whereIn('logbook_entry_id', $entryIds)
            ->with('user')
            ->where('resolution_status', PdfComment::STATUS_OPEN)
            ->orderBy('page_number')
            ->get();

        if ($comments->isEmpty()) {
            return response()->json(['feedback' => '']);
        }

        $lines = $comments->map(function ($c, $i) use ($logbook) {
            $source = $c->logbook_entry_id === $logbook->id
                ? 'Sesi ini'
                : 'Sesi sebelumnya, entri #'.$c->logbook_entry_id;
            $role = $c->user && $c->user->isDosen() ? 'dosen' : 'mahasiswa';

            return ($i + 1).'. ('.$source.' · '.$role.', Hal. '.$c->page_number.') '.$c->comment;
        });
        $feedback = $lines->implode("\n");

        // Simpan ke session untuk dipakai di quick review.
        $request->session()->put('feedback_draft', $feedback);
        $request->session()->put('feedback_draft_entry_id', $logbook->id);

        return response()->json(['feedback' => $feedback]);
    }

    private function lastFeedbackForStudent(LogbookEntry $entry): ?string
    {
        $taId = $entry->mahasiswa_ta_id;

        return LogbookEntry::where('mahasiswa_ta_id', $taId)
            ->whereNotNull('feedback_dosen')
            ->orderByDesc('id')
            ->value('feedback_dosen');
    }

    private function reviewQueue(Request $request): Builder
    {
        $user = $request->user();
        $taIds = MahasiswaTa::where('pembimbing_1_id', $user->id)
            ->orWhere('pembimbing_2_id', $user->id)
            ->pluck('id');

        return LogbookEntry::where('status', LogbookEntry::STATUS_SUBMITTED)
            ->where(function ($query) use ($taIds, $user) {
                $query->whereIn('mahasiswa_ta_id', $taIds)
                    ->orWhere('dosen_id', $user->id)
                    ->orWhereHas('revisionChildren', fn ($q) => $q->where('dosen_id', $user->id));
            })
            ->orderBy('submitted_at')->orderBy('id');
    }

    /** Setelah keputusan, lanjut ke penerus urutan semula, atau kembali ke awal bila terakhir. */
    private function nextQueueItem(Request $request, LogbookEntry $processed): array
    {
        $nextId = $this->reviewQueue($request)
            ->where(function ($query) use ($processed) {
                $query->where('submitted_at', '>', $processed->submitted_at)
                    ->orWhere(fn ($q) => $q->where('submitted_at', $processed->submitted_at)->where('id', '>', $processed->id));
            })->value('id');

        return $nextId ? ['item' => $nextId] : [];
    }

    private function resolveCommentsOnApproval(LogbookEntry $logbook): void
    {
        $entries = collect([$logbook, $logbook->parentEntry])->filter();

        foreach ($entries as $entry) {
            $entry->comments()
                ->where('resolution_status', '!=', PdfComment::STATUS_RESOLVED)
                ->get()
                ->each(function ($comment) {
                    $comment->setResolutionStatus(PdfComment::STATUS_RESOLVED);
                    $comment->save();
                });
        }
    }
}
