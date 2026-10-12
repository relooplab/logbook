<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'logbook_entry_id',
        'user_id',
        'feedback_dosen',
        'review_decision',
        'archive_reason',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LogbookEntry::class, 'logbook_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
