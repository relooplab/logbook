<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['mahasiswa_ta_id', 'user_one_id', 'user_two_id', 'chat_digest_sent_at', 'chat_escalation_sent_at'];

    protected $casts = [
        'chat_digest_sent_at' => 'datetime',
        'chat_escalation_sent_at' => 'datetime',
    ];

    public function mahasiswaTa(): BelongsTo
    {
        return $this->belongsTo(MahasiswaTa::class);
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Pasangan lawan bicara untuk user tertentu.
     */
    public function other(User $user): User
    {
        return $user->id === $this->user_one_id ? $this->userTwo : $this->userOne;
    }

    public function hasUser(int $userId): bool
    {
        return $this->user_one_id === $userId || $this->user_two_id === $userId;
    }

    /**
     * Jumlah pesan belum dibaca oleh user.
     */
    public function unreadCountFor(int $userId): int
    {
        return $this->messages()->where('sender_id', '!=', $userId)->whereNull('read_at')->count();
    }
}
