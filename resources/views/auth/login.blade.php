@extends("layouts.guest")
@section("title", "Masuk")
@section("guest-content")
    <h2 class="text-lg font-semibold mb-4 text-text-primary">Masuk</h2>
    @if ($errors->any())
        <div class="mb-4 px-3 py-2 rounded-xl bg-status-danger/10 text-status-danger text-sm">
            {{ $errors->first() }} </div>
    @endif
    <form method="POST" action="{{ route("login.attempt") }}" class="space-y-4"> @csrf <div> <label
                class="block text-sm font-medium mb-1" for="email">Email / NIM / NIDN</label> <input type="text" name="email"
                id="email" required value="{{ old("email") }}" autofocus autocomplete="username" placeholder="Email, NIM, atau NIDN"
                class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm focus:ring-2 focus:ring-brand/40 focus:outline-none">
        </div>
        <div> <label class="block text-sm font-medium mb-1" for="password">Kata Sandi</label> <input type="password"
                name="password" id="password" required autocomplete="current-password"
                class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm focus:ring-2 focus:ring-brand/40 focus:outline-none">
        </div> <label class="flex items-center gap-2 text-sm text-text-primary"> <input type="checkbox" name="remember"
                class="h-4 w-4 rounded border-border bg-bg-surface accent-brand"> Ingat saya </label> <button type="submit"
            class="w-full rounded-xl bg-brand hover:bg-brand-hover text-[#0b1420] py-2 text-sm font-semibold transition-colors"> Masuk
        </button> <a href="{{ route("password.request") }}"
            class="block text-center text-sm text-brand hover:underline">Lupa kata sandi?</a>
        @if (!empty($adminContactEmail ?? null))
        <p class="block text-center text-sm text-text-secondary">Ada kendala? Hubungi <a href="mailto:{{ $adminContactEmail }}" class="text-brand hover:underline">kami</a></p>
        @endif
        <p class="block text-center text-sm text-text-secondary">Belum punya akun? <a href="{{ route("register") }}"
                class="text-brand hover:underline">Daftar</a></p>
    </form>
@endsection
