<?php

namespace Tests\Feature;

use App\Models\DirectorySubscription;
use App\Models\Institution;
use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\InactivityReminderNotification;
use App\Notifications\ReminderNotification;
use App\Notifications\SeminarSubmissionNotification;
use App\Notifications\StorageQuotaWarningNotification;
use App\Notifications\SubscriptionExpiringNotification;
use App\Notifications\VerifyEmail;
use App\Notifications\WeeklyDigestNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Branding email notifikasi:
 *  - Semua notifikasi memakai tema clean-minimal (token brand).
 *  - Header/footer memuat wordmark two-tone Log (biru #4a6fc0) +
 *    book (emas #b8903f) via inline style — bukan class Tailwind
 *    (tidak dirender klien email).
 *  - Nama aplikasi kustom tetap satu warna (tanpa emas two-tone).
 */
class EmailBrandingTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('institution.active');
        $inst = Institution::first();
        if ($inst) {
            $inst->update(['app_name' => 'Logbook']);
        }
        Institution::active()->applyToConfig();
        Cache::forget('institution.active');

        Role::firstOrCreate(['name' => 'mahasiswa', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Penerima Email',
            'email' => 'penerima-'.uniqid().'@t.test',
            'password' => bcrypt('password'),
            'nim' => 'NIM'.substr(md5(uniqid()), 0, 8),
            'whatsapp' => '6281234567890',
            'registration_status' => 'active',
        ]);
        $this->user->assignRole('mahasiswa');
    }

    private function renderHtml(object $message): string
    {
        return (string) $message->render();
    }

    public function test_all_notifications_use_clean_minimal_theme(): void
    {
        $subscription = new DirectorySubscription([
            'scope_type' => DirectorySubscription::SCOPE_FACULTY,
            'scope_id' => 999999,
            'status' => DirectorySubscription::STATUS_ACTIVE,
        ]);
        $subscription->id = 1;

        $messages = [
            (new ActivityNotification('Aktivitas baru'))->toMail($this->user),
            (new WeeklyDigestNotification('Ringkasan'))->toMail($this->user),
            (new ReminderNotification('Pengingat'))->toMail($this->user),
            (new InactivityReminderNotification('7', '1 Jan 2026', 'Proposal', 'http://localhost'))->toMail($this->user),
            (new StorageQuotaWarningNotification(80, 800, 1000))->toMail($this->user),
            (new SubscriptionExpiringNotification($subscription, 'expiring'))->toMail($this->user),
            (new VerifyEmail)->toMail($this->user),
        ];

        foreach ($messages as $message) {
            $this->assertSame('clean-minimal', $message->theme);
        }
    }

    public function test_rendered_email_contains_two_tone_wordmark_with_inline_colors(): void
    {
        $html = $this->renderHtml((new ActivityNotification('Aktivitas baru'))->toMail($this->user));

        // Wordmark header: Log biru + book emas, inline style (bukan class Tailwind).
        $this->assertStringContainsString('>Log</span>', $html);
        $this->assertStringContainsString('>book</span>', $html);
        $this->assertMatchesRegularExpression('/color:\s*#4a6fc0/i', $html);
        $this->assertMatchesRegularExpression('/color:\s*#b8903f/i', $html);
        $this->assertStringNotContainsString('text-accent-blue', $html);
        $this->assertStringNotContainsString('text-status-pending', $html);
    }

    public function test_custom_app_name_renders_single_color_without_gold(): void
    {
        // Nama kustom diambil dari DB institusi (toMail me-resolve ulang
        // config dari DB), bukan dari config mentah.
        Cache::forget('institution.active');
        Cache::forget('institution.by-id.1');
        Institution::query()->update(['app_name' => 'Portal Akademik Kita']);
        Cache::forget('institution.active');
        Cache::forget('institution.by-id.1');

        $html = $this->renderHtml((new ReminderNotification('Pengingat'))->toMail($this->user));

        $this->assertStringContainsString('Portal Akademik Kita', $html);
        $this->assertStringNotContainsString('#b8903f', strtolower($html));
    }

    public function test_button_uses_brand_blue_token(): void
    {
        $html = $this->renderHtml(
            (new ReminderNotification('Klik tombol di bawah', 'http://localhost/buka'))->toMail($this->user)
        );

        $this->assertStringContainsString('Buka Aplikasi', $html);
        $this->assertMatchesRegularExpression('/#4a6fc0/i', $html);
    }
}
