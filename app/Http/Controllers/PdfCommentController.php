<?php

namespace App\Http\Controllers;

use App\Models\LogbookEntry;
use App\Models\PdfComment;
use App\Policies\LogbookEntryPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdfCommentController extends Controller
{
    public function resolve(Request $request, PdfComment $comment): JsonResponse
    {
        $this->authorize('view', $comment->entry);

        $status = $comment->resolution_status ?: ($comment->is_resolved ? PdfComment::STATUS_RESOLVED : PdfComment::STATUS_OPEN);
        $isReviewer = app(LogbookEntryPolicy::class)->isReviewer($request->user(), $comment->entry);

        if ($isReviewer) {
            $next = $status === PdfComment::STATUS_RESOLVED
                ? PdfComment::STATUS_OPEN
                : PdfComment::STATUS_RESOLVED;
        } elseif ($comment->entry->mahasiswaTa?->user_id === $request->user()->id && $status !== PdfComment::STATUS_RESOLVED) {
            $next = $status === PdfComment::STATUS_ADDRESSED
                ? PdfComment::STATUS_OPEN
                : PdfComment::STATUS_ADDRESSED;
        } else {
            abort(403);
        }

        $comment->setResolutionStatus($next);
        $comment->save();

        // Badge "Responsif" saat semua komentar PDF resolved.
        if ($owner = $comment->entry->mahasiswaTa?->mahasiswa) {
            app(\App\Services\AchievementService::class)->evaluateForUser($owner);
        }

        return response()->json([
            'ok' => true,
            'is_resolved' => $comment->is_resolved,
            'resolution_status' => $comment->resolution_status,
        ]);
    }

    public function reply(Request $request, PdfComment $comment): JsonResponse
    {
        $this->authorize('view', $comment->entry);

        $isOwner = $comment->entry->mahasiswaTa?->user_id === $request->user()->id;
        $isReviewer = app(LogbookEntryPolicy::class)->isReviewer($request->user(), $comment->entry);
        abort_unless($isOwner || $isReviewer, 403, 'Hanya mahasiswa pemilik atau dosen reviewer yang dapat membalas komentar.');

        $validated = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        $comment->replies()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['reply'],
        ]);
        // Kolom legacy tetap menampung balasan mahasiswa pertama untuk konsumen lama.
        if ($isOwner && ! $comment->reply) {
            $comment->update(['reply' => $validated['reply']]);
        }

        // Membalas komentar dosen = menandai "sudah diperbaiki" (addressed)
        // secara otomatis, menunggu keputusan dosen untuk resolve.
        $dosenAuthor = $comment->user && $comment->user->isDosen() ? $comment->user : null;
        if ($isOwner && $dosenAuthor && $comment->resolution_status === PdfComment::STATUS_OPEN) {
            $comment->setResolutionStatus(PdfComment::STATUS_ADDRESSED);
            $comment->save();
        }

        // Notifikasi ke dosen penulis komentar bahwa mahasiswa sudah menanggapi.
        if ($isOwner && $dosenAuthor && $comment->entry) {
            $mahasiswa = $comment->entry->mahasiswaTa?->mahasiswa;
            $this->bestEffort(fn () => $dosenAuthor->notify(new \App\Notifications\ActivityNotification(
                ($mahasiswa?->name ?? 'Mahasiswa').' menanggapi komentar Anda pada '.
                    ($comment->entry->jenis === 'revisi' ? 'revisi' : 'logbook sesi '.$comment->entry->sesi_ke).': "'.mb_strimwidth($validated['reply'], 0, 120, '…').'"',
                route('logbook.pdf-viewer', $comment->entry),
                'Komentar PDF Direspons',
            )));
        }

        if ($isReviewer && ($owner = $comment->entry->mahasiswaTa?->mahasiswa)) {
            $this->bestEffort(fn () => $owner->notify(new \App\Notifications\ActivityNotification(
                'Dosen menanggapi komentar PDF Anda: "'.mb_strimwidth($validated['reply'], 0, 120, '…').'"',
                route('logbook.pdf-viewer', $comment->entry).'#highlight-'.$comment->id,
                'Balasan Komentar PDF',
            )));
        }

        $comment->load('replies.user', 'entry.mahasiswaTa.mahasiswa');

        return response()->json([
            'ok' => true,
            'reply' => $comment->reply,
            'replies' => $comment->repliesForViewer(),
            'resolution_status' => $comment->resolution_status,
        ]);
    }

    /**
     * Ubah isi komentar anotasi. Hanya penulisnya dan hanya selama entri
     * masih bisa diubah (draft / revisi berjalan) — sejajar aturan hapus
     * milik non-reviewer. Balasan dan status tidak ikut berubah.
     */
    public function update(Request $request, PdfComment $comment): JsonResponse
    {
        $this->authorize('view', $comment->entry);
        abort_unless($request->user()->id === $comment->user_id, 403, 'Hanya penulis komentar yang dapat mengubahnya.');
        abort_unless($request->user()->can('update', $comment->entry), 403, 'Entri sudah tidak bisa diubah.');

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $comment->update(['comment' => trim($validated['comment'])]);

        return response()->json(['ok' => true, 'comment' => $comment->comment]);
    }

    public function destroy(Request $request, PdfComment $comment): JsonResponse
    {
        $this->authorize('view', $comment->entry);

        // Hanya pembuat komentar atau dosen pembimbing/reviewer entri ini yang boleh menghapus.
        $isReviewer = app(LogbookEntryPolicy::class)->isReviewer($request->user(), $comment->entry);
        if ($request->user()->id !== $comment->user_id && !$isReviewer) {
            abort(403);
        }

        $comment->delete();

        return response()->json(['ok' => true]);
    }
}
