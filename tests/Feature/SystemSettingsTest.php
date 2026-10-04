<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;

/**
 * Verifikasi halaman Pengaturan Autentikasi di panel system admin:
 * toggle "Wajib Verifikasi Email" + form SMTP.
 */
class SystemSettingsTest extends AuditSmokeTest
{
    use DatabaseTransactions;

    private function systemAdmin(): User
    {
        Role::firstOrCreate(['name' => 'system_admin', 'guard_name' => 'web']);
        $uid = uniqid();
        $sys = User::create([
            'name' => 'Sys Admin Settings', 'email' => "sys-settings-{$uid}@audit.test",
            'password' => bcrypt('x'), 'registration_status' => 'active',
            'nim' => "SYS-SET-{$uid}", 'whatsapp' => '628',
        ]);
        $sys->assignRole('system_admin');

        return $sys;
    }

    public function test_system_admin_can_view_settings_page(): void
    {
        $response = $this->actingAs($this->systemAdmin())
            ->get(route('admin.system.settings'));

        $response->assertOk();
        $response->assertSee('Pengaturan Autentikasi');
        $response->assertSee('Verifikasi Email');
    }

    public function test_non_system_admin_cannot_access_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.system.settings'));

        $response->assertForbidden();
    }

    public function test_override_tidak_persists(): void
    {
        $sys = $this->systemAdmin();

        $response = $this->actingAs($sys)
            ->post(route('admin.system.settings.update'), [
                'email_verification_override' => 'tidak',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertFalse(
            (bool) Institution::active()->fresh()->email_verification_override,
            'Override "tidak" harus tersimpan sebagai false.'
        );
    }

    public function test_smtp_fields_optional(): void
    {
        // SMTP kini opsional — menyimpan override tanpa SMTP harus sukses.
        $sys = $this->systemAdmin();

        $response = $this->actingAs($sys)
            ->post(route('admin.system.settings.update'), [
                'email_verification_override' => 'wajib',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_override_wajib_with_smtp_succeeds(): void
    {
        $sys = $this->systemAdmin();

        $response = $this->actingAs($sys)
            ->post(route('admin.system.settings.update'), [
                'email_verification_override' => 'wajib',
                'mail_mailer' => 'log',
                'mail_host' => 'smtp.test.local',
                'mail_port' => 1025,
                'mail_username' => 'user@test.local',
                'mail_password' => 'secret',
                'mail_encryption' => 'tls',
                'mail_from_address' => 'no-reply@test.local',
                'mail_from_name' => 'Test Sender',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $inst = Institution::active()->fresh();
        $this->assertTrue((bool) $inst->email_verification_override, 'Override "wajib" harus tersimpan true.');
        $this->assertSame('log', $inst->mail_mailer);
        $this->assertSame('smtp.test.local', $inst->mail_host);
        $this->assertSame(1025, (int) $inst->mail_port);
    }

    public function test_smtp_form_always_visible(): void
    {
        $sys = $this->systemAdmin();

        $response = $this->actingAs($sys)->get(route('admin.system.settings'));
        $response->assertOk();
        // Form SMTP selalu tampil (tanpa class hidden) & ada opsi Auto.
        $this->assertStringContainsString('id="smtp-form" class="space-y-4 pt-2 border-t border-border"', $response->getContent());
        $this->assertStringContainsString('Auto — ikuti SMTP', $response->getContent());
    }

    public function test_form_notifikasi_menampilkan_toggle_dengan_status_tersimpan(): void
    {
        $sys = $this->systemAdmin();

        $response = $this->actingAs($sys)->get(route('admin.system.settings'));

        $response->assertOk();
        $response->assertSee('Notifikasi Berkala');
        $response->assertSee('Digest Mingguan (Senin 07:00 WIB)');
        $response->assertSee('Reminder Harian (08:00 WIB)');
        $response->assertSee('Digest Chat Tertunda (per jam)');
    }

    public function test_mematikan_toggle_melalui_form_menyimpan_off(): void
    {
        $sys = $this->systemAdmin();
        $institution = Institution::current();
        $this->assertTrue($institution->isWeeklyDigestEnabled());
        $this->assertTrue($institution->isDailyReminderEnabled());
        $this->assertTrue($institution->isChatDigestEnabled());

        // Submit form notifikasi dengan kedua checkbox unchecked (absent).
        $this->actingAs($sys)->post(route('admin.system.settings.update'), [
            '_notification_form' => '1',
            'email_verification_override_keep' => 'auto',
            'mail_mailer_keep' => 'smtp',
        ])->assertRedirect()->assertSessionHas('success');

        $institution->refresh();
        $this->assertFalse($institution->isWeeklyDigestEnabled());
        $this->assertFalse($institution->isDailyReminderEnabled());
        $this->assertFalse($institution->isChatDigestEnabled());
    }

    public function test_menyalakan_toggle_melalui_form_menyimpan_on(): void
    {
        $sys = $this->systemAdmin();
        Institution::current()->update([
            'weekly_digest_enabled' => false,
            'daily_reminder_enabled' => false,
            'chat_digest_enabled' => false,
        ]);

        $this->actingAs($sys)->post(route('admin.system.settings.update'), [
            '_notification_form' => '1',
            'email_verification_override_keep' => 'auto',
            'mail_mailer_keep' => 'smtp',
            'weekly_digest_enabled' => '1',
            'daily_reminder_enabled' => '1',
            'chat_digest_enabled' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $institution = Institution::current()->fresh();
        $this->assertTrue($institution->isWeeklyDigestEnabled());
        $this->assertTrue($institution->isDailyReminderEnabled());
        $this->assertTrue($institution->isChatDigestEnabled());
    }

    public function test_form_autentikasi_tidak_mengubah_toggle(): void
    {
        $sys = $this->systemAdmin();
        Institution::current()->update([
            'weekly_digest_enabled' => false,
            'daily_reminder_enabled' => false,
            'chat_digest_enabled' => false,
        ]);

        // Submit form autentikasi/SMTP (tanpa _notification_form) — toggle tetap OFF.
        $this->actingAs($sys)->post(route('admin.system.settings.update'), [
            'email_verification_override' => 'tidak',
        ])->assertRedirect()->assertSessionHas('success');

        $institution = Institution::current()->fresh();
        $this->assertFalse($institution->isWeeklyDigestEnabled());
        $this->assertFalse($institution->isDailyReminderEnabled());
        $this->assertFalse($institution->isChatDigestEnabled());
        $this->assertFalse((bool) $institution->email_verification_override);
    }
}
