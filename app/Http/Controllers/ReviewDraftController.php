<?php

namespace App\Http\Controllers;

use App\Models\LogbookEntry;
use App\Models\ReviewDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewDraftController extends Controller
{
    /**
     * Simpan (upsert) draf keputusan review milik dosen untuk satu entri.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logbook_entry_id' => ['required', 'integer', 'exists:logbook_entries,id'],
            'feedback_dosen' => ['nullable', 'string', 'max:5000'],
            'review_decision' => ['nullable', 'in:approve,revisi,archive'],
            'archive_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $entry = LogbookEntry::findOrFail($validated['logbook_entry_id']);

        // Hanya reviewer entri ini yang boleh menyimpan draf.
        if (! $request->user()->can('review', $entry) && ! $request->user()->can('isReviewer', $entry)) {
            abort(403);
        }

        // Draf hanya relevan selama entri menunggu review.
        if ($entry->status !== LogbookEntry::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Entri sudah diputuskan.'], 409);
        }

        $draft = ReviewDraft::updateOrCreate(
            ['logbook_entry_id' => $entry->id, 'user_id' => $request->user()->id],
            [
                'feedback_dosen' => $validated['feedback_dosen'] ?? null,
                'review_decision' => $validated['review_decision'] ?? null,
                'archive_reason' => $validated['archive_reason'] ?? null,
            ]
        );

        return response()->json([
            'ok' => true,
            'saved_at' => $draft->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Buang draf keputusan milik sendiri untuk satu entri.
     */
    public function destroy(Request $request, LogbookEntry $entry): JsonResponse|RedirectResponse
    {
        if (! $request->user()->can('review', $entry) && ! $request->user()->can('isReviewer', $entry)) {
            abort(403);
        }

        $deleted = ReviewDraft::where('logbook_entry_id', $entry->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        if (! $deleted) {
            abort(404);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Draf keputusan dibuang.');
    }
}
