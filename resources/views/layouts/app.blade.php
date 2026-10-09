@php
    $user = auth()->user();
    // Desain sidebar: satu warna brand konteks (--brand) untuk state aktif,
    // ikon netral untuk non-aktif. Pill aktif + batang kiri = penanda "di sini".
    $active = fn ($name) => request()->routeIs($name)
        ? 'bg-brand/10 text-brand font-semibold before:content-[""] before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-5 before:w-1 before:rounded-full before:bg-brand'
        : 'text-text-secondary hover:bg-bg-hover hover:text-text-primary';
    $navLink = 'relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors';
    // Label grup sidebar (dipakai dengan pola text-[10px] uppercase tracking-widest).
    $groupLabel = 'px-3 pt-4 pb-1 text-[10px] uppercase tracking-widest text-text-secondary sidebar-label';
    // Badge "belum dibaca" untuk dosen pada menu Agenda Seminar/Sidang.
    $unreadSeminarCount = 0;
    if ($user?->isDosen()) {
        $dosenTaIds = \App\Models\MahasiswaTa::where(fn ($q) => $q->where('pembimbing_1_id', $user->id)
            ->orWhere('pembimbing_2_id', $user->id)
            ->orWhere('penguji_1_id', $user->id)
            ->orWhere('penguji_2_id', $user->id))->pluck('id');
        $unreadSeminarCount = \App\Models\SeminarSubmission::whereIn('mahasiswa_ta_id', $dosenTaIds)
            ->where('status', \App\Models\SeminarSubmission::STATUS_SUBMITTED)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    // Papan peringatan: dosen dengan mahasiswa pending menunggu keputusan.
    $pendingApprovals = ($user?->isDosen()) ? $user->pendingApprovals() : collect();
@endphp
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name')) · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32x32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet" />
    <style>
        .material-symbols-outlined,
        .material-symbols-rounded {
            user-select: none;
            vertical-align: middle;
            font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 24;
        }
        .icon-sm { font-size: 16px; }
        .icon-md { font-size: 20px; }
        .icon-lg { font-size: 24px; }
        #sidebar-collapse-icon { transition: transform .2s ease; }
        html.sidebar-collapsed #sidebar-collapse-icon { transform: rotate(180deg); }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // Default dark; persist toggle via localStorage.
        (function () {
            var saved = localStorage.getItem('lbta-theme');
            if (saved === 'light') document.documentElement.classList.remove('dark');
        })();
        // Sidebar collapse state; persist toggle via localStorage.
        (function () {
            if (localStorage.getItem('lbta-sidebar-collapsed') === '1') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('css/global.css') }}?v={{ @filemtime(public_path('css/global.css')) }}">
    <style>
        .progress-bar { transition: width .4s ease; }
        @media (min-width: 768px) {
            html.sidebar-collapsed #sidebar { width: 5rem; }
            html.sidebar-collapsed #main-wrap { margin-left: 5rem; }
            html.sidebar-collapsed .sidebar-label { display: none; }
            html.sidebar-collapsed #sidebar nav a { justify-content: center; }
        }
        /* ===== Perbaikan mobile ===== */
        /* Dropdown notifikasi & profil agar tidak overflow di layar kecil */
        @media (max-width: 767px) {
            #notif-dropdown, #global-search-results {
                position: fixed !important;
                left: 0.75rem !important;
                right: 0.75rem !important;
                top: 4rem !important;
                width: auto !important;
                max-width: none !important;
            }
            #profile-menu-dropdown {
                position: fixed !important;
                right: 0.75rem !important;
                top: 4rem !important;
                width: 14rem !important;
            }
        }
        /* Tabel responsif: sembunyikan kolom kurang penting di layar kecil */
        @media (max-width: 639px) {
            .table-col-email, .table-col-nim, .table-col-tanggal, .table-col-jenis,
            .table-col-pembimbing2, .table-col-penguji, .table-col-target { display: none; }
        }
        @media (max-width: 1023px) {
            .table-col-penguji { display: none; }
        }
        /* Tombol aksi header agar tidak overflow */
        .header-actions { flex-wrap: wrap; }
    </style>
    @yield('head')
</head>
<body class="bg-bg-base text-text-primary min-h-screen font-sans antialiased {{ $user?->isDosen() ? 'ctx-dosen' : ($user?->isAdmin() ? 'ctx-admin' : 'ctx-mahasiswa') }}">

