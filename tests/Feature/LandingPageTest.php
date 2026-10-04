<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\MahasiswaTa;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_guest_can_visit_public_landing_page(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('Bimbingan yang tertata')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false);
    }

    public function test_hero_uses_new_headline_and_moves_old_headline_to_eyebrow(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('<div class="landing-eyebrow landing-eyebrow-gold mb-6"><span class="landing-eyebrow-dot"></span><span>Bimbingan yang tertata, kemajuan yang terlihat.</span></div>', false)
            ->assertSee('<h1 id="hero-title" class="landing-display"><span class="text-accent-blue">Log</span><span class="text-status-pending">book</span></h1>', false)
            ->assertDontSee('Ruang kerja Tugas Akhir &amp; Kerja Praktik', false);
    }

    public function test_section_headings_use_rotating_token_accents(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('tidak harus <span class="landing-accent-gold">tercecer</span>', $html);
        $this->assertStringContainsString('<span class="text-accent-blue">tanpa memutus alur.</span>', $html);
        $this->assertStringContainsString('<span class="text-accent-teal">Sudut pandang yang tepat.</span>', $html);
        $this->assertStringContainsString('Dibuat untuk <span class="landing-accent-gold">pekerjaan nyata.</span>', $html);
        $this->assertStringContainsString('<span class="text-accent-purple">Kelola bersama.</span>', $html);
        $this->assertStringContainsString('<span class="text-accent-blue">Sebelum mulai.</span>', $html);
        $this->assertStringContainsString('<span class="landing-accent-gold">tempat yang semestinya.</span>', $html);
    }

    public function test_primary_buttons_use_gold_token_with_glow(): void
    {
        $css = file_get_contents(public_path('build/assets/'.basename(glob(public_path('build/assets/app-*.css'))[0])));

        $this->assertMatchesRegularExpression('/\.landing-button-primary\s*\{[^}]*status-pending/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-button-primary[^{]*\{[^}]*box-shadow/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-eyebrow-gold\s*\{[^}]*status-pending/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-accent-gold\s*\{[^}]*status-pending/s', $css);
        // Emas terang gagal AA di light mode (2.7:1) — pastikan override gelap #7d5f16 (5.5:1) ada.
        $this->assertMatchesRegularExpression('/html:not\(\.dark\) \.landing-accent-gold\s*\{\s*color:\s*#7d5f16/s', $css);
    }

    public function test_login_buttons_use_blue_token_with_glow(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();
        $css = file_get_contents(public_path('build/assets/'.basename(glob(public_path('build/assets/app-*.css'))[0])));

        // Tombol Masuk header + hero + CTA final memakai biru solid sejajar Daftar emas.
        $this->assertStringContainsString('landing-button landing-button-secondary">Masuk</a>', $html);
        $this->assertStringContainsString('landing-button landing-button-secondary landing-button-large">Sudah punya akun? Masuk</a>', $html);
        $this->assertStringContainsString('landing-button landing-button-secondary landing-button-large">Masuk</a>', $html);

        // Tidak ada lagi tombol outline/quiet yang tertinggal di beranda.
        $this->assertStringNotContainsString('landing-button-outline', $html);
        $this->assertStringNotContainsString('landing-button-quiet', $html);

        $this->assertMatchesRegularExpression('/\.landing-button-secondary\s*\{[^}]*accent-blue/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-button-secondary[^{]*\{[^}]*box-shadow/s', $css);
        // Teks gelap gagal AA di light mode (3.81:1) — pastikan override putih #fff (4.85:1) ada.
        $this->assertMatchesRegularExpression('/html:not\(\.dark\) \.landing-button-secondary\s*\{\s*color:\s*#fff/s', $css);
    }

    public function test_header_application_name_is_hidden_on_mobile(): void
    {
        $this->get(route('landing'))->assertOk()
            ->assertSee('data-header-app-name class="hidden sm:inline truncate text-sm sm:text-base"', false)
            ->assertSee('aria-label="Buka navigasi"', false)
            ->assertSee('data-theme-toggle', false);
    }

    public function test_dashboard_previews_use_presentation_assets(): void
    {
        $response = $this->get(route('landing'))->assertOk();

        foreach (['mahasiswa', 'dosen'] as $role) {
            $image = 'images/dashboard-'.$role.'.jpg';

            $version = hash_file('sha256', public_path($image));
            $response->assertSee('src="'.asset($image).'?v='.$version.'"', false)
                ->assertSee('Dashboard '.$role);
            $this->assertFileExists(public_path($image));
        }

        // Dimensi gambar presentasi (1705x922) + varian full-bleed.
        $response->assertSee('width="1705" height="922"', false)
            ->assertSee('landing-screenshot-present', false);

        $response->assertSee('data-dashboard-slider', false)
            ->assertSee('aria-roledescription="carousel"', false)
            ->assertSee('aria-controls="dashboard-slide-mahasiswa"', false)
            ->assertSee('aria-controls="dashboard-slide-dosen"', false)
            // Markup tombol panah dihapus (JS masih mereferensikan selektornya secara defensif).
            ->assertDontSee('data-slide-prev aria-label', false)
            ->assertDontSee('data-slide-next aria-label', false)
            ->assertSee('data-slide-play', false);
    }

    public function test_dashboard_slider_uses_minimal_overlay_controls(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        // Lapisan overlay baru ada di kedua slide.
        $this->assertStringContainsString('landing-slider-top', $html);
        $this->assertStringContainsString('landing-slider-bottom', $html);
        $this->assertStringContainsString('landing-slider-caption', $html);

        // Copy lama yang bertele-tele tidak muncul lagi.
        $this->assertStringNotContainsString('PREVIEW APLIKASI', $html);
        $this->assertStringNotContainsString('Putar slider', $html);
        $this->assertStringNotContainsString('Jeda slider', $html);
        $this->assertStringNotContainsString('01 / 02 — Dashboard', $html);
        $this->assertStringNotContainsString('Tampilan dashboard mahasiswa dan dosen dalam aplikasi.', $html);

        // Navigasi panah + status dihapus dari markup; tombol play ikon tetap ada.
        // (JS masih mereferensikan selektornya secara defensif bila markup ada.)
        $this->assertStringNotContainsString('data-slide-prev aria-label', $html);
        $this->assertStringNotContainsString('data-slide-next aria-label', $html);
        $this->assertStringNotContainsString('<span data-slide-status', $html);
        $this->assertStringNotContainsString('landing-slider-navigation', $html);
        $this->assertStringContainsString('aria-label="Jeda otomatis"', $html);

        // CSS overlay: absolute + scrim gradient + pill blur.
        $css = file_get_contents(public_path('build/assets/'.basename(glob(public_path('build/assets/app-*.css'))[0])));
        $this->assertMatchesRegularExpression('/\.landing-slider-top\s*\{[^}]*position:\s*absolute/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-slider-bottom\s*\{[^}]*position:\s*absolute/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-slider-caption\s*\{[^}]*position:\s*absolute/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-slider \.landing-screenshot::?after\s*\{[^}]*linear-gradient/s', $css);
    }

    public function test_readme_shows_dashboard_previews(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        foreach (['mahasiswa', 'dosen'] as $role) {
            $image = 'images/dashboard-'.$role.'.jpg';

            $this->assertFileExists(public_path($image));
            $this->assertStringContainsString('(public/'.$image.')', $readme);
        }
    }

    public function test_ta_journey_uses_application_phases_and_accessible_controls(): void
    {
        $response = $this->get(route('landing'))->assertOk();

        foreach (MahasiswaTa::FASES as $key => $label) {
            $response->assertSee($label)
                ->assertSee('id="ta-phase-detail-'.$key.'"', false)
                ->assertSee('aria-controls="ta-phase-detail-'.$key.'"', false);
        }

        $response->assertSee('data-ta-play', false)
            ->assertSee('prefers-reduced-motion: reduce', false)
            ->assertSee('ini ilustrasi alur, bukan progres akun Anda.')
            ->assertDontSee('Fase mahasiswa ditetapkan oleh dosen pembimbing.')
            ->assertSeeInOrder(['id="hero-title"', 'id="alur"', 'id="fitur"'], false);
    }

    public function test_landing_motion_has_phase_colors_and_progressive_enhancement(): void
    {
        $response = $this->get(route('landing'))->assertOk();

        foreach (array_keys(MahasiswaTa::FASES) as $index => $key) {
            $response->assertSee('data-ta-step="'.$index.'" data-phase-color="'.$index.'"', false);
        }

        $response->assertSee('IntersectionObserver', false)
            ->assertSee('is-reveal-pending', false)
            ->assertSee('pointercancel', false)
            ->assertSee('visibilitychange', false)
            ->assertSee('data-slider-controls hidden', false);
    }

    public function test_authenticated_user_stays_on_landing_page_and_sees_dashboard_cta(): void
    {
        $user = new User;
        $user->id = 999999;

        $this->actingAs($user)->get(route('landing'))
            ->assertOk()
            ->assertSee('Ke Dashboard')
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('Buat akun gratis');
    }

    public function test_capabilities_marquee_runs_without_pause_controls_and_has_hidden_duplicate(): void
    {
        $this->get(route('landing'))->assertOk()
            ->assertSee('data-capabilities-marquee', false)
            ->assertDontSee('data-marquee-toggle', false)
            ->assertDontSee('Jeda running text')
            ->assertSee('class="landing-marquee-group"  aria-hidden="true"', false)
            ->assertDontSee('Putar running text')
            ->assertSee("motion.addEventListener('change', sync)", false);
    }

    public function test_capabilities_marquee_alternates_blue_and_gold(): void
    {
        $css = file_get_contents(public_path('build/assets/'.basename(glob(public_path('build/assets/app-*.css'))[0])));

        // Ganjil biru, genap emas — mulai dari biru selaras brand default.
        // Catatan: minifier build mengubah `even` menjadi `2n`, jadi terima keduanya.
        $this->assertMatchesRegularExpression('/\.landing-marquee-group span:nth-child\(odd\)\s*\{[^}]*accent-blue/s', $css);
        $this->assertMatchesRegularExpression('/\.landing-marquee-group span:nth-child\((even|2n)\)\s*\{[^}]*status-pending/s', $css);
        // Biru token gagal AA di light mode (4.45:1) — pastikan override gelap #3b5aa0 (5.6:1) ada.
        $this->assertMatchesRegularExpression('/html:not\(\.dark\) \.landing-marquee-group span:nth-child\(odd\)\s*\{\s*color:\s*#3b5aa0/s', $css);
        // Emas terang gagal AA di light mode — pastikan override gelap #7d5f16 ada.
        $this->assertMatchesRegularExpression('/html:not\(\.dark\) \.landing-marquee-group span:nth-child\((even|2n)\)\s*\{\s*color:\s*#7d5f16/s', $css);
    }

    public function test_footer_has_current_year_copyright_and_social_links(): void
    {
        $this->get(route('landing'))->assertOk()
            ->assertSee('©', false)
            ->assertSee('text-accent-teal font-semibold">'.now()->year, false)
            ->assertSee('Made', false)
            ->assertSee('text-accent-blue font-semibold">with', false)
            ->assertSee('aria-hidden="true">❤️</span>', false)
            ->assertSee('href="https://reloop.id"', false)
            ->assertSee('ReLoop Lab')
            ->assertSee('href="https://github.com/relooplab/logbook"', false)
            ->assertSee('href="https://www.linkedin.com/company/relooplab"', false)
            ->assertSee('aria-label="LinkedIn Reloop Lab (tab baru)"', false)
            ->assertSee('aria-label="GitHub Logbook (tab baru)"', false);
    }

    public function test_footer_omits_institution_name_and_keeps_centered_identity(): void
    {
        $html = view('landing.partials.footer', [
            'appName' => 'Logbook',
            'institutionName' => 'Institution Must Not Appear',
            'version' => '1.2.3',
            'adminContactEmail' => null,
        ])->render();

        $this->assertStringNotContainsString('Institution Must Not Appear', $html);
        $this->assertStringContainsString('items-center gap-3 text-center', $html);
        $this->assertStringContainsString('v1.2.3', $html);
        $this->assertStringContainsString('text-accent-teal font-semibold">'.now()->year, $html);
        $this->assertStringContainsString('text-accent-blue font-semibold">with', $html);
        $this->assertStringNotContainsString('landing-accent-gold', $html);
        $this->assertStringContainsString('href="https://reloop.id"', $html);
        $this->assertStringContainsString('>ReLoop Lab</a>', $html);
        $this->assertStringNotContainsString('>Beranda</a>', $html);
        $this->assertStringNotContainsString('Kirim Masukan', $html);
        $this->assertStringContainsString('width="14" height="14"', $html);
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@data-footer-meta]/p')->length);
        $this->assertSame(2, $xpath->query('//*[@data-footer-meta]/nav/a')->length);
    }

    public function test_landing_page_hides_technical_stack_and_license_blurb(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('DIBANGUN DENGAN')
            ->assertDontSee('Laravel · Tailwind CSS · React · PDF.js · Reverb')
            ->assertDontSee('Dilisensikan dengan Business Source License 1.1.')
            ->assertSee('PERTANYAAN UMUM')
            ->assertDontSee('Kirim Masukan');
    }

    public function test_landing_page_has_metadata_for_public_sharing(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('property="og:title"', false);
    }

    public function test_landing_page_has_full_seo_metadata(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false)
            ->assertSee('property="og:site_name"', false)
            ->assertSee('property="og:image" content="'.url('/images/og-image.png').'"', false)
            ->assertSee('property="og:image:width" content="1200"', false)
            ->assertSee('property="og:image:height" content="630"', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('"@type": "WebSite"', false)
            ->assertSee('"@type": "SoftwareApplication"', false)
            ->assertSee('applicationCategory', false);
    }

    public function test_landing_page_has_faq_structured_data(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('"@type": "FAQPage"', $html);
        $this->assertSame(4, preg_match_all('/"@type": "Question"/', $html));
    }

    public function test_robots_and_sitemap_are_served_for_search_engines(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://logbook.reloop.id/sitemap.xml');
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('https://logbook.reloop.id/');
        $this->assertFileExists(public_path('images/og-image.png'));
    }
}
