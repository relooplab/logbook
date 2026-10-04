@extends('layouts.public')

@section('head-extra')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        {
            "@type": "Question",
            "name": "Siapa yang bisa menggunakan aplikasi ini?",
            "acceptedAnswer": { "@type": "Answer", "text": "Mahasiswa, dosen pembimbing atau penguji, serta pengelola akademik. Tampilan dan akses disesuaikan dengan peran masing-masing." }
        },
        {
            "@type": "Question",
            "name": "Apakah bisa digunakan untuk Tugas Akhir dan Kerja Praktik?",
            "acceptedAnswer": { "@type": "Answer", "text": "Ya. Aplikasi mendukung alur bimbingan Tugas Akhir maupun Kerja Praktik, termasuk pencatatan progres dan peninjauan dokumen." }
        },
        {
            "@type": "Question",
            "name": "Apakah harus bergabung ke institusi dulu?",
            "acceptedAnswer": { "@type": "Answer", "text": "Tidak selalu. Dosen dapat menggunakan ruang kerja personal; pengguna institusi dapat bekerja bersama sesuai pengaturan akses institusinya." }
        },
        {
            "@type": "Question",
            "name": "Bagaimana memulai?",
            "acceptedAnswer": { "@type": "Answer", "text": "Buat akun, lengkapi data yang diminta, lalu ikuti alur sesuai peran Anda. Jika sudah memiliki akun, langsung masuk ke Dashboard." }
        }
    ]
}
</script>
@endsection

@section('content')
<a href="#konten" class="landing-skip">Lewati navigasi</a>

<header class="landing-header">
    <div class="landing-container flex items-center justify-between gap-4 py-3">
        <a href="{{ route('landing') }}" class="flex items-center gap-2.5 min-w-0 font-heading font-extrabold tracking-tight text-text-primary" aria-label="{{ $appName }} — Beranda">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center">@include('partials.logo-mark')</span>
            <span data-header-app-name class="hidden sm:inline truncate text-sm sm:text-base">{{ $appName }}</span>
        </a>
        <nav aria-label="Navigasi utama" class="hidden lg:flex items-center gap-7 text-sm font-medium text-text-secondary">
            <a href="#fitur" class="hover:text-text-primary transition-colors">Fitur</a>
            <a href="#alur" class="hover:text-text-primary transition-colors">Alur</a>
            <a href="#untuk-siapa" class="hover:text-text-primary transition-colors">Untuk siapa</a>
            <a href="#faq" class="hover:text-text-primary transition-colors">Pertanyaan umum</a>
        </nav>
        <div class="flex items-center gap-2 shrink-0">
            <button type="button" data-theme-toggle class="landing-icon-button" aria-label="Ganti mode gelap atau terang" title="Mode gelap/terang">
                <span data-icon-dark class="material-symbols-outlined icon-md">dark_mode</span>
                <span data-icon-light class="material-symbols-outlined icon-md hidden">light_mode</span>
            </button>
            @auth
                <a href="{{ route('dashboard') }}" class="landing-button landing-button-primary hidden sm:inline-flex">Ke Dashboard <span aria-hidden="true">↗</span></a>
            @else
                <a href="{{ route('login') }}" class="hidden sm:inline-flex landing-button landing-button-secondary">Masuk</a>
                <a href="{{ route('register') }}" class="landing-button landing-button-primary hidden sm:inline-flex">Daftar <span aria-hidden="true">↗</span></a>
            @endauth
            <button type="button" id="landing-menu-toggle" class="landing-icon-button lg:hidden" aria-label="Buka navigasi" aria-controls="landing-mobile-nav" aria-expanded="false">
                <span class="material-symbols-outlined icon-md" aria-hidden="true">menu</span>
            </button>
        </div>
    </div>
    <nav id="landing-mobile-nav" aria-label="Navigasi seluler" class="landing-mobile-nav hidden lg:hidden" hidden>
        <div class="landing-container flex flex-col gap-1 pb-4 text-sm font-medium">
            <a href="#fitur">Fitur</a><a href="#alur">Alur</a><a href="#untuk-siapa">Untuk siapa</a><a href="#faq">Pertanyaan umum</a>
            @auth
                <a href="{{ route('dashboard') }}" class="sm:hidden text-brand">Ke Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="sm:hidden">Masuk</a><a href="{{ route('register') }}" class="sm:hidden text-brand">Daftar</a>
            @endauth
        </div>
    </nav>
