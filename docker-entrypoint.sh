#!/bin/sh
# ============================================================================
# Logbook — entrypoint
# 1. Pastikan named volume /var/www/public (dibagi ke nginx) berisi aset
#    frontend + pdfjs + css yang di-bake di image (dari /public-dist).
# 2. Buat symlink storage -> public/storage agar foto profil dapat diakses.
# ============================================================================
set -e

# Selalu sinkronkan aset bawaan image (build/pdfjs/css) dari /public-dist.
# Tanpa sinkronisasi ulang, named volume public/ menyimpan aset Vite yang STALE
# setelah image diperbarui (hash aset berubah) -> viewer PDF/anotasi rusak.
# Hapus build/pdfjs/css lama agar tidak menumpuk, lalu salin ulang dari image.
rm -rf /var/www/public/build /var/www/public/pdfjs /var/www/public/css
cp -a /public-dist/. /var/www/public/

# Pastikan struktur storage ada walau named/bind volume kosong saat first deploy.
# Folder-folder ini dibuat di image saat build (Dockerfile), tapi volume kosong
# menimpa isinya -> view compiler crash ("Please provide a valid cache path"),
# session/cache gagal, dan upload ke storage/app/public error.
mkdir -p \
    /var/www/storage/framework/views \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/cache/data \
    /var/www/storage/logs \
    /var/www/storage/app/public \
    /var/www/bootstrap/cache
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod -R ug+rw /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# Pastikan storage:link selalu ada & menunjuk target yang benar.
# Recreate tiap start (bukan hanya saat belum ada): volume public_pp persisten,
# jadi symlink basi dari deployment lama bisa tertinggal di dalamnya — tanpa
# recreate ini foto profil tetap 404 meski kode sudah baru.
if [ ! -e /var/www/public/storage ] || [ -L /var/www/public/storage ]; then
    ln -sfn /var/www/storage/app/public /var/www/public/storage || php artisan storage:link || true
else
    php artisan storage:link || true
fi

# Auto-migrate saat first deploy (idempoten — hanya jalankan migration yang belum).
# DB (MySQL) sudah dijadwalkan healthy via depends_on pada service app, tapi queue/
# scheduler/reverb juga memakai entrypoint ini; guard di sini agar tidak gagal
# ketika DB belum dapat diakses (mis. saat container lain boot lebih dulu).
if [ -n "$APP_ENV" ] && [ "$APP_ENV" != "testing" ]; then
    if php artisan migrate:status >/dev/null 2>&1; then
        php artisan migrate --force --no-interaction || true
    fi
fi

# Teruskan ke perintah utama (php-fpm / queue / scheduler / reverb).
exec "$@"
