@extends('layouts.app')

@section('title', 'Pengaturan Autentikasi')

@section('content')
<div class="max-w-3xl space-y-6">
    <div>
        <h1 class="text-xl font-bold">Pengaturan Autentikasi</h1>
        <p class="text-sm text-text-secondary">Atur apakah user yang baru registrasi wajib verifikasi email. Saat ON, form SMTP akan tampil dan harus diisi lengkap.</p>
    </div>

    <div class="bg-bg-surface rounded-xl border border-border p-6">
        <form method="POST" action="{{ route('admin.system.settings.update') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email-verification-toggle" class="block text-sm font-medium mb-1">Verifikasi Email</label>
                <select name="email_verification_override" id="email-verification-toggle"
                    class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm sm:max-w-xs">
                    <option value="auto" @selected($institution->email_verification_override === null || old('email_verification_override') === 'auto')>Auto — ikuti SMTP</option>
                    <option value="wajib" @selected($institution->email_verification_override === 1 || old('email_verification_override') === 'wajib')>Wajib</option>
                    <option value="tidak" @selected($institution->email_verification_override === 0 || old('email_verification_override') === 'tidak')>Tidak Wajib</option>
                </select>
                <p class="text-xs text-text-secondary mt-1">Mode <b>Auto</b> menjadikan verifikasi email wajib bila SMTP sungguhan terkonfigurasi. Anda bisa override kapan saja.</p>
                @error('email_verification_override')<p class="text-xs text-status-danger">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="admin_contact_email" class="block text-sm font-medium mb-1">Email Kontak Admin (Default Global)</label>
                <input type="email" name="admin_contact_email" id="admin_contact_email"
                    value="{{ old("admin_contact_email", $institution->admin_contact_email) }}" placeholder="admin@kampus.ac.id"
                    class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm sm:max-w-xs">
                <p class="text-xs text-text-secondary mt-1">Ditampilkan sebagai info bantuan di halaman daftar, masuk, dan bawah profil user (mis. untuk koreksi NIDN). Institusi bisa menimpanya via form Profil Institusi. Ini <b>bukan</b> email akun System Admin — field terpisah. Kosongkan agar tidak menampilkan info kontak.</p>
                @error('admin_contact_email')<p class="text-xs text-status-danger">{{ $message }}</p>@enderror
            </div>

            <div id="smtp-form" class="space-y-4 pt-2 border-t border-border">
                <p class="text-sm font-semibold">Konfigurasi SMTP</p>
                <p class="text-xs text-text-secondary -mt-3">Pengaturan SMTP untuk pengiriman email. Nilai di sini menimpa konfigurasi di .env.</p>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Mailer</label>
                        <select name="mail_mailer" class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                            @foreach (["smtp", "log", "array", "sendmail", "mailgun", "ses", "postmark", "resend"] as $m)
                                <option value="{{ $m }}" @selected(old("mail_mailer", $institution->mail_mailer ?? "smtp") === $m)>{{ strtoupper($m) }}</option>
                            @endforeach
                        </select>
                        @error('mail_mailer')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Host SMTP</label>
                        <input type="text" name="mail_host" value="{{ old("mail_host", $institution->mail_host) }}" placeholder="smtp.gmail.com"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                        @error('mail_host')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Port</label>
                        <input type="number" name="mail_port" min="1" max="65535" value="{{ old("mail_port", $institution->mail_port) }}" placeholder="587"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                        @error('mail_port')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Enkripsi</label>
                        <select name="mail_encryption" class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                            <option value="">Tanpa enkripsi</option>
                            <option value="tls" @selected(old("mail_encryption", $institution->mail_encryption) === "tls")>TLS</option>
                            <option value="ssl" @selected(old("mail_encryption", $institution->mail_encryption) === "ssl")>SSL</option>
                        </select>
                        @error('mail_encryption')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Username</label>
                        <input type="text" name="mail_username" value="{{ old("mail_username", $institution->mail_username) }}" placeholder="email@domain.com"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                        @error('mail_username')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Password</label>
                        <input type="password" name="mail_password" placeholder="••••••••" autocomplete="new-password"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                        <p class="text-xs text-text-secondary mt-1">Kosongkan bila tidak ingin mengubah password SMTP yang tersimpan.</p>
                        @error('mail_password')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">From Address</label>
                        <input type="email" name="mail_from_address" value="{{ old("mail_from_address", $institution->mail_from_address) }}" placeholder="no-reply@domain.com"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                        @error('mail_from_address')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">From Name</label>
                        <input type="text" name="mail_from_name" value="{{ old("mail_from_name", $institution->mail_from_name) }}" placeholder="Logbook"
                            class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                        @error('mail_from_name')<p class="text-xs text-status-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button class="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-[#0b1420] text-sm font-semibold">Simpan</button>
            </div>
        </form>
    </div>

    <div class="bg-bg-surface rounded-xl border border-border p-6">
        <h2 class="font-semibold mb-1">Notifikasi Berkala</h2>
        <p class="text-sm text-text-secondary mb-4">Atur email otomatis berkala. Berlaku untuk seluruh user pada institusi Anda ({{ $institution->institution_name ?? 'ini' }}). Mematikan toggle berarti email + notifikasi in-app untuk jenis tersebut tidak dikirim.</p>
        <form method="POST" action="{{ route('admin.system.settings.update') }}" class="space-y-3">
            @csrf
            {{-- Pertahankan nilai autentikasi/SMTP saat ini agar tidak tertimpa form parsial ini. --}}
            <input type="hidden" name="_notification_form" value="1">
            <input type="hidden" name="email_verification_override_keep" value="{{ $institution->email_verification_override === true ? 'wajib' : ($institution->email_verification_override === false ? 'tidak' : 'auto') }}">
            <input type="hidden" name="admin_contact_email_keep" value="{{ $institution->admin_contact_email }}">
            <input type="hidden" name="mail_mailer_keep" value="{{ $institution->mail_mailer ?? 'smtp' }}">
            <input type="hidden" name="mail_host_keep" value="{{ $institution->mail_host }}">
            <input type="hidden" name="mail_port_keep" value="{{ $institution->mail_port }}">
            <input type="hidden" name="mail_username_keep" value="{{ $institution->mail_username }}">
            <input type="hidden" name="mail_from_address_keep" value="{{ $institution->mail_from_address }}">
            <input type="hidden" name="mail_from_name_keep" value="{{ $institution->mail_from_name }}">

            <label class="flex items-start gap-3 rounded-xl border border-border p-3 cursor-pointer">
                <input type="checkbox" name="weekly_digest_enabled" value="1" @checked(old('weekly_digest_enabled', $institution->isWeeklyDigestEnabled())) class="mt-1 rounded bg-bg-surface border-border">
                <span>
                    <span class="block text-sm font-medium">Digest Mingguan (Senin 07:00 WIB)</span>
                    <span class="block text-xs text-text-secondary mt-0.5">Ringkasan bimbingan mingguan ke dosen &amp; mahasiswa (<code>ta:weekly-digest</code>).</span>
                </span>
            </label>
            <label class="flex items-start gap-3 rounded-xl border border-border p-3 cursor-pointer">
                <input type="checkbox" name="daily_reminder_enabled" value="1" @checked(old('daily_reminder_enabled', $institution->isDailyReminderEnabled())) class="mt-1 rounded bg-bg-surface border-border">
                <span>
                    <span class="block text-sm font-medium">Reminder Harian (08:00 WIB)</span>
                    <span class="block text-xs text-text-secondary mt-0.5">Pengingat mahasiswa tidak aktif &amp; antrean review dosen (<code>logbook:send-reminders</code>), termasuk pengingat inaktivitas &gt; 3 minggu + CC pembimbing (<code>ta:notify-inactive</code>).</span>
                </span>
            </label>

            <div class="flex items-center gap-3 pt-1">
                <button class="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-[#0b1420] text-sm font-semibold">Simpan</button>
            </div>
        </form>
    </div>

    <div id="smtp-test" class="bg-bg-surface rounded-xl border border-border p-6">
        <h2 class="font-semibold mb-1">Kirim Email Uji</h2>
        <p class="text-sm text-text-secondary mb-4">Verifikasi konfigurasi SMTP dengan mengirim email percobaan ke alamat Anda.</p>
        <form method="POST" action="{{ route('admin.system.settings.test-mail') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium mb-1" for="test-mail-to">Alamat Email Tujuan</label>
                <input type="email" name="to" id="test-mail-to" required value="{{ old("to", auth()->user()->email) }}"
                    class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-[#0b1420] text-sm font-semibold">
                <span class="material-symbols-outlined icon-sm align-text-bottom">send</span> Kirim Email Uji
            </button>
        </form>
    </div>

    {{-- ===== Pengaturan Fitur Paket (dipindah dari hak akses) ===== --}}
    <div class="bg-bg-surface rounded-xl border border-border p-6">
        <h2 class="font-semibold mb-1">Pengaturan Fitur Paket</h2>
        <p class="text-sm text-text-secondary mb-4">Atur batas penyimpanan &amp; fitur export/import per paket, atau tambah paket baru.</p>

        <div class="space-y-3 mb-6">
            @foreach ($plans as $plan)
                <div class="rounded-xl border border-border bg-bg-panel p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <span class="font-semibold text-text-primary">{{ $plan->name }}</span>
                        <span class="text-xs text-text-secondary">{{ $plan->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    </div>
                    <form method="POST" action="{{ route('admin.system.plans.update') }}">
                        @csrf
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs text-text-secondary mb-1">Label</label>
                                <input type="text" name="plans[{{ $plan->id }}][label]" value="{{ $plan->label }}"
                                    class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-text-secondary mb-1">Harga (Rp)</label>
                                <input type="number" name="plans[{{ $plan->id }}][price]" value="{{ $plan->price }}" min="0"
                                    class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-text-secondary mb-1">Storage (MB)</label>
                                <input type="number" name="plans[{{ $plan->id }}][storage_mb]" value="{{ $plan->storageLimitMb() }}" min="0"
                                    class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                            </div>
                            <div class="flex items-end justify-between gap-3 pb-1">
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="plans[{{ $plan->id }}][export]" value="1" @checked($plan->feature('export', false)) class="rounded bg-bg-surface border-border"> Export
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="plans[{{ $plan->id }}][import]" value="1" @checked($plan->feature('import', false)) class="rounded bg-bg-surface border-border"> Import
                                </label>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center gap-3">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-brand hover:bg-brand-hover text-[#0b1420] text-sm font-semibold">Simpan</button>
                            <form method="POST" action="{{ route('admin.system.plans.destroy', $plan) }}" class="inline" onsubmit="return confirm('Hapus paket ini?')">
                                @csrf
                                @method('DELETE')
                                <button class="text-status-danger hover:underline text-xs">Hapus</button>
                            </form>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>

        {{-- Form tambah paket --}}
        <div class="border-t border-border pt-4">
            <h3 class="text-sm font-semibold mb-3">Tambah Paket Baru</h3>
            <form method="POST" action="{{ route('admin.system.plans.store') }}" class="space-y-3">
                @csrf
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs text-text-secondary mb-1">Nama (slug)</label>
                        <input type="text" name="name" placeholder="mis. pro" required class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-text-secondary mb-1">Label</label>
                        <input type="text" name="label" placeholder="mis. Pro" required class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-text-secondary mb-1">Harga (Rp)</label>
                        <input type="number" name="price" min="0" required class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-text-secondary mb-1">Periode</label>
                        <select name="period" class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                            @foreach (['monthly' => 'Bulanan', 'yearly' => 'Tahunan', 'daily' => 'Harian', 'weekly' => 'Mingguan', 'once' => 'Sekali'] as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-text-secondary mb-1">Storage (MB)</label>
                        <input type="number" name="storage_mb" min="0" required class="w-full rounded-xl border border-border bg-bg-surface px-3 py-2 text-sm">
                    </div>
                    <div class="flex items-end gap-4 pb-1">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="export" value="1" class="rounded bg-bg-surface border-border"> Export</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="import" value="1" class="rounded bg-bg-surface border-border"> Import</label>
                    </div>
                </div>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-[#0b1420] text-sm font-semibold">+ Tambah Paket</button>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
@endsection
