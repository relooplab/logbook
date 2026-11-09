<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceFile extends Model
{
    use HasFactory;

    /** Nama grup arsip di workspace (berkas lama yang diganti, tidak dihapus). */
    public const BAB_ARSIP = 'Arsip';

    /**
     * Batas berkas historis yang disimpan per field form. Saat mahasiswa
     * mengganti berkas lagi dan batas terlampaui, berkas terlama ditimpa.
     */
    public const MAX_ARSIP_PER_FIELD = 4;

    public const FIELD_UNDANGAN = 'undangan';

    public const FIELD_MATERI = 'materi';

    public const FIELD_DOKUMEN_TAMBAHAN = 'dokumen_tambahan';

    protected $fillable = [
        'user_id',
        'mahasiswa_ta_id',
        'uploaded_by',
        'bab',
        'original_name',
        'path',
        'mime_type',
        'size',
        'description',
        'is_archived',
        'archived_at',
        'archive_note',
        'archive_field',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }

    public function scopeNotArchived($query)
    {
        return $query->where('is_archived', false);
    }

    public function scopeArchivedOfField($query, string $field)
    {
        return $query->where('is_archived', true)->where('archive_field', $field);
    }

    public function isArchived(): bool
    {
        return (bool) $this->is_archived;
    }

    /**
     * Dosen yang berhak "memusnahkan" berkas arsip: pembimbing/penguji TA
     * pemilik berkas, atau admin.
     */
    public function canBePurgedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $ta = $this->mahasiswaTa;

        return $ta !== null && ($ta->isPembimbing($user) || $ta->isPenguji($user));
    }

    /**
     * Hapus: berkas arsip hanya oleh dosen/admin; berkas biasa oleh pemilik
     * (file pribadi dosen) atau anggota program (workspace TA).
     */
    public function canBeDeletedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isArchived()) {
            return $this->canBePurgedBy($user);
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->user_id) {
            return $this->user_id === $user->id;
        }

        return $this->mahasiswaTa?->isMember($user) ?? false;
    }

    /**
     * Ubah metadata (label bab & deskripsi). Berkas arsip dikunci agar
     * mahasiswa tidak bisa mengeluarkannya dari grup "Arsip".
     */
    public function canBeEditedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isArchived()) {
            return $this->canBePurgedBy($user);
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->user_id) {
            return $this->user_id === $user->id;
        }

        return $this->mahasiswaTa?->isMember($user) ?? false;
    }

    public function mahasiswaTa(): BelongsTo
    {
        return $this->belongsTo(MahasiswaTa::class, 'mahasiswa_ta_id');
    }

    /**
     * Pemilik workspace pribadi (dosen) — nullable, hanya untuk workspace dosen.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Ukuran dalam format ramah-baca.
     */
    public function sizeHuman(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }

    /**
     * Ikon berdasarkan tipe file.
     */
    public function icon(): string
    {
        $mime = strtolower((string) $this->mime_type);
        if (str_contains($mime, 'pdf')) return '📕';
        if (str_contains($mime, 'word') || str_contains($mime, 'doc')) return '📘';
        if (str_contains($mime, 'excel') || str_contains($mime, 'sheet') || str_contains($mime, 'spreadsheet')) return '📗';
        return '📄';
    }

    /**
     * True bila file adalah PDF (bisa preview inline).
     */
    public function isPdf(): bool
    {
        return str_contains(strtolower((string) $this->mime_type), 'pdf');
    }

    public function scopeByBab($q, $bab)
    {
        return $q->where('bab', $bab);
    }
}
