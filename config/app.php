<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Logbook'),

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | Versi rilis perangkat lunak, ditampilkan di footer sidebar.
    |
    */

    'version' => env('APP_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS Scheme
    |--------------------------------------------------------------------------
    |
    | Saat akses lewat reverse-proxy HTTPS (mis. https://domain), semua URL yang
    | digenerate Laravel harus memakai https agar tidak terjadi mixed content
    | (yang memblokir viewer PDF/anotasi). Aktifkan via APP_FORCE_HTTPS=true.
    |
    */

    'force_https' => env('APP_FORCE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Daftar IP/nilai proxy yang dipercaya untuk header X-Forwarded-* (mis.
    | `X-Forwarded-Proto`, `X-Forwarded-For`). Dipakai oleh trustProxies() agar
    | Laravel tahu skema https saat di belakang reverse-proxy.
    |
    | WAJIB berupa CIDR/nilai yang valid. Default kosong = tidak mempercayai
    | header forwarded apa pun (aman), sehingga mencegah TypeError di
    | Symfony\IpUtils saat `X-Forwarded-For` kosong/null.
    |
    */

    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Enforce Dosen Affiliation Onboarding
    |--------------------------------------------------------------------------
    |
    | Bila true, dosen yang BELUM punya afiliasi institusi lengkap tidak dapat
    | mengakses fitur lain (diarahkan ke halaman afiliasi). Aktif otomatis di
    | local/production; dinonaktifkan di env testing agar fixture test berjalan.
    |
    */

    'enforce_dosen_affiliation' => env('APP_ENFORCE_DOSEN_AFFILIATION', env('APP_ENV', 'production') !== 'testing'),

    /*
    |--------------------------------------------------------------------------
    | Enforce Email Verification Middleware
    |--------------------------------------------------------------------------
    |
    | Bila true, middleware `ensure.email.verified` akan aktif. Otomatis
    | mengikuti setting institusi `email_verification_required`, tapi
    | flag ini memungkinkan force-disable di env testing atau untuk
    | men-debug tanpa mematikan setting.
    |
    */

    'enforce_email_verification' => env('APP_ENFORCE_EMAIL_VERIFICATION', env('APP_ENV', 'production') !== 'testing'),

    /*
    |--------------------------------------------------------------------------
    | Enforce Dosen Pending Approval (Gate Lunak)
    |--------------------------------------------------------------------------
    | Bila true, middleware `ensure.dosen.decision` aktif: dosen yang punya
    | mahasiswa pending yang SUDAH melewati batas waktu diarahkan ke halaman
    | Persetujuan sampai memutuskan Approve/Tolak. Batas waktu (hari) diatur
    | `dosen_pending_approval_deadline_days`. Nonaktif di env testing.
    |
    */

    'enforce_dosen_pending_approval' => env('APP_ENFORCE_DOSEN_PENDING_APPROVAL', env('APP_ENV', 'production') !== 'testing'),

    'dosen_pending_approval_deadline_days' => env('APP_DOSEN_PENDING_APPROVAL_DEADLINE_DAYS', 4),

    /*
    |--------------------------------------------------------------------------
    | External Links (configurable per-institution)
    |--------------------------------------------------------------------------
    | Tautan eksternal yang mungkin berbeda antar institusi. Isi di .env:
    |   APP_JADWAL_URL  -> tautan "Jadwal Bimbingan" (default contoh placeholder)
    |   APP_TEMPLATE_URL -> tautan template catatan perbaikan revisi
    */

    'jadwal_url' => env('APP_JADWAL_URL', 'https://<schedule-url>/'),
    'template_url' => env('APP_TEMPLATE_URL', 'https://<template-url>/'),

    /*
    |--------------------------------------------------------------------------
    | Application Mode
    |--------------------------------------------------------------------------
    | 'saas' (default) — deployment unified. User personal (institution_id NULL)
    |   dan user institusi (institution_id terisi) hidup bersamaan.
    |   Gate fitur dilakukan per-user, bukan berdasarkan mode global.
    |   Nilai lain ('individual'/'institution') dipertahankan untuk kompatibilitas
    |   namun tidak lagi dipakai untuk meng-gate fitur.
    */

    'mode' => env('APP_MODE', 'saas'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