<div class="flex min-h-screen">

    {{-- Overlay (mobile only, shown while sidebar drawer is open) --}}
    <div id="sidebar-overlay" class="hidden fixed inset-0 bg-black/50 z-30 md:hidden"></div>

    {{-- ===================== SIDEBAR (fixed kiri) ===================== --}}
    <aside id="sidebar" class="fixed left-0 top-0 h-screen w-60 bg-bg-base border-r border-border flex flex-col z-40 -translate-x-full md:translate-x-0 transition-all duration-200 ease-in-out">
        <button type="button" id="sidebar-collapse-btn" title="Ciutkan/lebarkan sidebar" class="hidden md:flex absolute -right-3 top-5 w-6 h-6 rounded-full bg-bg-surface border border-border items-center justify-center text-text-secondary hover:text-text-primary hover:bg-bg-hover z-50">
            <span id="sidebar-collapse-icon" class="material-symbols-outlined icon-sm">chevron_left</span>
        </button>
        <div class="flex justify-end px-3 pt-2 md:hidden">
            <button type="button" id="sidebar-close-btn" title="Tutup menu" class="p-1.5 rounded-lg text-text-secondary hover:bg-bg-hover hover:text-text-primary">
                <span class="material-symbols-outlined icon-md">close</span>
            </button>
        </div>

        @auth
            @php
                $showDosenMenu = $user->isDosen();
                $showAdminMenu = $user->isAdmin();
            @endphp
        @endauth

        <nav class="flex-1 px-3 space-y-1 overflow-y-auto mt-1">
            <div class="{{ $groupLabel }}">Ringkasan</div>
            <a href="{{ route('dashboard') }}" class="{{ $navLink }} {{ $active('dashboard') }}">
                <span class="material-symbols-outlined icon-md">dashboard</span>
                <span class="sidebar-label">Dashboard</span>
            </a>
            <a href="{{ route('chat.index') }}" class="{{ $navLink }} {{ $active('chat.*') }}">
                <span class="material-symbols-outlined icon-md">chat</span>
                <span class="sidebar-label">Chat</span>
            </a>
            <a href="{{ route('announcements.index') }}" class="{{ $navLink }} {{ $active('announcements.*') }}">
                <span class="material-symbols-outlined icon-md">campaign</span>
                <span class="sidebar-label">Pengumuman</span>
            </a>

            @if ($user->isMahasiswa())
            <a href="{{ route('scheduling.index') }}" class="{{ $navLink }} {{ $active('scheduling.*') }}">
                <span class="material-symbols-outlined icon-md text-status-info">calendar_month</span>
                <span class="sidebar-label">Jadwalkan Bimbingan</span>
            </a>
            @endif

            @if ($user->isMahasiswa())
                @php
                    $programs = \App\Support\ProgramContext::programs($user);
                    $currentProgram = \App\Support\ProgramContext::resolve($user, request());
                    $kp = $user->allPrograms()->where('jenis', 'kp')->first();
                    $hasTa = (bool) $user->mahasiswaTa;
                    $hasKp = (bool) $kp;
                    $hasProgram = $hasTa || $hasKp;
                    $profileIncomplete = blank($user->nim) || blank($user->whatsapp);
                @endphp
                <div class="{{ $groupLabel }}">Profil</div>
                @if ($profileIncomplete)
                    <a href="{{ route('profile.index') }}" class="{{ $navLink }} {{ $active('profile.index') }}">
                        <span class="material-symbols-outlined icon-md">badge</span>
                        <span class="sidebar-label">Lengkapi Profil</span>
                    </a>
                @endif
                <a href="{{ route('profile.profil-akademik') }}" class="{{ $navLink }} {{ $active('profile.profil-akademik*') }}">
                    <span class="material-symbols-outlined icon-md">school</span>
                    <span class="sidebar-label">Profil Akademik</span>
                </a>
                @if ($hasProgram)
                    @php $multiplePrograms = $programs->count() > 1; @endphp
                    @foreach ($programs as $p)
                        @if ($multiplePrograms)
                        <button type="button" data-program-group-toggle="{{ $p->jenis }}" data-default-open="{{ $currentProgram && $currentProgram->jenis === $p->jenis ? '1' : '0' }}" aria-expanded="true" aria-controls="program-group-{{ $p->jenis }}"
                            class="{{ $groupLabel }} w-full flex items-center justify-between text-left hover:text-text-primary transition-colors">
                            <span class="inline-flex items-center gap-2">
                                {{ $p->isKp() ? 'Kerja Praktik' : 'Tugas Akhir' }}
                                @if ($p->status_ta === \App\Models\MahasiswaTa::STATUS_AKTIF)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-brand/10 text-brand normal-case tracking-normal">aktif</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_PENDING_APPROVAL)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-status-pending/10 text-status-pending normal-case tracking-normal">menunggu</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_DITOLAK)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-status-danger/10 text-status-danger normal-case tracking-normal">ditolak</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_NONAKTIF)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-status-danger/10 text-status-danger normal-case tracking-normal">nonaktif</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_TAMAT)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-text-secondary/10 text-text-secondary normal-case tracking-normal">tamat</span>
                                @endif
                            </span>
                            <span class="material-symbols-outlined icon-sm sidebar-label transition-transform" data-program-chevron="{{ $p->jenis }}">expand_more</span>
                        </button>
                        @else
                        <div class="{{ $groupLabel }}">
                            <span class="inline-flex items-center gap-2">
                                {{ $p->isKp() ? 'Kerja Praktik' : 'Tugas Akhir' }}
                                @if ($p->status_ta === \App\Models\MahasiswaTa::STATUS_AKTIF)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-brand/10 text-brand normal-case tracking-normal">aktif</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_PENDING_APPROVAL)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-status-pending/10 text-status-pending normal-case tracking-normal">menunggu</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_DITOLAK)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-status-danger/10 text-status-danger normal-case tracking-normal">ditolak</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_NONAKTIF)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-status-danger/10 text-status-danger normal-case tracking-normal">nonaktif</span>
                                @elseif ($p->status_ta === \App\Models\MahasiswaTa::STATUS_TAMAT)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-medium bg-text-secondary/10 text-text-secondary normal-case tracking-normal">tamat</span>
                                @endif
                            </span>
                        </div>
                        @endif
                        <div id="program-group-{{ $p->jenis }}" data-program-group="{{ $p->jenis }}" data-default-open="{{ $currentProgram && $currentProgram->jenis === $p->jenis ? '1' : '0' }}">
                        @php
                            $__routeProgram = request('program');
                            $__routeMt = request()->route('mahasiswaTa');
                            $__routeMtId = is_object($__routeMt) ? $__routeMt->id : $__routeMt;
                            $__onProgram = $__routeProgram ? $__routeProgram === $p->jenis : ($__routeMtId ? (int) $__routeMtId === $p->id : ($currentProgram && $currentProgram->id === $p->id));
                            $pActive = fn ($name) => (request()->routeIs($name) && $__onProgram) ? 'bg-brand/10 text-brand font-semibold before:content-[""] before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-5 before:w-1 before:rounded-full before:bg-brand' : 'text-text-secondary hover:bg-bg-hover hover:text-text-primary';
                        @endphp
                        <a href="{{ route('logbook.index', ['program' => $p->jenis]) }}" class="{{ $navLink }} {{ $pActive('logbook.index') }}">
                            <span class="material-symbols-outlined icon-md">menu_book</span>
                            <span class="sidebar-label">Logbook</span>
                        </a>
                        <a href="{{ route('logbook.create', ['program' => $p->jenis]) }}" class="{{ $navLink }} {{ $pActive('logbook.create') }}">
                            <span class="material-symbols-outlined icon-md">add</span>
                            <span class="sidebar-label">Tambah Logbook</span>
                        </a>
                        <a href="{{ route('logbook.create-revisi', ['program' => $p->jenis]) }}" class="{{ $navLink }} {{ $pActive('logbook.create-revisi') }}">
                            <span class="material-symbols-outlined icon-md">edit_note</span>
                            <span class="sidebar-label">Entri Revisi</span>
                            @php $pendingCount = \App\Models\LogbookEntry::pendingRevisionsFor($p)->count(); @endphp
                            @if ($pendingCount > 0)
                                <span class="ml-auto rounded-full bg-status-danger/15 px-1.5 py-0.5 text-[10px] font-bold text-status-danger" title="{{ $pendingCount }} revisi belum selesai">{{ $pendingCount }}</span>
                            @endif
                        </a>
                        <a href="{{ route('logbook.feedback', ['program' => $p->jenis]) }}" class="{{ $navLink }} {{ $pActive('logbook.feedback') }}">
                            <span class="material-symbols-outlined icon-md">forum</span>
                            <span class="sidebar-label">Riwayat Umpan Balik</span>
                        </a>
                        @if ($p->isKp())
                            <a href="{{ route('logbook-harian.index', $p) }}" class="{{ $navLink }} {{ $pActive('logbook-harian.*') }}">
                                <span class="material-symbols-outlined icon-md">event_note</span>
                                <span class="sidebar-label">Logbook Harian</span>
                            </a>
                            <a href="{{ route('profil-perusahaan.index', $p) }}" class="{{ $navLink }} {{ $pActive('profil-perusahaan.*') }}">
                                <span class="material-symbols-outlined icon-md">business</span>
                                <span class="sidebar-label">Profil Perusahaan</span>
                            </a>
                        @endif
                        <a href="{{ route('workspace.index', $p) }}" class="{{ $navLink }} {{ $pActive('workspace.*') }}">
                            <span class="material-symbols-outlined icon-md">workspaces</span>
                            <span class="sidebar-label">Workspace</span>
                        </a>
                        </div>
                    @endforeach
                @endif
            @elseif ($showDosenMenu)
                <div class="{{ $groupLabel }}">Bimbingan</div>
                <a href="{{ route('dosen.mahasiswa-saya') }}" class="{{ $navLink }} {{ $active('dosen.mahasiswa-saya') }}">
                    <span class="material-symbols-outlined icon-md">group</span>
                    <span class="sidebar-label">Mahasiswa Saya</span>
                </a>
                <a href="{{ route('logbook.index') }}" class="{{ $navLink }} {{ $active('logbook.index') }}">
                    <span class="material-symbols-outlined icon-md">history</span>
                    <span class="sidebar-label">Riwayat Bimbingan</span>
                </a>
                <a href="{{ route('materials-review.index') }}" class="{{ $navLink }} {{ $active('materials-review.*') }}">
                    <span class="material-symbols-outlined icon-md text-status-pending">inbox</span>
                    <span class="sidebar-label">Antrean Review</span>
                </a>
                <a href="{{ route('dosen.seminar-jadwal') }}" class="{{ $navLink }} {{ $active('dosen.seminar-jadwal') }}">
                    <span class="material-symbols-outlined icon-md">event_note</span>
                    <span class="sidebar-label">Agenda Seminar/Sidang</span>
                    @if (!empty($unreadSeminarCount))
                        <span class="ml-auto rounded-full bg-status-danger text-[#0b1420] text-[10px] font-bold px-1.5 py-0.5">{{ $unreadSeminarCount }}</span>
                    @endif
                </a>
                <a href="{{ route('quick-review.index') }}" class="{{ $navLink }} {{ $active('quick-review.*') }}">
                    <span class="material-symbols-outlined icon-md">bolt</span>
                    <span class="sidebar-label">Quick Review</span>
                </a>
                <a href="{{ route('approval.index') }}" class="{{ $navLink }} {{ $active('approval.*') }}">
                    <span class="material-symbols-outlined icon-md text-status-success">check_circle</span>
                    <span class="sidebar-label">Persetujuan</span>
                </a>
                <div class="{{ $groupLabel }}">Workspace &amp; Data</div>
                <a href="{{ route('workspace.role') }}" class="{{ $navLink }} {{ $active('workspace.role') }}">
                    <span class="material-symbols-outlined icon-md">folder</span>
                    <span class="sidebar-label">Workspace</span>
                </a>
                <a href="{{ route('storage.index') }}" class="{{ $navLink }} {{ $active('storage.*') }}">
                    <span class="material-symbols-outlined icon-md">database</span>
                    <span class="sidebar-label">Workspace Mahasiswa</span>
                </a>
                <a href="{{ route('workspace-institusi.index') }}" class="{{ $navLink }} {{ $active('workspace-institusi.*') }}">
                    <span class="material-symbols-outlined icon-md">folder_shared</span>
                    <span class="sidebar-label">Workspace Institusi</span>
                </a>
                <div class="{{ $groupLabel }}">Profil &amp; Komunitas</div>
                @if ($user->isDosen())
                    <a href="{{ route('profile.affiliation') }}" class="{{ $navLink }} {{ $active('profile.affiliation*') }}">
                        <span class="material-symbols-outlined icon-md">account_balance</span>
                        <span class="sidebar-label">Afiliasi Institusi</span>
                    </a>
                @endif
                <a href="{{ route('groups.index') }}" class="{{ $navLink }} {{ $active('groups.*') }}">
                    <span class="material-symbols-outlined icon-md">groups</span>
                    <span class="sidebar-label">Grup Dosen</span>
                </a>
                <a href="{{ route('dosen-sidang.index') }}" class="{{ $navLink }} {{ $active('dosen-sidang.*') }}">
                    <span class="material-symbols-outlined icon-md">verified</span>
                    <span class="sidebar-label">Riwayat Sidang</span>
                </a>
            @endif

            @if ($showAdminMenu)
                <div class="px-3 pt-4 pb-1 text-[10px] uppercase tracking-widest text-text-secondary sidebar-label">Administrasi</div>
                @if ($user->isAdmin())
                    <a href="{{ route('affiliation-approval.index') }}" class="{{ $navLink }} {{ $active('affiliation-approval.*') }}">
                        <span class="material-symbols-outlined icon-md">person_add</span>
                        <span class="sidebar-label">Persetujuan Afiliasi</span>
                    </a>
                @endif
                @if ($user->isSystemAdmin())
                    @can('system.admins')
                        <a href="{{ route('admin.system.admins') }}" class="{{ $navLink }} {{ $active('admin.system.admins') }}">
                            <span class="material-symbols-outlined icon-md">admin_panel_settings</span>
                            <span class="sidebar-label">Kelola Admin</span>
                        </a>
                    @endcan
                    <a href="{{ route('admin.system.permissions') }}" class="{{ $navLink }} {{ $active('admin.system.permissions') }}">
                        <span class="material-symbols-outlined icon-md">lock</span>
                        <span class="sidebar-label">Kelola Hak Akses</span>
                    </a>
                    <a href="{{ route('admin.system.settings') }}" class="{{ $navLink }} {{ $active('admin.system.settings*') }}">
                        <span class="material-symbols-outlined icon-md">settings</span>
                        <span class="sidebar-label">Pengaturan</span>
                    </a>
                    <a href="{{ route('admin.system.directory') }}" class="{{ $navLink }} {{ $active('admin.system.directory*') }}">
                        <span class="material-symbols-outlined icon-md">account_tree</span>
                        <span class="sidebar-label">Direktori</span>
                    </a>
                    <a href="{{ route('admin.system.directory-subscriptions') }}" class="{{ $navLink }} {{ $active('admin.system.directory-subscriptions*') }}">
                        <span class="material-symbols-outlined icon-md">subscriptions</span>
                        <span class="sidebar-label">Langganan Direktori</span>
                    </a>
                    <a href="{{ route('admin.system.backup') }}" class="{{ $navLink }} {{ $active('admin.system.backup*') }}">
                        <span class="material-symbols-outlined icon-md">cloud_download</span>
                        <span class="sidebar-label">Backup &amp; Restore</span>
                    </a>
                @endif
                @can('admin.users')
                    <a href="{{ route('admin.users') }}" class="{{ $navLink }} {{ $active('admin.users') }}">
                        <span class="material-symbols-outlined icon-md">group</span>
                        <span class="sidebar-label">Pengguna</span>
                    </a>
                @endcan
                @can('admin.tas')
                    <a href="{{ route('admin.tas') }}" class="{{ $navLink }} {{ $active('admin.tas') }}">
                        <span class="material-symbols-outlined icon-md">archive</span>
                        <span class="sidebar-label">Data TA</span>
                    </a>
                @endcan
                @can('admin.bulk-review')
                    <a href="{{ route('admin.entries') }}" class="{{ $navLink }} {{ $active('admin.entries') }}">
                        <span class="material-symbols-outlined icon-md">fact_check</span>
                        <span class="sidebar-label">Review Massal</span>
                    </a>
                @endcan
                @can('admin.sidangs')
                    <a href="{{ route('admin.sidangs') }}" class="{{ $navLink }} {{ $active('admin.sidangs') }}">
                        <span class="material-symbols-outlined icon-md">gavel</span>
                        <span class="sidebar-label">Sidang</span>
                    </a>
                @endcan
                @can('admin.institution')
                    <a href="{{ route('admin.institution') }}" class="{{ $navLink }} {{ $active('admin.institution') }}">
                        <span class="material-symbols-outlined icon-md">apartment</span>
                        <span class="sidebar-label">Institusi</span>
                    </a>
                    <a href="{{ route('admin.program-naming') }}" class="{{ $navLink }} {{ $active('admin.program-naming') }}">
                        <span class="material-symbols-outlined icon-md">edit_note</span>
                        <span class="sidebar-label">Penamaan Program</span>
                    </a>
                @endcan
            @endif
        </nav>
    </aside>

    {{-- ===================== MAIN CONTENT ===================== --}}
    <div id="main-wrap" class="flex-1 md:ml-60 min-w-0 transition-all duration-200 ease-in-out">
        <header class="sticky top-0 z-30 h-16 bg-bg-base/80 backdrop-blur border-b border-border px-3 md:px-8 grid grid-cols-[1fr_auto] md:grid-cols-[1fr_auto_1fr] items-center gap-2 md:gap-3">
            <div class="flex items-center gap-2 md:gap-3 min-w-0">
                <button type="button" id="sidebar-open-btn" title="Buka menu" class="md:hidden p-2 rounded-xl bg-bg-hover text-text-secondary hover:text-text-primary shrink-0">
                    <span class="material-symbols-outlined icon-md">menu</span>
                </button>
                @yield('header-title', '')
            </div>
            @php
                $headerAppName = config('app.name');
                $headerWords = explode(' ', $headerAppName);
                $headerLast = array_pop($headerWords);
                $headerFirst = implode(' ', $headerWords);
            @endphp
            <a href="{{ route('dashboard') }}" class="hidden md:flex items-center gap-2 px-2 min-w-0 justify-center" title="{{ $headerAppName }}">
                <span class="w-8 h-8 md:w-9 md:h-9 flex items-center justify-center shrink-0">
                    @include('partials.logo-mark')
                </span>
                <span class="hidden sm:flex flex-col min-w-0 leading-tight">
                    @if ($headerFirst !== '')
                        <span class="font-heading font-extrabold text-sm md:text-base text-text-primary truncate">{{ $headerFirst }}</span>
                        <span class="text-[9px] md:text-[10px] font-semibold text-brand truncate">{{ $headerLast }}</span>
                    @else
                        <span class="font-heading font-extrabold text-sm md:text-base text-text-primary truncate">{{ $headerLast }}</span>
                    @endif
                </span>
            </a>
            <div class="flex items-center justify-end gap-3">
                <div class="relative hidden md:block">
                    <input type="text" id="global-search-input" placeholder="Cari (Ctrl+K)…" aria-label="Pencarian global"
                        class="w-56 rounded-xl border border-border bg-bg-surface px-3.5 py-2 text-sm text-text-primary placeholder:text-text-secondary focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <div id="global-search-results" class="hidden absolute right-0 mt-2 w-80 bg-bg-surface rounded-card shadow-lg border border-border overflow-hidden"></div>
                </div>

                <div class="relative">
                    <button type="button" id="notif-bell" class="relative p-2.5 rounded-xl bg-bg-hover text-text-secondary hover:text-text-primary" title="Notifikasi" aria-label="Notifikasi" aria-expanded="false">
                        <span class="material-symbols-outlined icon-md text-status-info">notifications</span>
                        <span id="notif-badge" class="hidden absolute -top-1 -right-1 h-4 w-4 rounded-full bg-status-danger text-[10px] text-white items-center justify-center"></span>
                    </button>
                    <div id="notif-dropdown" class="hidden absolute right-0 mt-2 w-80 bg-bg-surface rounded-card shadow-lg border border-border overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-2.5 border-b border-border">
                            <span class="text-sm font-semibold text-text-primary">Notifikasi</span>
                            <a href="{{ route('notifications.index') }}" class="text-xs text-brand hover:underline">Lihat semua</a>
                        </div>
                        <div id="notif-list" class="max-h-80 overflow-y-auto text-sm">
                            <div class="px-4 py-3 text-text-secondary">Memuat…</div>
                        </div>
                    </div>
                </div>

                <button type="button" id="theme-toggle" class="p-2.5 rounded-xl bg-bg-hover text-text-secondary hover:text-text-primary" title="Mode gelap/terang" aria-label="Ganti mode gelap atau terang">
                    <span id="icon-dark" class="material-symbols-outlined icon-md">dark_mode</span>
                    <span id="icon-light" class="material-symbols-outlined icon-md hidden">light_mode</span>
                </button>

                @auth
                <div class="relative" id="profile-menu-wrap">
                    <button type="button" id="profile-menu-btn" title="{{ $user->name }}" aria-label="Menu profil" aria-haspopup="menu" aria-expanded="false" class="avatar w-9 h-9 text-xs overflow-hidden hover:ring-2 hover:ring-brand/40 transition shrink-0">
                        @if ($user->photoUrl())
                            <img src="{{ $user->photoUrl() }}" class="h-full w-full object-cover" alt="Foto profil">
                        @else
                            {{ $user->initials() }}
                        @endif
                    </button>
                    <div id="profile-menu-dropdown" class="hidden absolute right-0 mt-2 w-56 bg-bg-surface rounded-xl shadow-lg border border-border overflow-hidden">
                        <div class="px-4 py-3 border-b border-border">
                            <div class="text-sm font-medium text-text-primary truncate">{{ $user->name }}</div>
                            <div class="text-xs text-text-secondary truncate">{{ $user->email }}</div>
                        </div>
                        <a href="{{ route('profile.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-text-secondary hover:bg-bg-hover hover:text-text-primary">
                            <span class="material-symbols-outlined icon-sm">person</span>
                            Profil
                        </a>
                        <a href="https://reloop.notion.site/3b1155a221e880829514df5d0a8dcfd6" target="_blank" rel="noopener"
                            class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-text-secondary hover:bg-bg-hover hover:text-text-primary"
                            title="Laporkan masalah atau kirim ide untuk pengembangan aplikasi">
                            <span class="material-symbols-outlined icon-sm">feedback</span>
                            Kirim Masukan
                        </a>
                        <p class="border-t border-border px-4 py-2.5 text-xs text-text-secondary" title="Versi rilis perangkat lunak">
                            Versi v{{ \App\Support\ReleaseVersion::get() }}
                        </p>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm text-text-secondary hover:bg-bg-hover hover:text-status-danger">
                                <span class="material-symbols-outlined icon-sm text-status-danger">logout</span>
                                Keluar
                            </button>
                        </form>
                    </div>
                </div>
                @endauth
            </div>
        </header>

        <main class="px-4 py-6 md:px-8 md:py-8">
            @if (session('success'))
                <div class="mb-5 px-4 py-3 rounded-xl bg-status-success/10 text-status-success border border-status-success/20 flex items-start gap-2.5">
                    <span class="material-symbols-outlined icon-md mt-0.5 text-status-success">check_circle</span><span>{{ session('success') }}</span>
                </div>
            @endif
            @if (session('error'))
                <div class="mb-5 px-4 py-3 rounded-xl bg-status-danger/10 text-status-danger border border-status-danger/20 flex items-start gap-2.5">
                    <span class="material-symbols-outlined icon-md mt-0.5 text-status-danger">warning</span><span>{{ session('error') }}</span>
                </div>
            @endif
            @if (session('warning'))
                <div class="mb-5 px-4 py-3 rounded-xl bg-status-pending/10 text-status-pending border border-status-pending/20 flex items-start gap-2.5">
                    <span class="material-symbols-outlined icon-md mt-0.5 text-status-info">info</span><span>{{ session('warning') }}</span>
                </div>
            @endif
            @if (session('import_errors'))
                <div class="mb-5 px-4 py-3 rounded-xl bg-status-pending/10 text-status-pending border border-status-pending/20">
                    <div class="flex items-start gap-2.5 font-medium">
                        <span class="material-symbols-outlined icon-md mt-0.5 text-status-info">info</span>
                        <span>Sebagian baris dilewati saat import:</span>
                    </div>
                    <ul class="mt-2 ml-8 list-disc space-y-1 text-sm">
                        @foreach (session('import_errors') as $importError)
                            <li>{{ $importError }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($pendingApprovals->isNotEmpty())
                <div class="mb-4 px-4 py-3 rounded-xl border border-status-pending/40 bg-status-pending/10 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-status-pending font-medium">
                            <span class="material-symbols-outlined icon-sm align-text-bottom">hourglass_top</span>
                            Anda punya {{ $pendingApprovals->count() }} mahasiswa yang menunggu persetujuan. Mohon diproses sebelum melewati {{ config('app.dosen_pending_approval_deadline_days', 4) }} hari agar akses tidak terkunci.
                        </p>
                        <a href="{{ route('approval.index') }}"
                            class="px-3 py-1.5 rounded-xl bg-brand text-[#0b1420] text-xs font-semibold hover:opacity-90">Keputusan Persetujuan →</a>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script src="https://js.pusher.com/7.2/pusher.min.js"></script>
<script>
    // Realtime notifikasi (Reverb via protokol Pusher)
    (function () {
        var userId = {{ auth()->id() ?? 'null' }};
        if (!userId) return;
        var rKey = @json(config('broadcasting.connections.reverb.key')) || 'lbta-key';
        var rHost = @json(config('broadcasting.connections.reverb.host')) || window.location.hostname;
        var rPort = @json(config('broadcasting.connections.reverb.port')) || 443;
        var rScheme = @json(config('broadcasting.connections.reverb.scheme')) || 'http';
        var pusher;
        try {
            pusher = new Pusher(rKey, {
                wsHost: rHost,
                wsPort: rPort,
                wssPort: rPort,
                forceTLS: rScheme === 'https',
                encrypted: rScheme === 'https',
                enabledTransports: ['ws', 'wss'],
                disableStats: true
            });
        } catch (e) { return; }

        function toast(msg) {
            var el = document.createElement('div');
            el.className = 'fixed bottom-4 right-4 z-50 max-w-sm px-4 py-3 rounded-card shadow-lg bg-bg-surface border border-border text-sm text-text-primary';
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(function () { el.remove(); }, 5000);
        }
        function bump() {
            var b = document.getElementById('notif-badge');
            b.classList.remove('hidden');
            b.classList.add('flex');
            var n = parseInt(b.textContent || '0', 10) + 1;
            b.textContent = n > 9 ? '9+' : n;
        }

        var ch = pusher.subscribe('user.' + userId);
        ch.bind('entry.status.changed', function (data) { bump(); toast(data.message); });
        ch.bind('pdf.comment.created', function (data) { bump(); toast('Komentar baru pada PDF.'); });
    })();
</script>
<script>
    // Dark mode toggle
    var toggle = document.getElementById('theme-toggle');
    var isDark = function () { return document.documentElement.classList.contains('dark'); };
    function syncIcons() {
        document.getElementById('icon-dark').classList.toggle('hidden', !isDark());
        document.getElementById('icon-light').classList.toggle('hidden', isDark());
    }
    syncIcons();
    toggle.addEventListener('click', function () {
        if (isDark()) document.documentElement.classList.remove('dark');
        else document.documentElement.classList.add('dark');
        localStorage.setItem('lbta-theme', isDark() ? 'dark' : 'light');
        syncIcons();
    });
</script>
<script>
    // ---- Dropdown notifikasi ----
    (function () {
        var bell = document.getElementById('notif-bell');
        var drop = document.getElementById('notif-dropdown');
        var list = document.getElementById('notif-list');
        if (!bell || !drop) return;

        function esc(s) {
            return String(s || '').replace(/[&<>"']/g, function (c) {
                return { '&': '&', '<': '<', '>': '>', '"': '"', "'": '&#39;' }[c];
            });
        }
        function load() {
            fetch('{{ route("notifications.dropdown") }}', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var badge = document.getElementById('notif-badge');
                    if (data.unread > 0) {
                        badge.classList.remove('hidden');
                        badge.classList.add('flex');
                        badge.textContent = data.unread > 9 ? '9+' : data.unread;
                    } else {
                        badge.classList.add('hidden');
                    }
                    if (!data.items.length) {
                        list.innerHTML = '<div class="px-4 py-3 text-text-secondary">Tidak ada notifikasi.</div>';
                        return;
                    }
                    list.innerHTML = data.items.map(function (n) {
                        var unread = !n.read_at;
                        return '<a href="' + esc(n.url || '{{ route("notifications.index") }}') + '" class="flex items-start gap-2 px-4 py-2.5 border-b border-border hover:bg-bg-hover ' + (unread ? 'bg-brand/5' : '') + '">' +
                            '<span class="mt-1 h-2 w-2 rounded-full flex-shrink-0 ' + (unread ? 'bg-brand' : 'bg-text-secondary/40') + '"></span>' +
                            '<span class="min-w-0"><span class="block text-xs leading-snug text-text-primary">' + esc(n.message) + '</span>' +
                            '<span class="block text-[10px] text-text-secondary">' + esc(n.created_at) + '</span></span></a>';
                    }).join('') +
                    '<a href="{{ route("notifications.index") }}" class="block px-4 py-2 text-center text-xs text-brand hover:underline">Lihat semua</a>';
                });
        }
        bell.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !drop.classList.contains('hidden');
            drop.classList.toggle('hidden');
            bell.setAttribute('aria-expanded', open ? 'false' : 'true');
            if (!open) load();
        });
        document.addEventListener('click', function () { drop.classList.add('hidden'); });
        fetch('{{ route("notifications.dropdown") }}', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var badge = document.getElementById('notif-badge');
                if (d.unread > 0) { badge.classList.remove('hidden'); badge.classList.add('flex'); badge.textContent = d.unread > 9 ? '9+' : d.unread; }
            });
    })();

    // ---- Sidebar drawer (mobile) ----
    (function () {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebar-overlay');
        var openBtn = document.getElementById('sidebar-open-btn');
        var closeBtn = document.getElementById('sidebar-close-btn');
        if (!sidebar || !overlay) return;

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        }
        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        }
        if (openBtn) openBtn.addEventListener('click', openSidebar);
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        overlay.addEventListener('click', closeSidebar);
        sidebar.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 767px)').matches) closeSidebar();
            });
        });
        window.addEventListener('resize', function () {
            if (window.matchMedia('(min-width: 768px)').matches) overlay.classList.add('hidden');
        });
    })();

    // ---- Sidebar collapse (desktop) ----
    (function () {
        var collapseBtn = document.getElementById('sidebar-collapse-btn');
        if (!collapseBtn) return;
        var root = document.documentElement;
        // Saat collapsed (ikon saja), jadikan label sebagai tooltip via title.
        function syncTitles() {
            var collapsed = root.classList.contains('sidebar-collapsed');
            document.querySelectorAll('#sidebar nav a').forEach(function (a) {
                var label = a.querySelector('.sidebar-label');
                if (!label) return;
                if (collapsed) {
                    if (!a.getAttribute('title')) a.setAttribute('title', label.textContent.trim());
                } else {
                    a.removeAttribute('title');
                }
            });
        }
        collapseBtn.addEventListener('click', function () {
            var collapsed = root.classList.toggle('sidebar-collapsed');
            localStorage.setItem('lbta-sidebar-collapsed', collapsed ? '1' : '0');
            syncTitles();
        });

        // Grup program (TA/KP) dapat dilipat; state disimpan per program.
        document.querySelectorAll('[data-program-group-toggle]').forEach(function (btn) {
            var key = btn.getAttribute('data-program-group-toggle');
            var panel = document.getElementById(btn.getAttribute('aria-controls'));
            var chevron = document.querySelector('[data-program-chevron="' + key + '"]');
            if (!panel) return;
            var stored = localStorage.getItem('lbta-sidebar-group-' + key);
            var open = stored !== null ? stored === '1' : btn.getAttribute('data-default-open') === '1';
            var apply = function (isOpen) {
                panel.classList.toggle('hidden', !isOpen);
                btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (chevron) chevron.style.transform = isOpen ? '' : 'rotate(-90deg)';
            };
            apply(open);
            btn.addEventListener('click', function () {
                var isOpen = btn.getAttribute('aria-expanded') !== 'true';
                apply(isOpen);
                localStorage.setItem('lbta-sidebar-group-' + key, isOpen ? '1' : '0');
            });
        });
        syncTitles();
    })();

    // ---- Profile dropdown ----
    (function () {
        var btn = document.getElementById('profile-menu-btn');
        var drop = document.getElementById('profile-menu-dropdown');
        if (!btn || !drop) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !drop.classList.contains('hidden');
            drop.classList.toggle('hidden');
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
        document.addEventListener('click', function () {
            drop.classList.add('hidden');
            btn.setAttribute('aria-expanded', 'false');
        });
    })();

    // ---- Global search (Ctrl+K) ----
    (function () {
        var input = document.getElementById('global-search-input');
        if (!input) return;
        var results = document.getElementById('global-search-results');
        var timer;
        function esc(s) {
            return String(s || '').replace(/[&<>"']/g, function (c) {
                return { '&': '&', '<': '<', '>': '>', '"': '"', "'": '&#39;' }[c];
            });
        }
        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                input.focus();
            }
        });
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) { results.classList.add('hidden'); return; }
            timer = setTimeout(function () {
                fetch('{{ route("global-search") }}?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var html = '';
                        if (d.users.length) {
                            html += '<p class="px-4 py-1 text-[10px] uppercase tracking-wider text-text-secondary">Mahasiswa/Dosen</p>';
                            d.users.forEach(function (u) {
                                html += '<a href="' + esc(u.url) + '" class="flex items-center gap-2 px-4 py-2 hover:bg-bg-hover text-sm"><span class="w-6 h-6 rounded-full bg-brand-light text-brand flex items-center justify-center text-[10px] font-bold">' + esc((u.name || '?').charAt(0)) + '</span><span class="min-w-0"><span class="block text-text-primary truncate">' + esc(u.name) + '</span><span class="block text-xs text-text-secondary">' + esc(u.nim || '') + '</span></span></a>';
                            });
                        }
                        if (d.entries.length) {
                            html += '<p class="px-4 py-1 text-[10px] uppercase tracking-wider text-text-secondary">Entri</p>';
                            d.entries.forEach(function (e) {
                                html += '<a href="' + esc(e.url) + '" class="block px-4 py-2 hover:bg-bg-hover text-sm text-text-primary"><span class="material-symbols-outlined icon-sm align-text-bottom">description</span> ' + esc(e.title) + ' <span class="text-xs text-text-secondary">' + esc(e.student || '') + '</span></a>';
                            });
                        }
                        if (d.files.length) {
                            html += '<p class="px-4 py-1 text-[10px] uppercase tracking-wider text-text-secondary">File Workspace</p>';
                            d.files.forEach(function (f) {
                                html += '<a href="' + esc(f.url) + '" class="block px-4 py-2 hover:bg-bg-hover text-sm text-text-primary"><span class="material-symbols-outlined icon-sm align-text-bottom">folder</span> ' + esc(f.name) + '</a>';
                            });
                        }
                        if (!html) html = '<div class="px-4 py-3 text-text-secondary">Tidak ada hasil.</div>';
                        results.innerHTML = html;
                        results.classList.remove('hidden');
                    });
            }, 300);
        });
        document.addEventListener('click', function (e) {
            if (!results.contains(e.target) && e.target !== input) results.classList.add('hidden');
        });
    })();
</script>
@yield('scripts')
</body>
</html>
