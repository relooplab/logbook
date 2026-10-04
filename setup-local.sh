#!/usr/bin/env bash
# ============================================================================
# Setup & jalankan Logbook LOKAL (tanpa Docker)
# Dipakai untuk verifikasi cepat. Menjalankan: composer install,
# npm build, storage:link, menulis .env lokal, migrate --seed, lalu serve.
# ============================================================================
set -euo pipefail

# Direktori proyek (default: lokasi script ini).
cd "$(dirname "$0")"
ROOT="$(pwd)"
PORT="${1:-8000}"
HOST="${2:-127.0.0.1}"

echo ">>> Proyek: $ROOT"
echo ">>> Mode:   lokal (SQLite, tanpa Docker)"

# ----------------------------------------------------------------------------
# 1. Install dependensi PHP (regenerasi vendor/ dari composer.lock)
# ----------------------------------------------------------------------------
echo ""
echo ">>> [1/6] composer install"
if [ ! -d vendor ]; then
    composer install --no-interaction
else
    echo "    (vendor sudah ada, skip)"
fi

# ----------------------------------------------------------------------------
# 2. Instal & build aset frontend (PDF viewer React) — wajib untuk viewer
# ----------------------------------------------------------------------------
echo ""
echo ">>> [2/6] npm install & build (PDF viewer)"
if [ ! -d node_modules ]; then
    npm install
else
    echo "    (node_modules sudah ada, skip)"
fi
if [ ! -d public/build ]; then
    npm run build
else
    echo "    (public/build sudah ada, skip)"
fi

# ----------------------------------------------------------------------------
# 3. Tulis .env lokal (SQLite, tanpa Redis/MySQL) — SEBELUM key:generate
#    agar boot aplikasi tidak gagal oleh konfigurasi Docker/Redis.
# ----------------------------------------------------------------------------
echo ""
echo ">>> [3/6] konfigurasi .env lokal"
if [ ! -f .env ]; then
    cp .env.example .env
fi
# Set nilai lokal (sqlite, database, log) — idempotent.
sed -i \
    -e 's/^APP_ENV=.*/APP_ENV=local/' \
    -e 's/^APP_DEBUG=.*/APP_DEBUG=true/' \
    -e "s|^APP_URL=.*|APP_URL=http://$HOST:$PORT|" \
    -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' \
    -e '/^DB_HOST=/d' \
    -e '/^DB_PORT=/d' \
    -e '/^DB_DATABASE=/d' \
    -e '/^DB_USERNAME=/d' \
    -e '/^DB_PASSWORD=/d' \
    -e 's/^SESSION_DRIVER=.*/SESSION_DRIVER=database/' \
    -e 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' \
    -e 's/^CACHE_STORE=.*/CACHE_STORE=database/' \
    -e 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=log/' \
    -e 's/^MAIL_MAILER=.*/MAIL_MAILER=log/' \
    -e 's/^REDIS_HOST=.*/REDIS_HOST=127.0.0.1/' \
    .env
php artisan config:clear >/dev/null 2>&1 || true

# ----------------------------------------------------------------------------
# 4. Pastikan key
# ----------------------------------------------------------------------------
echo ""
echo ">>> [4/6] application key"
if ! grep -q '^APP_KEY=base64' .env 2>/dev/null; then
    php artisan key:generate --force
else
    echo "    (APP_KEY sudah ada)"
fi

# ----------------------------------------------------------------------------
# 5. Database sqlite + migrasi + seeder + storage:link
# ----------------------------------------------------------------------------
echo ""
echo ">>> [5/6] database & migrasi"
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --force
if [ ! -L public/storage ]; then
    php artisan storage:link
fi

# ----------------------------------------------------------------------------
# 6. Jalankan server
# ----------------------------------------------------------------------------
echo ""
echo ">>> [6/6] server: http://$HOST:$PORT"
echo ""
echo "============================================================"
echo "  Logbook siap!"
echo "  URL      : http://$HOST:$PORT"
echo "  Admin    : admin@example.com / password"
echo "  Dosen 2  : dosen2@example.com / password"
echo "  Mahasiswa: mahasiswa@example.com / password"
echo "  (Ctrl+C untuk berhenti)"
echo "============================================================"
echo ""

php artisan serve --host="$HOST" --port="$PORT"
