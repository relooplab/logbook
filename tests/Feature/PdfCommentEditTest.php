<?php

namespace Tests\Feature;

use App\Models\LogbookEntry;
use App\Models\PdfComment;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class PdfCommentEditTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    private function ownComment(): PdfComment
    {
        return $this->entryDraft->comments()->create([
            'user_id' => $this->mhs->id,
            'file_type' => PdfComment::FILE_TYPE_DRAFT,
            'page_number' => 1,
            'comment' => 'Komentar awal.',
            'payload' => ['type' => 'highlight', 'page' => 1],
            'resolution_status' => PdfComment::STATUS_OPEN,
            'is_resolved' => false,
        ]);
    }

    public function test_owner_can_edit_own_comment_on_editable_entry(): void
    {
        $comment = $this->ownComment();

        $this->actingAs($this->mhs)->patchJson(route('pdf-comments.update', $comment), [
            'comment' => 'Komentar revisi.',
        ])->assertOk()->assertJson(['ok' => true, 'comment' => 'Komentar revisi.']);

        $this->assertSame('Komentar revisi.', $comment->fresh()->comment);
    }

    public function test_edit_rejects_empty_comment(): void
    {
        $comment = $this->ownComment();

        $this->actingAs($this->mhs)->patchJson(route('pdf-comments.update', $comment), [
            'comment' => '   ',
        ])->assertStatus(422);

        $this->assertSame('Komentar awal.', $comment->fresh()->comment);
    }

    public function test_non_author_cannot_edit(): void
    {
        $comment = $this->ownComment();

        // Dosen reviewer pun tidak boleh mengubah komentar milik mahasiswa.
        $this->actingAs($this->dosen)->patchJson(route('pdf-comments.update', $comment), [
            'comment' => 'Diubah dosen.',
        ])->assertForbidden();

        $this->assertSame('Komentar awal.', $comment->fresh()->comment);
    }

    public function test_owner_cannot_edit_on_submitted_entry(): void
    {
        $comment = $this->ownComment();
        $this->entryDraft->update(['status' => LogbookEntry::STATUS_SUBMITTED, 'submitted_at' => now()]);

        $this->actingAs($this->mhs)->patchJson(route('pdf-comments.update', $comment), [
            'comment' => 'Terlambat.',
        ])->assertForbidden();
    }
}
