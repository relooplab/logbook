<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deterministik: simulasikan DB production pasca-migrasi rename,
        // apa pun state awal DB test.
        \Illuminate\Support\Facades\Cache::forget('institution.active');
        $inst = \App\Models\Institution::first();
        if ($inst) {
            $inst->update([
                'app_name' => 'Logbook',
                'admin_contact_email' => 'admin@example.test',
            ]);
        }
        \Illuminate\Support\Facades\Cache::forget('institution.active');
    }

    public function test_login_header_shows_two_tone_logbook_tagline_and_byline(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('<span class="text-accent-blue">Log</span><span class="text-status-pending">book</span>', false)
            ->assertSee('Aplikasi pencatatan &amp; monitoring bimbingan Tugas Akhir mahasiswa', false)
            ->assertSee('>ReLoop Lab</a>', false)
            ->assertDontSee('Campus Logbook Management');
    }

    public function test_register_header_matches_login_header(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('<span class="text-accent-blue">Log</span><span class="text-status-pending">book</span>', false)
            ->assertSee('Aplikasi pencatatan &amp; monitoring bimbingan Tugas Akhir mahasiswa', false)
            ->assertSee('>ReLoop Lab</a>', false)
            ->assertDontSee('Campus Logbook Management');
    }

    public function test_login_and_register_share_unified_footer(): void
    {
        foreach ([route('login'), route('register')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Kirim Masukan')
                ->assertSee('Hubungi admin')
                ->assertSee('aria-label="GitHub Logbook (tab baru)"', false)
                ->assertSee('aria-label="LinkedIn Reloop Lab (tab baru)"', false);
        }
    }

    public function test_login_has_no_duplicate_admin_or_version_blocks(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'Mengalami kendala? Hubungi admin:'));
        $this->assertSame(1, preg_match_all('/v\d+\.\d+\.\d+/', $html));
    }

    public function test_wordmark_renders_two_tone_for_logbook_and_legacy_for_custom_names(): void
    {
        $logbook = view('partials.wordmark', ['name' => 'Logbook'])->render();
        $this->assertStringContainsString(
            '<span class="text-accent-blue">Log</span><span class="text-status-pending">book</span>',
            $logbook
        );

        $custom = view('partials.wordmark', ['name' => 'Portal Akademik Kita'])->render();
        $this->assertStringContainsString('Portal Akademik', $custom);
        $this->assertStringNotContainsString('text-status-pending', $custom);
    }

    public function test_landing_hero_uses_two_tone_logbook(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('<h1 id="hero-title" class="landing-display"><span class="text-accent-blue">Log</span><span class="text-status-pending">book</span></h1>', false)
            ->assertDontSee('Campus Logbook Management');
    }
}