</header>

<main id="konten">
    <section class="landing-hero landing-container grid lg:grid-cols-[1fr_0.94fr] gap-12 lg:gap-16 items-center" aria-labelledby="hero-title">
        <div class="max-w-2xl">
            <div class="landing-eyebrow landing-eyebrow-gold mb-6"><span class="landing-eyebrow-dot"></span><span>Bimbingan yang tertata, kemajuan yang terlihat.</span></div>
            <h1 id="hero-title" class="landing-display"><span class="text-accent-blue">Log</span><span class="text-status-pending">book</span></h1>
            <p class="mt-6 text-base sm:text-lg leading-relaxed text-text-secondary max-w-xl">Dari entri logbook pertama sampai sidang terakhir, mahasiswa dan dosen bisa mencatat progres, memberi umpan balik, serta menuntaskan revisi dalam satu tempat.</p>
            <div class="mt-8 flex flex-wrap items-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="landing-button landing-button-primary landing-button-large">Ke Dashboard <span aria-hidden="true">↗</span></a>
                @else
                    <a href="{{ route('register') }}" class="landing-button landing-button-primary landing-button-large">Buat akun gratis <span aria-hidden="true">↗</span></a>
                    <a href="{{ route('login') }}" class="landing-button landing-button-secondary landing-button-large">Sudah punya akun? Masuk</a>
                @endauth
            </div>
            <p class="mt-7 text-xs leading-relaxed text-text-secondary">Untuk mahasiswa, dosen pembimbing, penguji, dan pengelola program.</p>
        </div>

        <div class="landing-journey landing-hero-preview" data-phase-color="0" aria-label="Ilustrasi perjalanan Tugas Akhir">
            <div class="landing-journey-top flex items-center justify-between gap-2">
                <span class="flex items-center gap-2 font-semibold"><span class="material-symbols-outlined icon-md text-brand" aria-hidden="true">route</span> Perjalanan TA</span>
                <span class="landing-step-tag">Ilustrasi</span>
            </div>
            <div class="landing-preview-body">
                <div class="landing-preview-orbit" aria-hidden="true"><span class="material-symbols-outlined">school</span><span class="landing-preview-orbit-dot"></span></div>
                <span class="landing-section-label">LANGKAH BERIKUTNYA, TERLIHAT.</span>
                <h2 data-ta-hero-label>Penyusunan Proposal</h2>
                <p>Catatan, dokumen, dan umpan balik menyertai setiap fase perjalanan Anda.</p>
                <div class="landing-preview-progress" aria-hidden="true">@foreach($taPhases as $key => $label)<span data-ta-hero-segment="{{ $loop->index }}" class="{{ $loop->first ? 'is-current' : '' }}"></span>@endforeach</div>
                <a href="#alur" class="landing-text-link">Jelajahi delapan fase <span aria-hidden="true">↓</span></a>
            </div>
            <div class="landing-journey-bottom"><span class="material-symbols-outlined icon-sm" aria-hidden="true">history</span> Bukan sekadar selesai. Setiap langkah tercatat.</div>
        </div>
    </section>

    @include('landing.partials.capabilities-marquee')

    @include('landing.partials.ta-journey')

    <section class="landing-section landing-container" aria-labelledby="masalah-title">
        <div class="grid md:grid-cols-[0.78fr_1fr] gap-8 md:gap-16 items-start">
            <div><span class="landing-section-label">KENAPA PERLU SATU TEMPAT?</span><h2 id="masalah-title" class="landing-heading mt-4">Bimbingan tidak harus <span class="landing-accent-gold">tercecer</span> di banyak tempat.</h2></div>
            <div class="space-y-5 text-text-secondary leading-relaxed"><p>Catatan pertemuan ada di buku. Revisi ada di percakapan. Berkas terbaru ada di folder yang berbeda. Ketika waktunya meninjau progres, semua orang harus menyusunnya lagi dari awal.</p><p class="text-text-primary font-semibold">Di sini, entri, dokumen, umpan balik, dan tahapan berikutnya hadir dalam satu alur yang bisa diikuti bersama.</p></div>
        </div>
    </section>

    <section id="fitur" class="landing-section landing-section-muted" aria-labelledby="fitur-title">
        <div class="landing-container">
            <span class="landing-section-label">ALAT UNTUK SETIAP LANGKAH</span>
            <div class="flex flex-wrap justify-between items-end gap-4 mt-4 mb-9"><h2 id="fitur-title" class="landing-heading max-w-2xl">Semua yang dibutuhkan, <span class="text-accent-blue">tanpa memutus alur.</span></h2><a href="#alur" class="landing-text-link">Lihat alur bimbingan <span aria-hidden="true">→</span></a></div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ([
                    ['edit_document', 'Logbook & revisi', 'Catat setiap sesi, ajukan perbaikan, dan ikuti status review sampai disetujui.', 'blue'],
                    ['picture_as_pdf', 'Review dokumen PDF', 'Baca dokumen bersama, tambahkan anotasi dan komentar langsung pada bagian yang relevan.', 'orange'],
                    ['space_dashboard', 'Dashboard sesuai peran', 'Mahasiswa, dosen, dan admin melihat pekerjaan serta informasi yang mereka butuhkan.', 'teal'],
                    ['folder_open', 'Workspace berkas', 'Simpan dokumen pendukung dan kelola akses berkas di ruang kerja yang tepat.', 'purple'],
                    ['forum', 'Komunikasi terhubung', 'Chat realtime, pengumuman, notifikasi email, dan pengingat membantu semua pihak tetap selaras.', 'blue'],
                    ['event_available', 'Seminar & sidang', 'Pantau tahapan, kelola bahan seminar, dan teruskan perjalanan hingga finalisasi.', 'orange'],
                ] as [$icon, $title, $description, $color])
                    <article class="landing-feature">
                        <span class="landing-feature-icon text-accent-{{ $color }}"><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span></span>
                        <h3 class="font-heading font-bold text-lg mt-6">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-text-secondary">{{ $description }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>



    <section id="untuk-siapa" class="landing-section landing-section-muted" aria-labelledby="peran-title">
        <div class="landing-container">
            <span class="landing-section-label">DIBUAT UNTUK BEKERJA BERSAMA</span><h2 id="peran-title" class="landing-heading mt-4 mb-9">Satu ruang kerja. <span class="text-accent-teal">Sudut pandang yang tepat.</span></h2>
            <div class="grid md:grid-cols-3 gap-4">
                <article class="landing-role"><span class="material-symbols-outlined text-accent-blue text-3xl" aria-hidden="true">school</span><h3>Mahasiswa</h3><p>Catat bimbingan, kirim revisi, lihat umpan balik, dan ketahui apa yang perlu dikerjakan berikutnya.</p></article>
                <article class="landing-role"><span class="material-symbols-outlined text-accent-orange text-3xl" aria-hidden="true">co_present</span><h3>Dosen</h3><p>Tinjau progres mahasiswa, beri komentar pada dokumen, dan kelola antrean review tanpa berpindah-pindah.</p></article>
                <article class="landing-role"><span class="material-symbols-outlined text-accent-purple text-3xl" aria-hidden="true">account_tree</span><h3>Pengelola</h3><p>Atur pengguna, pembimbing, tahapan akademik, dan kebutuhan institusi dari satu sistem.</p></article>
            </div>
        </div>
    </section>

    <section class="landing-section landing-container" aria-labelledby="tampilan-title">
        <div class="flex flex-wrap justify-between items-end gap-4 mb-9"><div><span class="landing-section-label">LIHAT RUANG KERJANYA</span><h2 id="tampilan-title" class="landing-heading mt-4">Dibuat untuk <span class="landing-accent-gold">pekerjaan nyata.</span></h2></div></div>
        @include('landing.partials.dashboard-slider')
    </section>

    <section class="landing-section landing-section-muted" aria-labelledby="mode-title">
        <div class="landing-container grid md:grid-cols-[0.8fr_1fr] gap-10 md:gap-20 items-start"><div><span class="landing-section-label">FLEKSIBEL UNTUK KAMPUS</span><h2 id="mode-title" class="landing-heading mt-4">Mulai sendiri.<br><span class="text-accent-purple">Kelola bersama.</span></h2><p class="mt-4 text-text-secondary leading-relaxed">Gunakan untuk bimbingan pribadi atau sebagai ruang kerja bersama di institusi. Alurnya tetap mengikuti kebutuhan tiap pengguna.</p></div><div class="space-y-4"><div class="landing-mode"><span class="landing-mode-icon text-accent-blue"><span class="material-symbols-outlined" aria-hidden="true">person</span></span><div><h3>Personal</h3><p>Dosen mengelola mahasiswa dan data bimbingannya sendiri.</p></div></div><div class="landing-mode"><span class="landing-mode-icon text-accent-teal"><span class="material-symbols-outlined" aria-hidden="true">domain</span></span><div><h3>Institusi</h3><p>Tim akademik bekerja bersama dengan pengaturan akses dan ruang penyimpanan institusi.</p></div></div></div></div>
    </section>

    <section id="faq" class="landing-section landing-container" aria-labelledby="faq-title">
        <div class="grid md:grid-cols-[0.7fr_1fr] gap-8 md:gap-20"><div><span class="landing-section-label">PERTANYAAN UMUM</span><h2 id="faq-title" class="landing-heading mt-4"><span class="text-accent-blue">Sebelum mulai.</span></h2></div><div class="landing-faq">
            <details><summary>Siapa yang bisa menggunakan aplikasi ini?<span class="material-symbols-outlined icon-md" aria-hidden="true">add</span></summary><p>Mahasiswa, dosen pembimbing atau penguji, serta pengelola akademik. Tampilan dan akses disesuaikan dengan peran masing-masing.</p></details>
            <details><summary>Apakah bisa digunakan untuk Tugas Akhir dan Kerja Praktik?<span class="material-symbols-outlined icon-md" aria-hidden="true">add</span></summary><p>Ya. Aplikasi mendukung alur bimbingan Tugas Akhir maupun Kerja Praktik, termasuk pencatatan progres dan peninjauan dokumen.</p></details>
            <details><summary>Apakah harus bergabung ke institusi dulu?<span class="material-symbols-outlined icon-md" aria-hidden="true">add</span></summary><p>Tidak selalu. Dosen dapat menggunakan ruang kerja personal; pengguna institusi dapat bekerja bersama sesuai pengaturan akses institusinya.</p></details>
            <details><summary>Bagaimana memulai?<span class="material-symbols-outlined icon-md" aria-hidden="true">add</span></summary><p>Buat akun, lengkapi data yang diminta, lalu ikuti alur sesuai peran Anda. Jika sudah memiliki akun, langsung masuk ke Dashboard.</p></details>
        </div></div>
    </section>

    <section class="landing-container pb-20 sm:pb-28" aria-labelledby="cta-title"><div class="landing-final-cta"><div class="relative z-10"><span class="landing-section-label">MULAI DARI SATU ENTRI</span><h2 id="cta-title" class="landing-heading mt-4 max-w-2xl">Beri setiap langkah bimbingan <span class="landing-accent-gold">tempat yang semestinya.</span></h2><p class="mt-4 text-text-secondary max-w-xl">Catat yang sudah dikerjakan, lihat yang perlu diperbaiki, dan lanjutkan bersama.</p><div class="mt-7 flex flex-wrap gap-3">@auth<a href="{{ route('dashboard') }}" class="landing-button landing-button-primary landing-button-large">Ke Dashboard <span aria-hidden="true">↗</span></a>@else<a href="{{ route('register') }}" class="landing-button landing-button-primary landing-button-large">Buat akun gratis <span aria-hidden="true">↗</span></a><a href="{{ route('login') }}" class="landing-button landing-button-outline landing-button-large">Masuk</a>@endauth</div></div></div></section>
