<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'attachable_type',
        'attachable_id',
        'read_at',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Pesan baru membuka episode digest baru: digest/eskalasi
        // berikutnya dihitung ulang dari pesan ini (semua jalur kirim).
        static::created(function (Message $message) {
            $message->conversation()->update([
                'chat_digest_sent_at' => null,
                'chat_escalation_sent_at' => null,
            ]);
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function workspaceFiles(): HasMany
    {
        return $this->hasMany(MessageWorkspaceFile::class);
    }

    public function isEditable(): bool
    {
        // Edit hanya 15 menit pertama setelah dikirim.
        return $this->created_at && $this->created_at->gt(now()->subMinutes(15));
    }
}
