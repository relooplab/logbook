@extends("layouts.app") @section("title", "Profil " . $profile->name) @section("content")
<div class="profile-workspace space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading text-2xl font-bold">Profil</h1>
            <p class="mt-1 text-sm text-text-secondary">Informasi profil, kontak, dan akademik pengguna.</p>
        </div>
        <a href="{{ url()->previous() }}"
            class="btn-ghost inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
            <span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_back</span> Kembali</a>
    </header>

    <div class="profile-workspace-grid">
        <aside class="card min-w-0 self-start p-5 sm:p-6" aria-label="Ringkasan profil">
            <h2 class="font-heading font-semibold">Informasi Profil</h2>
            <div class="mt-5 flex flex-col items-center text-center">
                <div class="avatar h-24 w-24 overflow-hidden text-2xl">
                    @if ($profile->photoUrl())
                        <img src="{{ $profile->photoUrl() }}" alt="Foto profil {{ $profile->name }}" class="h-full w-full object-cover">
                    @else
                        {{ $profile->initials() }}
                    @endif
                </div>
                <p class="mt-4 max-w-full break-words text-lg font-semibold">{{ $profile->name }}</p>
                <p class="max-w-full break-all text-sm text-text-secondary">{{ $profile->email }}</p>
                <div class="mt-2 flex flex-wrap justify-center gap-1">
                    @foreach ($profile->roles->whereNotIn('name', ['admin', 'system_admin']) as $r)
                        <span class="rounded-full bg-bg-panel px-2.5 py-1 text-xs text-text-secondary">{{ ucfirst($r->name) }}</span>
                    @endforeach
                </div>
                @if ($profile->lastActiveStatus() === 'online')
                    <span class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-status-success/10 text-status-success">
                        <span class="w-2 h-2 rounded-full bg-status-success animate-pulse"></span> Online
                    </span>
                @elseif ($profile->lastActiveStatus() === 'offline')
                    <span class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-bg-panel text-text-secondary">
                        <span class="w-2 h-2 rounded-full bg-text-secondary/50"></span> Offline
                    </span>
                @else
                    <span class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-bg-panel text-text-secondary">
                        <span class="w-2 h-2 rounded-full bg-text-secondary/50"></span> Belum pernah aktif
                    </span>
                @endif
                <p class="mt-3 text-xs text-text-secondary">
                    <span class="material-symbols-outlined icon-xs align-text-bottom" aria-hidden="true">schedule</span>
                    Terakhir aktif: {{ $profile->lastActiveLabel() }}
                </p>
            </div>

            <section class="mt-6 border-t border-border pt-5" aria-labelledby="student-identitas-title">
                <h3 id="student-identitas-title" class="font-heading text-sm font-semibold">Identitas Akademik</h3>
                <ul class="mt-4 space-y-3 text-sm text-text-secondary">
                    @if ($profile->nim)
                        <li class="flex min-w-0 items-start gap-2"><span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-brand" aria-hidden="true">confirmation_number</span><span class="min-w-0 break-words">NIM: <span class="text-text-primary font-medium">{{ $profile->nim }}</span></span></li>
                    @endif
                    @if ($profile->nidn)
                        <li class="flex min-w-0 items-start gap-2"><span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-brand" aria-hidden="true">badge</span><span class="min-w-0 break-words">NIDN: <span class="text-text-primary font-medium">{{ $profile->nidn }}</span></span></li>
                    @endif
                    @php $profileUniv = $profile->primaryUniversity(); @endphp
                    @if ($profileUniv)
                        <li class="flex min-w-0 items-start gap-2"><span class="material-symbols-outlined icon-sm mt-0.5 shrink-0 text-brand" aria-hidden="true">account_balance</span><span class="min-w-0 break-words">{{ $profileUniv->name }}</span></li>
                    @endif
                </ul>
            </section>

            @if ($profile->mahasiswaTa)
                <section class="mt-5 border-t border-border pt-5" aria-labelledby="student-ta-title">
                    <h3 id="student-ta-title" class="font-heading text-sm font-semibold">Tugas Akhir</h3>
                    <p class="mt-3 min-w-0 break-words text-sm text-text-secondary">{{ \Illuminate\Support\Str::limit($profile->mahasiswaTa->judul_ta, 120) }}</p>
                </section>
            @endif
        </aside>

        <div class="min-w-0 space-y-6">
            <section class="card min-w-0 p-5 sm:p-6" aria-labelledby="student-contact-title">
                <h2 id="student-contact-title" class="font-heading font-semibold">Kontak</h2>
                <p class="mt-1 text-sm text-text-secondary">Saluran komunikasi dan tautan akademik pengguna.</p>
                <div class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                    @if ($profile->whatsapp)
                        <a href="{{ $profile->whatsappUrl() }}" target="_blank" rel="noopener"
                            class="rounded-control bg-bg-panel px-3 py-2.5 hover:bg-bg-hover"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">chat</span> WhatsApp:
                            {{ $profile->whatsapp }}
                            @if ($profile->bimbingan_via_whatsapp)
                                <span class="ml-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-brand/10 text-brand"><span class="material-symbols-outlined icon-xs align-text-bottom" aria-hidden="true">calendar_month</span> Bimbingan</span>
                            @endif
                        </a>
                    @endif
                    @if ($profile->telegram)
                        <span class="rounded-control bg-bg-panel px-3 py-2.5"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">send</span> Telegram: {{ $profile->telegram }}
                            @if ($profile->bimbingan_via_telegram)
                                <span class="ml-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-brand/10 text-brand"><span class="material-symbols-outlined icon-xs align-text-bottom" aria-hidden="true">calendar_month</span> Bimbingan</span>
                            @endif
                        </span>
                    @endif
                    @if ($profile->linkedin)
                        <a href="{{ $profile->linkedin }}" target="_blank" rel="noopener"
                            class="rounded-control bg-bg-panel px-3 py-2.5 hover:bg-bg-hover"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">link</span>
                            LinkedIn: {{ $profile->linkedin }}</a>
                    @endif
                    @if (! $profile->whatsapp && ! $profile->telegram && ! $profile->linkedin)
                        <p class="text-sm text-text-secondary">Belum ada kontak yang ditambahkan.</p>
                    @endif
                </div>
                @include('partials.profile-affiliation', ['affUser' => $profile])
            </section>

            @if ($profile->isDosen() && ($profile->google_scholar || $profile->orcid || $profile->sinta || $profile->researchgate || $profile->jadwal_bimbingan_url))
                <section class="card min-w-0 p-5 sm:p-6" aria-labelledby="student-academic-links-title">
                    <h2 id="student-academic-links-title" class="font-heading font-semibold">Tautan Akademik</h2>
                    <div class="mt-5 flex flex-wrap gap-2 text-sm">
                        @if ($profile->google_scholar)
                            <a href="{{ $profile->google_scholar }}" target="_blank" rel="noopener"
                                class="rounded-control bg-brand/10 px-3 py-1.5 text-brand hover:bg-brand/20"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">school</span>
                                Google Scholar</a>
                        @endif
                        @if ($profile->orcid)
                            <a href="https://orcid.org/{{ $profile->orcid }}" target="_blank" rel="noopener"
                                class="rounded-control bg-brand/10 px-3 py-1.5 text-brand hover:bg-brand/20"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">badge</span>
                                ORCID</a>
                        @endif
                        @if ($profile->sinta)
                            <a href="https://sinta.kemdikbud.go.id/authors/profile/{{ $profile->sinta }}" target="_blank" rel="noopener"
                                class="rounded-control bg-status-pending/10 px-3 py-1.5 text-status-pending"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">bar_chart</span>
                                SINTA</a>
                        @endif
                        @if ($profile->researchgate)
                            <a href="{{ $profile->researchgate }}" target="_blank" rel="noopener"
                                class="rounded-control bg-bg-hover px-3 py-1.5 hover:bg-bg-hover"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">science</span>
                                ResearchGate</a>
                        @endif
                        @if ($profile->jadwal_bimbingan_url)
                            <a href="{{ $profile->jadwal_bimbingan_url }}" target="_blank" rel="noopener"
                                class="rounded-control bg-brand/10 px-3 py-1.5 text-brand"><span class="material-symbols-outlined icon-sm align-text-bottom" aria-hidden="true">calendar_month</span>
                                Jadwalkan Bimbingan</a>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
