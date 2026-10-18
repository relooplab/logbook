<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\LogbookEntry;
use App\Notifications\ActivityNotification;
use App\Services\AchievementService;
use App\Services\LogbookReviewTransition;
use App\Services\MaterialsReviewQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DosenArchiveReviewTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    private const REASON = 'Dokumen ini disimpan sebagai referensi, tanpa persetujuan.';

    public function test_reviewer_can_archive_submitted_entry_with_reason_and_it_leaves_review_queues(): void
    {
        Notification::fake();
        $this->assertSame(1, app(MaterialsReviewQueue::class)->pendingLogbook($this->dosen)->count());

        $this->actingAs($this->dosen)->post('/logbook/'.$this->entrySubmitted->id.'/archive', [
            'archive_reason' => self::REASON,
        ])->assertRedirect();

        $entry = $this->entrySubmitted->fresh();
        $this->assertSame('archived', $entry->status);
        $this->assertSame(self::REASON, $entry->archive_reason);
        $this->assertSame($this->dosen->id, $entry->archived_by);
        $this->assertNotNull($entry->archived_at);
        $this->assertNotNull($entry->reviewed_at);
        $this->assertSame(0, app(MaterialsReviewQueue::class)->pendingLogbook($this->dosen)->count());
        $this->actingAs($this->dosen)->get(route('quick-review.index'))->assertOk()->assertSee('Tidak ada item yang menunggu review.');
        $this->actingAs($this->mhs)->get(route('logbook.show', $entry))->assertOk()->assertSee('Diarsipkan')->assertSee(self::REASON);
        Notification::assertSentTo($this->mhs, ActivityNotification::class, fn ($notification) => $notification->subject === 'Entri Diarsipkan' && str_contains($notification->message, self::REASON));
    }

    public function test_archive_accepts_short_reason_and_requires_submitted_status(): void
    {
        $url = '/logbook/'.$this->entrySubmitted->id.'/archive';
        // Entri belum submitted: ditolak dengan redirect + pesan ramah,
        // bukan 403 mentah (keputusan basi / belum waktunya).
        $this->actingAs($this->dosen)->post('/logbook/'.$this->entryDraft->id.'/archive', [
            'archive_reason' => self::REASON,
        ])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->dosen)->post($url, ['archive_reason' => 'singkat'])->assertRedirect();
        $this->assertSame('singkat', $this->entrySubmitted->fresh()->archive_reason);
        // Entri sudah diputuskan: sama, redirect + pesan, bukan 403.
        $this->actingAs($this->dosen)->post($url, ['archive_reason' => self::REASON])
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_detail_review_archives_without_reason_and_notifies_without_empty_reason_label(): void
    {
        Notification::fake();
        $this->actingAs($this->dosen)->post('/logbook/'.$this->entrySubmitted->id.'/archive')->assertRedirect();
        $entry = $this->entrySubmitted->fresh();
        $this->assertSame(LogbookEntry::STATUS_ARCHIVED, $entry->status);
        $this->assertNull($entry->archive_reason);
        Notification::assertSentTo($this->mhs, ActivityNotification::class, fn ($notification) => $notification->subject === 'Entri Diarsipkan' && ! str_contains($notification->message, 'Alasan:'));
    }

    public function test_non_reviewers_cannot_archive_entry(): void
    {
        $url = '/logbook/'.$this->entrySubmitted->id.'/archive';
        $payload = ['archive_reason' => self::REASON];
        $this->actingAs($this->mhs)->post($url, $payload)->assertForbidden();
        $this->actingAs($this->admin)->post($url, $payload)->assertForbidden();
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $this->entrySubmitted->fresh()->status);
    }

    public function test_quick_review_archive_advances_to_next_pending_entry(): void
    {
        $next = LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 37,
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now()->addMinute(),
        ]);
        $this->actingAs($this->dosen)->post('/quick-review/'.$this->entrySubmitted->id.'/archive-next', [
            'archive_reason' => self::REASON,
        ])->assertRedirect(route('quick-review.index', ['item' => $next->id]));
        $this->assertSame('archived', $this->entrySubmitted->fresh()->status);
        $this->assertSame(LogbookEntry::STATUS_SUBMITTED, $next->fresh()->status);
    }

    public function test_review_screens_expose_archive_choice_for_reviewer_only(): void
    {
        $this->actingAs($this->dosen)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()->assertSee('Arsipkan')->assertSee('name="archive_reason"', false);
        $this->actingAs($this->dosen)->get(route('quick-review.index'))
            ->assertOk()->assertSee('Arsipkan')->assertSee('name="archive_reason"', false);
        $this->actingAs($this->mhs)->get(route('logbook.show', $this->entrySubmitted))
            ->assertOk()->assertDontSee('name="archive_reason"', false);
    }

    public function test_archived_parent_with_revision_child_keeps_archived_label(): void
    {
        LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id,
            'parent_entry_id' => $this->entrySubmitted->id,
            'dosen_id' => $this->dosen->id,
            'jenis' => LogbookEntry::JENIS_REVISI,
            'status' => LogbookEntry::STATUS_APPROVED,
        ]);
        $this->actingAs($this->dosen)->post('/logbook/'.$this->entrySubmitted->id.'/archive', [
            'archive_reason' => self::REASON,
        ])->assertRedirect();
        $this->assertSame('Diarsipkan', $this->entrySubmitted->fresh()->statusLabel());
    }

    public function test_quick_review_accepts_missing_reason_and_rejects_non_reviewer(): void
    {
        $url = '/quick-review/'.$this->entrySubmitted->id.'/archive-next';
        $this->actingAs($this->mhs)->post($url, ['archive_reason' => self::REASON])->assertForbidden();
        $this->actingAs($this->dosen)->post($url)->assertRedirect(route('quick-review.index'));
        $this->assertSame(LogbookEntry::STATUS_ARCHIVED, $this->entrySubmitted->fresh()->status);
        $this->assertNull($this->entrySubmitted->fresh()->archive_reason);
    }

    public function test_archived_entry_interrupts_consecutive_approval_achievement(): void
    {
        Achievement::firstOrCreate(['code' => Achievement::ZERO_REVISI], [
            'name' => 'Zero Revisi', 'description' => 'Dua approval beruntun', 'icon' => '🎯',
        ]);
        $this->entryDraft->update(['status' => LogbookEntry::STATUS_APPROVED]);
        // Entri berikutnya diarsipkan — run approval harus terputus sebelum
        // mencapai dua beruntun, sehingga badge belum ter-unlock.
        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_ARCHIVED]);
        LogbookEntry::create([
            'mahasiswa_ta_id' => $this->ta->id, 'jenis' => LogbookEntry::JENIS_LOGBOOK,
            'sesi_ke' => 3, 'status' => LogbookEntry::STATUS_APPROVED,
        ]);

        app(AchievementService::class)->evaluateForUser($this->mhs);
        $this->assertFalse($this->mhs->achievements()->where('code', Achievement::ZERO_REVISI)->exists());
    }

    public function test_stale_review_decisions_cannot_overwrite_archived_entry(): void
    {
        $staleApproval = LogbookEntry::findOrFail($this->entrySubmitted->id);
        $staleRevision = LogbookEntry::findOrFail($this->entrySubmitted->id);
        $entry = LogbookEntry::findOrFail($this->entrySubmitted->id);

        app(LogbookReviewTransition::class)->apply($entry, LogbookEntry::STATUS_ARCHIVED);

        foreach ([
            [$staleApproval, LogbookEntry::STATUS_APPROVED],
            [$staleRevision, LogbookEntry::STATUS_REVISI],
        ] as [$stale, $status]) {
            try {
                app(LogbookReviewTransition::class)->apply($stale, $status);
                $this->fail('Review keputusan lama seharusnya ditolak.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(LogbookEntry::STATUS_ARCHIVED, $entry->fresh()->status);
    }

    public function test_admin_bulk_transition_skips_entry_archived_after_queue_read(): void
    {
        $staleBulkEntry = LogbookEntry::findOrFail($this->entrySubmitted->id);
        $currentEntry = LogbookEntry::findOrFail($this->entrySubmitted->id);
        $transition = app(LogbookReviewTransition::class);

        $transition->apply($currentEntry, LogbookEntry::STATUS_ARCHIVED);

        $this->assertFalse($transition->tryApply($staleBulkEntry, LogbookEntry::STATUS_APPROVED));
        $this->assertSame(LogbookEntry::STATUS_ARCHIVED, $currentEntry->fresh()->status);
    }

    public function test_admin_bulk_still_approves_pending_but_does_not_touch_archived(): void
    {
        Notification::fake();
        $permission = Permission::firstOrCreate(['name' => 'admin.bulk-review', 'guard_name' => 'web']);
        $this->admin->givePermissionTo($permission);

        $this->actingAs($this->admin)->post(route('admin.bulk'), [
            'action' => 'approve', 'ids' => [$this->entrySubmitted->id],
        ])->assertRedirect()->assertSessionHas('success', '1 entri berhasil diproses.');
        $this->assertSame(LogbookEntry::STATUS_APPROVED, $this->entrySubmitted->fresh()->status);

        $this->entrySubmitted->update(['status' => LogbookEntry::STATUS_ARCHIVED]);
        $this->actingAs($this->admin)->post(route('admin.bulk'), [
            'action' => 'revisi', 'ids' => [$this->entrySubmitted->id],
            'feedback_dosen' => 'Perbaiki pembahasan hasil pengujian secara rinci.',
        ])->assertRedirect()->assertSessionHas('success', '0 entri berhasil diproses.');
        $this->assertSame(LogbookEntry::STATUS_ARCHIVED, $this->entrySubmitted->fresh()->status);
    }
}
