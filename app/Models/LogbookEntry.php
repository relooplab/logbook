<?php

namespace App\Models;

use App\Notifications\ActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class LogbookEntry extends Model
{
    use HasFactory;

    /** Jenis submission. */
    public const JENIS_LOGBOOK = 'logbook';

    public const JENIS_REVISI = 'revisi';

    /** Status workflow. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REVISI = 'revisi';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_REVISION_IN_PROGRESS = 'revision_in_progress';

    /** Status perbaikan pada tabel riwayat perbaikan. */
    public const PERBAIKAN_SUDAH = 'Sudah';

    public const PERBAIKAN_SEBAGIAN = 'Sebagian';

    public const PERBAIKAN_BELUM = 'Belum';

    public const PERBAIKAN_STATUSES = [self::PERBAIKAN_SUDAH, self::PERBAIKAN_SEBAGIAN, self::PERBAIKAN_BELUM];

    public const MAX_REVISION_ROUND = 3;

    public const JENISES = [self::JENIS_LOGBOOK, self::JENIS_REVISI];

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REVISI,
        self::STATUS_ARCHIVED,
        self::STATUS_REVISION_IN_PROGRESS,
    ];

    /**
     * Label status operasional (bahasa yang jelas & tidak ambigu).
     */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draf',
        self::STATUS_SUBMITTED => 'Menunggu Review',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REVISI => 'Revisi Diminta',
        self::STATUS_ARCHIVED => 'Diarsipkan',
        self::STATUS_REVISION_IN_PROGRESS => 'Revisi sedang dikerjakan',
    ];

    /**
     * Label status tampilan. Entri yang sudah punya revisi anak (terkunci)
     * ditampilkan "Terkunci" — semua perbaikan lewat jalur revisi baru.
     */
    public function statusLabel(): string
    {
        if ($this->status !== self::STATUS_ARCHIVED && $this->isLockedByActiveRevision()) {
            return 'Terkunci';
        }

        return self::STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    protected $fillable = [
        'mahasiswa_ta_id',
        'parent_entry_id',
        'revision_round',
        'dosen_id',
        'tanggal_bimbingan',
        'tanggal_pengiriman',
        'topik',
        'sesi_ke',
        'jenis',
        'progres_kendala',
        'riwayat_perbaikan',
        'lampiran_path',
        'lampiran_size',
        'lampiran_original_name',
        'catatan_perbaikan_path',
        'catatan_perbaikan_size',
        'catatan_original_name',
        'feedback_dosen',
        'archive_reason',
        'archived_at',
        'archived_by',
        'feedback_note',
        'status',
        'submitted_at',
        'reviewed_at',
        'review_opened_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_bimbingan' => 'date',
            'tanggal_pengiriman' => 'date',
            'sesi_ke' => 'integer',
            'revision_round' => 'integer',
            'riwayat_perbaikan' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'archived_at' => 'datetime',
            'review_opened_at' => 'datetime',
        ];
    }

    public function mahasiswaTa(): BelongsTo
    {
        return $this->belongsTo(MahasiswaTa::class);
    }

    /** Entri asal yang direvisi oleh entri ini (parent). */
    public function parentEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entry_id');
    }

    /** Entri-entri revisi yang merujuk ke entri ini (children). */
    public function revisionChildren(): HasMany
    {
        return $this->hasMany(self::class, 'parent_entry_id');
    }

    public function dosen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dosen_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PdfComment::class, 'logbook_entry_id');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class, 'logbook_entry_id');
    }

    public function scopeJenis($query, $jenis): Builder
    {
        return $query->where('jenis', $jenis);
    }

    public function scopeStatus($query, $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * True when the entry still allows editing by the owner (draft).
     * Begitu entri sudah masuk alur review (submitted/approved/revisi),
     * entri tidak boleh diedit langsung; perbaikan harus lewat jalur revisi baru.
     */
    public function isEditable(): bool
    {
        if ($this->isLockedByActiveRevision()) {
            return false;
        }

        // Draft biasa, atau draft revisi anak yang belum pernah di-submit
        // (status revision_in_progress dengan submitted_at masih kosong).
        return $this->status === self::STATUS_DRAFT
            || ($this->status === self::STATUS_REVISION_IN_PROGRESS && $this->submitted_at === null);
    }

    /**
     * Entri dikunci permanen dari edit/submit ulang bila sudah pernah menjadi
     * parent revisi. Semua perbaikan selanjutnya harus lewat jalur revisi baru.
     */
    public function isLockedByActiveRevision(): bool
    {
        if (array_key_exists('revision_children_exists', $this->attributes)) {
            return (bool) $this->attributes['revision_children_exists'];
        }

        return $this->revisionChildren()->exists();
    }

    /**
     * Apakah entri ini sudah melewati batas sesi revisi yang wajar.
     */
    public function exceedsRevisionRoundLimit(): bool
    {
        return $this->jenis === self::JENIS_REVISI
            && $this->revision_round >= self::MAX_REVISION_ROUND;
    }

    /**
     * Tanggal yang ditampilkan: Tanggal Bimbingan untuk logbook,
     * Tanggal Pengiriman untuk revisi (agar tidak kosong).
     */
    public function getTanggalTampilAttribute(): ?Carbon
    {
        return $this->jenis === self::JENIS_REVISI
            ? ($this->tanggal_pengiriman ?? $this->submitted_at?->toDate())
            : $this->tanggal_bimbingan;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Entri yang sudah disetujui masih bisa dibuka kembali oleh dosen
     * pembimbing (batal ke menunggu-review atau minta revisi lagi).
     */
    public function isReopenable(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Penerima yang dipilih mahasiswa tersimpan di dosen_id untuk logbook dan
     * revisi. Entri lama tanpa dosen_id tetap jatuh ke pembimbing program.
     */
    public function reviewDosen(): ?User
    {
        $ta = $this->mahasiswaTa;

        if ($this->dosen_id) {
            return $this->dosen;
        }

        if ($ta) {
            if ($ta->pembimbing_1_id) {
                return User::find($ta->pembimbing_1_id);
            }
            if ($ta->pembimbing_2_id) {
                return User::find($ta->pembimbing_2_id);
            }
        }

        // Entri revisi tanpa dosen_id: pakai dosen_id entri asal (parent).
        if ($this->parent_entry_id) {
            return $this->parentEntry?->dosen;
        }

        return null;
    }

    /**
     * Notify pemilik TA + pembimbing (DB + email) dengan pesan tertentu.
     */
    public function notifyParties(string $message, ?string $url = null, string $subject = 'Pemberitahuan Logbook'): void
    {
        $recipients = [];
        if ($ownerId = $this->mahasiswaTa?->user_id) {
            $recipients[] = $ownerId;
        }
        if ($dosen = $this->reviewDosen()) {
            $recipients[] = $dosen->id;
        }

        $recipients = array_unique(array_filter($recipients));

        foreach ($recipients as $id) {
            if ($user = User::find($id)) {
                try {
                    $user->notify(new ActivityNotification($message, $url, $subject));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * Notify pembimbing bahwa ada entri baru menunggu review.
     */
    public function notifyDosen(string $message, ?string $url = null, string $subject = 'Entri Baru Menunggu Review'): void
    {
        if ($dosen = $this->reviewDosen()) {
            try {
                $dosen->notify(new ActivityNotification($message, $url, $subject));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Notify penerima entri dan pembimbing program (CC) tanpa duplikasi.
     */
    public function notifyReviewers(string $message, ?string $url = null, string $subject = 'Entri Baru Menunggu Review'): void
    {
        $recipients = [$this->reviewDosen()?->id];

        $recipients[] = $this->mahasiswaTa?->pembimbing_1_id;
        $recipients[] = $this->mahasiswaTa?->pembimbing_2_id;

        $recipients = array_unique(array_filter($recipients));

        foreach ($recipients as $id) {
            if ($user = User::find($id)) {
                try {
                    $user->notify(new ActivityNotification($message, $url, $subject));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /** Isi notifikasi pengiriman entri, termasuk identitas mahasiswa pemilik program. */
    public function reviewSubmissionMessage(?string $recipientRole = null): string
    {
        $student = $this->mahasiswaTa?->mahasiswa;
        $identity = $student?->name ?: 'Mahasiswa';
        if ($student?->nim) {
            $identity .= ' (NIM '.$student->nim.')';
        }

        $entry = $this->jenis === self::JENIS_REVISI
            ? 'entri revisi'
            : 'entri logbook sesi '.$this->sesi_ke;

        return $identity.' mengirim '.$entry.' untuk direview'
            .($recipientRole ? ' oleh '.$recipientRole : '').'.';
    }
}
