<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_name',
        'institution_name',
        'faculty',
        'study_program',
        'address',
        'city',
        'phone',
        'email',
        'admin_contact_email',
        'website',
        'logo_path',
        'footer_note',
        'max_upload_size_mb',
        'allowed_file_types',
        'seminar_hardcopy_note',
        'email_verification_required',
        'email_verification_override',
        'weekly_digest_enabled',
        'daily_reminder_enabled',
        'chat_digest_enabled',
        'storage_limit_mb',
        'mail_mailer',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    protected function casts(): array
    {
        return [
            'email_verification_required' => 'boolean',
            'email_verification_override' => 'boolean',
            'weekly_digest_enabled' => 'boolean',
            'daily_reminder_enabled' => 'boolean',
            'storage_limit_mb' => 'integer',
        ];
    }

    /**
     * Password SMTP disimpan terenkripsi di DB. Saat dibaca, dekripsi kembali.
     * Nilai lama yang masih plaintext ditangani (fallback ke apa adanya).
     */
    public function getMailPasswordAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value; // belum terenkripsi (data lama)
        }
    }

    /**
     * Saat disimpan, password selalu dienkripsi (jangan simpan plaintext).
     */
    public function setMailPasswordAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['mail_password'] = null;
            return;
        }

        $this->attributes['mail_password'] = Crypt::encryptString($value);
    }

    /**
     * Status verifikasi email yang EFEKTIF (default + override admin):
     * - override eksplisit (on/off) menang mutlak;
     * - override null (Auto) = ikuti apakah SMTP sungguhan terkonfigurasi.
     */
    public function emailVerificationEffective(): bool
    {
        if ($this->email_verification_override !== null) {
            return (bool) $this->email_verification_override;
        }

        return \App\Support\Feature::smtpConfigured();
    }

    /**
     * Versi statis (query fresh, tanpa cache) untuk controller/middleware auth.
     */
    public static function emailVerificationRequiredNow(): bool
    {
        $institution = static::query()->first();

        return $institution ? $institution->emailVerificationEffective() : false;
    }

    /**
     * Ambil profil institusi aktif (single-row), di-cache.
     * Ini adalah fallback global — dipakai pre-auth, console command,
     * dan queue worker tanpa konteks user.
     */
    public static function active(): self
    {
        return Cache::remember('institution.active', now()->addDay(), function () {
            return static::first() ?? static::create([
                'app_name' => 'Logbook',
                'institution_name' => 'Perguruan Tinggi',
                'email' => 'no-reply@example.com',
                // Default: user TIDAK wajib verifikasi email saat mendaftar.
                // System admin dapat mengaktifkan di panel Pengaturan.
                'email_verification_required' => false,
            ]);
        });
    }

    /**
     * Ambil institusi berdasarkan ID, di-cache per-ID.
     * Fallback ke active() jika ID null atau tidak ditemukan.
     */
    public static function forInstitutionId(?int $id): self
    {
        if (!$id) {
            return self::active();
        }

        return Cache::remember("institution.by-id.{$id}", now()->addDay(), function () use ($id) {
            return static::find($id) ?? self::active();
        });
    }

    /**
     * Ambil institusi yang relevan untuk user tertentu.
     */
    public static function forUser(?User $user): self
    {
        return self::forInstitutionId($user?->institution_id);
    }

    /**
     * Ambil institusi yang relevan untuk user yang sedang login.
     */
    public static function current(): self
    {
        return self::forUser(auth()->user());
    }

    /**
     * Email kontak admin untuk ditampilkan sebagai info bantuan.
     * Prioritas: admin_contact_email institusi user → fallback ke default
     * global (diisi system admin di panel pengaturan). Untuk user tanpa
     * institusi (mis. guest register/login) → default global.
     */
    public static function adminContactEmailFor(?User $user): ?string
    {
        return self::forUser($user)?->admin_contact_email
            ?: self::active()->admin_contact_email;
    }

    /**
     * Catatan default untuk form hardcopy seminar/sidang.
     * Mengembalikan string (bukan null) walau kolom DB null / model cached lama.
     */
    public function getSeminarHardcopyNoteAttribute($value): string
    {
        return (string) ($value ?? '');
    }

    /**
     * Sinkron nilai brand + pengaturan email ke konfigurasi yang berjalan.
     * Dipanggil di AppServiceProvider::boot() setiap request.
     */
    public function applyToConfig(): void
    {
        config(['app.name' => $this->app_name ?: 'Logbook']);

        // From address: prioritas mail_from_address, fallback email institusi.
        $fromAddress = $this->mail_from_address ?: $this->email;
        $fromName = $this->mail_from_name ?: ($this->app_name ?: 'Logbook');

        if ($fromAddress) {
            config(['mail.from.address' => $fromAddress]);
            config(['mail.from.name' => $fromName]);
        }

        // Pengaturan SMTP dinamis (bisa diisi admin).
        if ($this->mail_mailer && ($this->mail_mailer !== 'smtp' || $this->mail_host)) {
            config(['mail.default' => $this->mail_mailer]);
        }

        if ($this->mail_host) {
            config(['mail.mailers.smtp.host' => $this->mail_host]);
        }
        if ($this->mail_port) {
            config(['mail.mailers.smtp.port' => (int) $this->mail_port]);
        }
        if ($this->mail_username !== null) {
            config(['mail.mailers.smtp.username' => $this->mail_username]);
        }
        if ($this->mail_password !== null) {
            config(['mail.mailers.smtp.password' => $this->mail_password]);
        }
        if ($this->mail_encryption !== null) {
            config(['mail.mailers.smtp.encryption' => $this->mail_encryption ?: null]);
        }
    }

    public static function flush(?int $institutionId = null): void
    {
        Cache::forget('institution.active');
        if ($institutionId) {
            Cache::forget("institution.by-id.{$institutionId}");
        }
    }

    /**
     * Apakah verifikasi email efektif wajib (mengikuti override admin /
     * default SMTP). Dipertahankan agar panggil lama tetap konsisten.
     */
    public function emailVerificationRequired(): bool
    {
        return $this->emailVerificationEffective();
    }

    /**
     * Apakah digest mingguan (Senin 07:00) aktif untuk institusi ini.
     * Default true (perilaku lama) bila kolom null — mis. baris yang dibuat
     * sebelum migrasi toggle.
     */
    public function isWeeklyDigestEnabled(): bool
    {
        return $this->weekly_digest_enabled ?? true;
    }

    /**
     * Apakah reminder harian 08:00 (logbook:send-reminders + ta:notify-inactive)
     * aktif untuk institusi ini. Default true (perilaku lama).
     */
    public function isDailyReminderEnabled(): bool
    {
        return $this->daily_reminder_enabled ?? true;
    }

    /**
     * Apakah digest chat tertunda (pesan belum dibaca H+2 + eskalasi H+7)
     * aktif untuk institusi ini (default ON = perilaku baru).
     */
    public function isChatDigestEnabled(): bool
    {
        return $this->chat_digest_enabled ?? true;
    }

    /**
     * Batas ukuran file upload (MB) yang diatur admin.
     */
    public function maxUploadSizeMb(): int
    {
        return (int) ($this->max_upload_size_mb ?: 10);
    }

    /**
     * Daftar jenis file yang diizinkan (array). Default: pdf.
     */
    public function allowedFileTypes(): array
    {
        $raw = $this->allowed_file_types ?: 'pdf';

        return array_values(array_filter(array_map(
            fn ($t) => strtolower(trim($t)),
            explode(',', $raw)
        )));
    }

    /**
     * String mime/accept untuk input file (mis. "application/pdf,.doc,.docx").
     */
    public function fileAccept(): string
    {
        $map = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'zip' => 'application/zip',
        ];

        $accepts = [];
        foreach ($this->allowedFileTypes() as $ext) {
            if (isset($map[$ext])) {
                $accepts[] = $map[$ext];
            } else {
                $accepts[] = '.'.$ext;
            }
        }

        return implode(',', $accepts);
    }
}
