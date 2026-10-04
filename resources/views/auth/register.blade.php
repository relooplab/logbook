@extends("layouts.guest")
@section("title", "Daftar")
@section("guest-content")
    <h2 class="text-lg font-semibold mb-4 text-text-primary">Daftar Akun</h2>
    @if ($errors->any())
        <div class="mb-4 px-3 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm">
            @foreach ($errors->all() as $e)
                <p>{{ $e }}</p>
            @endforeach
        </div>
    @endif

    {{-- ===== Pemilih peran: kartu role (Mahasiswa / Dosen) ===== --}}
    <div id="role-cards" class="grid grid-cols-2 gap-2 mb-3" role="radiogroup" aria-label="Pilih peran pendaftaran">
        <button type="button" data-role="mahasiswa" id="role-mahasiswa" role="radio" aria-checked="true"
            class="role-card text-left rounded-xl border border-accent-blue bg-accent-blue/10 p-3 cursor-pointer transition-colors hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-blue">
            <span class="block text-sm font-semibold text-text-primary">Mahasiswa</span>
            <span class="mt-0.5 block text-xs text-text-secondary">Catat logbook bimbingan TA/KP</span>
        </button>
        <button type="button" data-role="dosen" id="role-dosen" role="radio" aria-checked="false"
            class="role-card text-left rounded-xl border border-border bg-bg-panel p-3 cursor-pointer transition-colors hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-orange">
            <span class="block text-sm font-semibold text-text-primary">Dosen</span>
            <span class="mt-0.5 block text-xs text-text-secondary">Bimbing &amp; nilai mahasiswa</span>
        </button>
    </div>
    <p id="role-hint" class="text-xs text-text-secondary mb-3">Setelah verifikasi email, isi NIM dan pilih dosen pembimbing Anda.</p>

    <form method="POST" action="{{ route("register") }}" class="space-y-4" id="role-panels"> @csrf <input type="hidden" name="role"
            id="role-input" value="mahasiswa">
        <div> <label class="block text-sm font-medium mb-1" for="name">Nama</label> <input type="text" name="name"
                id="name" required value="{{ old("name") }}" autocomplete="name"
                class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm focus:ring-2 focus:ring-brand/40 focus:outline-none"> </div>
        <div> <label class="block text-sm font-medium mb-1" for="email">Email</label> <input type="email"
                name="email" id="email" required value="{{ old("email") }}" autocomplete="email"
                class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm focus:ring-2 focus:ring-brand/40 focus:outline-none"> </div>
        <div> <label class="block text-sm font-medium mb-1" for="password">Kata Sandi</label> <input type="password"
                name="password" id="password" required minlength="6" autocomplete="new-password"
                class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm focus:ring-2 focus:ring-brand/40 focus:outline-none"> </div>
        <div> <label class="block text-sm font-medium mb-1" for="password_confirmation">Konfirmasi Kata Sandi</label> <input
                type="password" name="password_confirmation" id="password_confirmation" required minlength="6" autocomplete="new-password"
                class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm focus:ring-2 focus:ring-brand/40 focus:outline-none"> </div>

        <button type="submit" id="submit-btn"
            class="w-full rounded-xl bg-accent-blue hover:bg-accent-blue/85 text-[#0b1420] py-2 text-sm font-semibold transition-colors">Daftar</button>
        <a href="{{ route("login") }}" class="block text-center text-sm text-brand hover:underline">Sudah punya akun?
            Masuk</a>
    </form>
    @endsection
    @section("guest-scripts")
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var roleInput = document.getElementById('role-input');
            var roleHint = document.getElementById('role-hint');
            var roleCards = document.getElementById('role-cards');

            if (!roleInput || !roleCards) return;

            function setRole(role) {
                roleInput.value = role;
                var activeByRole = {
                    'mahasiswa': ['border-accent-blue', 'bg-accent-blue/10'],
                    'dosen': ['border-accent-orange', 'bg-accent-orange/10'],
                };
                var inactive = ['border-border', 'bg-bg-panel'];
                var cards = roleCards.querySelectorAll('.role-card');
                Array.prototype.forEach.call(cards, function (card) {
                    var cardRole = card.dataset.role;
                    var active = cardRole === role;
                    activeByRole[cardRole].forEach(function (cls) { card.classList.remove(cls); });
                    inactive.forEach(function (cls) { card.classList.remove(cls); });
                    if (active) {
                        activeByRole[cardRole].forEach(function (cls) { card.classList.add(cls); });
                        card.setAttribute('aria-checked', 'true');
                    } else {
                        inactive.forEach(function (cls) { card.classList.add(cls); });
                        card.setAttribute('aria-checked', 'false');
                    }
                });
                if (roleHint) {
                    roleHint.textContent = role === 'dosen'
                        ? 'Setelah daftar, lengkapi afiliasi institusi dan NIDN Anda di halaman profil.'
                        : 'Setelah verifikasi email, isi NIM dan pilih dosen pembimbing Anda.';
                }
                var submitBtn = document.getElementById('submit-btn');
                if (submitBtn) {
                    ['bg-accent-blue', 'hover:bg-accent-blue/85', 'bg-accent-orange', 'hover:bg-accent-orange/85']
                        .forEach(function (cls) { submitBtn.classList.remove(cls); });
                    (role === 'dosen'
                        ? ['bg-accent-orange', 'hover:bg-accent-orange/85']
                        : ['bg-accent-blue', 'hover:bg-accent-blue/85'])
                        .forEach(function (cls) { submitBtn.classList.add(cls); });
                }
            }

            // Event delegation: robust, tetap aktif untuk kartu baru.
            roleCards.addEventListener('click', function (e) {
                var card = e.target.closest('.role-card');
                if (card) setRole(card.dataset.role);
            });

            // Pertahankan role yang dipilih saat ada error validasi (old input).
            var oldRole = @json(old('role', 'mahasiswa'));
            setRole(oldRole === 'dosen' ? 'dosen' : 'mahasiswa');
        });
    </script>
    @endsection