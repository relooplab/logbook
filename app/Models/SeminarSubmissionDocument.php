<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeminarSubmissionDocument extends Model
{
    use HasFactory;

    public const TYPE_FILE = 'file';

    public const TYPE_LINK = 'link';

    protected $fillable = [
        'seminar_submission_id',
        'type',
        'path',
        'original_name',
        'size',
        'url',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SeminarSubmission::class, 'seminar_submission_id');
    }

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    public function isLink(): bool
    {
        return $this->type === self::TYPE_LINK;
    }
}