</main>

@include('landing.partials.footer')
@endsection

@section('scripts')
<script>
    (function () {
        var root = document.documentElement;
        var themeButton = document.querySelector('[data-theme-toggle]');
        function syncTheme() {
            var dark = root.classList.contains('dark');
            document.querySelector('[data-icon-dark]').classList.toggle('hidden', !dark);
            document.querySelector('[data-icon-light]').classList.toggle('hidden', dark);
        }
        syncTheme();
        themeButton.addEventListener('click', function () {
            root.classList.toggle('dark');
            try { localStorage.setItem('lbta-theme', root.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
            syncTheme();
        });

        var menuButton = document.getElementById('landing-menu-toggle');
        var menu = document.getElementById('landing-mobile-nav');
        function closeMenu() {
            menu.hidden = true;
            menu.classList.add('hidden');
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.setAttribute('aria-label', 'Buka navigasi');
        }
        menuButton.addEventListener('click', function () {
            var opening = menuButton.getAttribute('aria-expanded') !== 'true';
            menu.hidden = !opening;
            menu.classList.toggle('hidden', !opening);
            menuButton.setAttribute('aria-expanded', String(opening));
            menuButton.setAttribute('aria-label', opening ? 'Tutup navigasi' : 'Buka navigasi');
        });
        menu.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', closeMenu); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeMenu(); });

        var journey = document.querySelector('[data-ta-journey]');
        var phaseButtons = Array.from(journey.querySelectorAll('[data-ta-select]'));
        var playButton = journey.querySelector('[data-ta-play]');
        var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        var activePhase = 0;
        var playing = !motion.matches;
        var visible = false;
        var timer = null;

        function selectPhase(index) {
            activePhase = index;
            document.querySelector('.landing-hero-preview').setAttribute('data-phase-color', String(index));
            phaseButtons.forEach(function (button, i) {
                button.setAttribute('aria-pressed', String(i === index));
                var step = button.closest('li');
                step.classList.toggle('is-current', i === index);
                step.classList.toggle('is-complete', i < index);
            });
            journey.querySelectorAll('[data-ta-detail]').forEach(function (panel, i) { panel.hidden = i !== index; });
            document.querySelector('[data-ta-hero-label]').textContent = phaseButtons[index].lastElementChild.textContent;
            document.querySelectorAll('[data-ta-hero-segment]').forEach(function (segment, i) {
                segment.classList.toggle('is-current', i === index);
                segment.classList.toggle('is-complete', i < index);
            });
        }
        function syncPlayback() {
            window.clearInterval(timer);
            timer = null;
            playButton.textContent = playing ? 'Jeda animasi' : 'Putar animasi';
            playButton.setAttribute('aria-pressed', String(playing));
            journey.classList.toggle('is-breathing', playing && visible && !document.hidden && !motion.matches);
            if (playing && visible && !document.hidden && !motion.matches) {
                timer = window.setInterval(function () { selectPhase((activePhase + 1) % phaseButtons.length); }, 4500);
            }
        }
        playButton.hidden = false;
        phaseButtons.forEach(function (button, index) {
            button.addEventListener('click', function () { playing = false; selectPhase(index); syncPlayback(); });
        });
        playButton.addEventListener('click', function () { playing = !playing; syncPlayback(); });
        journey.addEventListener('focusin', function (event) {
            if (event.target !== playButton) { playing = false; syncPlayback(); }
        });
        motion.addEventListener('change', function () { if (motion.matches) playing = false; syncPlayback(); });
        document.addEventListener('visibilitychange', syncPlayback);
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                visible = entries[0].isIntersecting;
                syncPlayback();
            }, { threshold: .15 }).observe(journey);
        } else {
            visible = true;
        }
        syncPlayback();
    })();
</script>
@include('landing.partials.motion-script')
@include('landing.partials.marquee-script')
@endsection