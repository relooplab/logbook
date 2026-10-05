<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'institution_storage_limit_mb',
        'name',
        'email',
        'password',
        'nim',
        'nidn',
        'registration_status',
        'profile_photo_path',
        'whatsapp',
        'telegram',
        'linkedin',
        'google_scholar',
        'orcid',
        'sinta',
        'researchgate',
        'jadwal_bimbingan_url',
        'bimbingan_via_whatsapp',
        'bimbingan_via_telegram',
        'last_active_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * Kirim notifikasi verifikasi email. Dipakai Laravel saat user wajib
     * verifikasi (MustVerifyEmail) atau via endpoint resend.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmail);
    }

    /**
     * Cari user berdasarkan NIM atau NIDN (identitas unik lintas kolom).
     * Dipakai untuk deteksi "akun sudah terdaftar".
     */
    public static function findByIdentifier(string $nimOrNidn): ?User
    {
        return static::where('nim', $nimOrNidn)
            ->orWhere('nidn', $nimOrNidn)
            ->first();
    }

    /**
     * Apakah nilai NIM/NIDN sudah dipakai oleh user lain (lintas kolom nim & nidn)?
     * Mencegah satu nilai dipakai mahasiswa (NIM) dan dosen (NIDN) sekaligus.
     */
    public static function identifierIsTaken(string $value, ?int $ignoreUserId = null): bool
    {
        return static::where(fn ($q) => $q->where('nim', $value)->orWhere('nidn', $value))
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->exists();
    }

    /**
     * Institusi tempat user bergabung (NULL = mode individual).
     */
    public function institution(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Perguruan tinggi yang diikuti user (multi-universitas via pivot).
     */
    public function universities()
    {
        return $this->belongsToMany(University::class, 'user_university')
            ->withPivot('faculty_id', 'department_id', 'study_program_id', 'is_primary', 'status', 'approved_by', 'approved_at', 'rejection_reason')
            ->withTimestamps();
    }

    /**
     * Perguruan tinggi utama (is_primary = true), atau yang pertama.
     * Memprioritaskan afiliasi yang BERSTATUS ACTIVE agar tidak menampilkan
     * afiliasi pending/revoked sebagai afiliasi utama.
     */
    public function primaryUniversity(): ?University
    {
        return $this->universities()
            ->wherePivot('is_primary', true)
            ->wherePivot('status', 'active')
            ->first()
            ?? $this->universities()->wherePivot('status', 'active')->first()
            ?? $this->universities()->first();
    }

    /**
     * Langganan user (subscriptions).
     */
    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Override paket oleh admin (per-user custom).
     */
    public function planOverride(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserPlanOverride::class);
    }

    /**
     * Top-up storage individual (additive di atas base plan/direktori).
     */
    public function storageAddons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserStorageAddon::class);
    }

    /**
     * Pembatasan cakupan admin (prodi/departemen/fakultas).
     * Kosong = institusi penuh.
     */
    public function adminScopes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AdminScope::class);
    }

    /**
     * Paket aktif user (plan dari subscription aktif terbaru).
     */
    public function activePlan(): ?Plan
    {
        $subscription = $this->subscriptions()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()
            ->first();

        return $subscription?->plan;
    }

    /**
     * Apakah user ini memiliki "hubungan langsung" dengan user lain.
     * Berlaku untuk dosen: true jika mereka sama-sama pembimbing/penguji
     * pada TA yang sama, ATAU berada dalam grup yang sama (approved).
     * Membatasi akses data hanya pada hubungan langsung.
     */
    public function hasDirectRelation(User $other): bool
    {
        if ($this->id === $other->id) {
            return true;
        }

        // Admin selalu punya akses melihat siapa saja (bukan sebaliknya).
        if ($this->isAdmin()) {
            return true;
        }

        // Dosen dengan dosen: cek TA bersama atau grup bersama.
        if ($this->isDosen() && $other->isDosen()) {
            // TA bersama (pembimbing/penguji sama).
            $sharedTa = MahasiswaTa::where(function ($q) use ($other) {
                $q->whereIn('pembimbing_1_id', [$this->id, $other->id])
                    ->orWhereIn('pembimbing_2_id', [$this->id, $other->id])
                    ->orWhereIn('penguji_1_id', [$this->id, $other->id])
                    ->orWhereIn('penguji_2_id', [$this->id, $other->id]);
            })->exists();

            if ($sharedTa) {
                return true;
            }

            // Grup bersama (approved).
            $sharedGroup = \DB::table('group_members as a')
                ->join('group_members as b', 'a.group_id', '=', 'b.group_id')
                ->where('a.user_id', $this->id)
                ->where('b.user_id', $other->id)
                ->where('a.status', 'approved')
                ->where('b.status', 'approved')
                ->exists();

            return $sharedGroup;
        }

        // Dosen dengan mahasiswa (kedua arah): cek TA bimbingan/pengujian.
        if (($this->isDosen() && $other->isMahasiswa()) || ($this->isMahasiswa() && $other->isDosen())) {
            $dosenId = $this->isDosen() ? $this->id : $other->id;
            $mahasiswaId = $this->isMahasiswa() ? $this->id : $other->id;

            return MahasiswaTa::where('user_id', $mahasiswaId)
                ->where(fn ($q) => $q->where('pembimbing_1_id', $dosenId)
                    ->orWhere('pembimbing_2_id', $dosenId)
                    ->orWhere('penguji_1_id', $dosenId)
                    ->orWhere('penguji_2_id', $dosenId))
                ->exists();
        }

        return false;
    }

    /**
     * ID dosen yang memiliki hubungan langsung dengan user ini.
     * Mencakup: dosen dalam grup yang sama (approved) + dosen yang
     * berbagi TA (pembimbing/penguji) dengan user ini.
     */
    public function relatedDosenIds(): array
    {
        if (!$this->isDosen()) {
            return [];
        }

        $ids = [];

        // Dosen dalam grup yang sama (approved).
        $groupIds = \DB::table('group_members as mine')
            ->where('mine.user_id', $this->id)
            ->where('mine.status', 'approved')
            ->pluck('mine.group_id');

        if ($groupIds->isNotEmpty()) {
            $groupMemberIds = \DB::table('group_members')
                ->whereIn('group_id', $groupIds)
                ->where('status', 'approved')
                ->where('user_id', '!=', $this->id)
                ->pluck('user_id')
                ->all();
            $ids = array_merge($ids, $groupMemberIds);
        }

        // Dosen yang berbagi TA (pembimbing/penguji) dengan user ini.
        $sharedTaIds = MahasiswaTa::where(fn ($q) => $q->where('pembimbing_1_id', $this->id)
            ->orWhere('pembimbing_2_id', $this->id)
            ->orWhere('penguji_1_id', $this->id)
            ->orWhere('penguji_2_id', $this->id))
            ->pluck('id');

        if ($sharedTaIds->isNotEmpty()) {
            $taDosenIds = MahasiswaTa::whereIn('id', $sharedTaIds)
                ->get()
                ->flatMap(fn ($ta) => $ta->allDosenIds())
                ->filter(fn ($id) => $id !== $this->id)
                ->all();
            $ids = array_merge($ids, $taDosenIds);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * URL foto profil (atau kosong jika belum ada).
     * Foto disimpan di disk 'public' agar dapat diakses via /storage.
     */
    public function photoUrl(): string
    {
        if ($this->profile_photo_path) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->profile_photo_path);
        }

        return '';
    }

    /**
     * Inisial untuk avatar fallback.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name));
        $init = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $init .= mb_substr($p, 0, 1);
        }

        return strtoupper($init ?: '?');
    }

    /**
     * Normalisasi nomor WhatsApp ke tautan.
     */
    public function whatsappUrl(): ?string
    {
        if (!$this->whatsapp) return null;
        $num = preg_replace('/[^0-9]/', '', $this->whatsapp);
        if ($num === '') return null;

        return 'https://wa.me/'.$num;
    }

    /**
     * Normalisasi username Telegram ke tautan.
     */
    public function telegramUrl(): ?string
    {
        if (!$this->telegram) return null;
        $username = ltrim(trim($this->telegram), '@');
        if ($username === '') return null;

        return 'https://t.me/'.$username;
    }

    /**
     * Jalur kontak bimbingan yang aktif untuk dosen ini.
     * Hanya mengembalikan jalur yang di-opt-in DAN datanya terisi.
     *
     * @return array<int, array{key: string, label: string, url: string, icon: string}>
     */
    public function bimbinganChannels(): array
    {
        $channels = [];

        if ($this->jadwal_bimbingan_url) {
            $channels[] = [
                'key' => 'external',
                'label' => 'Buka Jadwalkan Bimbingan',
                'url' => $this->jadwal_bimbingan_url,
                'icon' => 'calendar_month',
            ];
        }

        if ($this->bimbingan_via_whatsapp && $this->whatsappUrl()) {
            $channels[] = [
                'key' => 'whatsapp',
                'label' => 'Hubungi WhatsApp',
                'url' => $this->whatsappUrl(),
                'icon' => 'chat',
            ];
        }

        if ($this->bimbingan_via_telegram && $this->telegramUrl()) {
            $channels[] = [
                'key' => 'telegram',
                'label' => 'Hubungi Telegram',
                'url' => $this->telegramUrl(),
                'icon' => 'send',
            ];
        }

        return $channels;
    }

    /**
     * Convenience role helpers.
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin', 'system_admin']);
    }

    /**
     * Apakah user ini adalah System Admin (role tertinggi).
     */
    public function isSystemAdmin(): bool
    {
        return $this->hasRole('system_admin');
    }

    /**
     * Achievement yang telah di-unlock user ini.
     */
    public function achievements(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Achievement::class, 'user_achievements')
            ->withPivot('unlocked_at')
            ->withTimestamps();
    }

    public function isDosen(): bool
    {
        return $this->hasRole('dosen');
    }

    public function isMahasiswa(): bool
    {
        return $this->hasRole('mahasiswa');
    }

    /**
     * Mahasiswa sudah verifikasi email (aktif) — belum tentu attach dosen.
     */
    public function isActive(): bool
    {
        return $this->isMahasiswa() && $this->registration_status === 'active';
    }

    /**
     * Mahasiswa sudah verified — sudah punya MahasiswaTa dengan dosen (disetujui).
     */
    public function isVerified(): bool
    {
        return $this->isMahasiswa() && $this->registration_status === 'verified';
    }

    /**
     * Program (TA/KP) yang menunggu keputusan dosen ini (sebagai pembimbing/
     * penguji yang dipilih mahasiswa). Dipakai untuk papan peringatan.
     */
    public function pendingApprovals(): \Illuminate\Database\Eloquent\Collection
    {
        return MahasiswaTa::where('status_ta', MahasiswaTa::STATUS_PENDING_APPROVAL)
            ->where(fn ($q) => $q->where('pembimbing_1_id', $this->id)
                ->orWhere('pembimbing_2_id', $this->id)
                ->orWhere('penguji_1_id', $this->id)
                ->orWhere('penguji_2_id', $this->id))
            ->with('mahasiswa')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Benarkah ada mahasiswa pending yang sudah melewati batas waktu (hari)?
     * Dipakai gate keras "harus diputuskan" (gate lunak: blokir setelah lewat batas).
     */
    public function hasPendingApprovalOverdue(int $days): bool
    {
        $cutoff = now()->subDays(max(1, $days));

        return MahasiswaTa::where('status_ta', MahasiswaTa::STATUS_PENDING_APPROVAL)
            ->where(fn ($q) => $q->where('pembimbing_1_id', $this->id)
                ->orWhere('pembimbing_2_id', $this->id)
                ->orWhere('penguji_1_id', $this->id)
                ->orWhere('penguji_2_id', $this->id))
            ->where('created_at', '<', $cutoff)
            ->exists();
    }

    /**
     * Ambang batas (menit) untuk dianggap "online" sejak terakhir aktif.
     */
    public const ONLINE_THRESHOLD_MINUTES = 5;

    /**
     * Apakah user sedang online (aktif dalam 5 menit terakhir).
     */
    public function isOnline(): bool
    {
        return $this->last_active_at !== null
            && $this->last_active_at->diffInMinutes(now()) < self::ONLINE_THRESHOLD_MINUTES;
    }

    /**
     * Status ringkas: 'online' | 'offline' | 'never'.
     */
    public function lastActiveStatus(): string
    {
        if ($this->last_active_at === null) {
            return 'never';
        }

        return $this->isOnline() ? 'online' : 'offline';
    }

    /**
     * Label "terakhir aktif" untuk ditampilkan (mis. "5 menit lalu").
     */
    public function lastActiveLabel(): string
    {
        if ($this->last_active_at === null) {
            return 'Belum pernah aktif';
        }

        return $this->last_active_at->diffForHumans();
    }

    /**
     * The TA record owned by this user (mahasiswa).
     */
    public function mahasiswaTa(): HasOne
    {
        return $this->hasOne(MahasiswaTa::class, 'user_id')
            ->where('jenis', MahasiswaTa::JENIS_TA);
    }

    /**
     * The KP record owned by this user (mahasiswa).
     */
    public function mahasiswaKp(): HasOne
    {
        return $this->hasOne(MahasiswaTa::class, 'user_id')
            ->where('jenis', MahasiswaTa::JENIS_KP);
    }

    /**
     * Program milik user ini sebagai pemilik utama (KP + TA).
     * Relasi standar (dipakai oleh whereHas/whereDoesntHave).
     */
    public function mahasiswaPrograms(): HasMany
    {
        return $this->hasMany(MahasiswaTa::class, 'user_id');
    }

    /**
     * Semua program yang diikuti user (pemilik utama ATAU anggota pivot KP).
     */
    public function allPrograms()
    {
        $memberProgramIds = \DB::table('mahasiswa_ta_members')
            ->where('user_id', $this->id)
            ->pluck('mahasiswa_ta_id');

        return MahasiswaTa::where('user_id', $this->id)
            ->orWhereIn('id', $memberProgramIds);
    }

    /**
     * Program yang sedang aktif (status_ta = aktif). Logbook bimbingan
     * otomatis masuk ke program ini. Termasuk program KP kelompok di mana
     * user menjadi anggota pivot.
     */
    public function programAktif(): ?MahasiswaTa
    {
        $memberProgramIds = \DB::table('mahasiswa_ta_members')
            ->where('user_id', $this->id)
            ->pluck('mahasiswa_ta_id');

        return MahasiswaTa::query()
            ->where('status_ta', MahasiswaTa::STATUS_AKTIF)
            ->where(function ($q) use ($memberProgramIds) {
                $q->where('user_id', $this->id)->orWhereIn('id', $memberProgramIds);
            })
            ->latest('id')
            ->first();
    }

    /**
     * Entries where this user is recorded as the guiding dosen.
     */
    public function guidedEntries(): HasMany
    {
        return $this->hasMany(LogbookEntry::class, 'dosen_id');
    }

    /**
     * TA records where this user acts as pembimbing 1 or 2.
     */
    public function supervisedTas(): HasMany
    {
        return $this->hasMany(MahasiswaTa::class, 'pembimbing_1_id');
    }

    /**
     * Pengumuman yang diterima user ini (via pivot).
     */
    public function announcements(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Announcement::class, 'announcement_recipients')
            ->withPivot('read_at')
            ->withTimestamps();
    }
}
