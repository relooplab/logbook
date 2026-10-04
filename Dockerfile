# ============================================================================
# Logbook — PHP-FPM image (source DI-BAKE ke image, bukan bind mount)
# ============================================================================
# Stage 1: build aset frontend (React viewer) dengan Node
FROM node:20-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --legacy-peer-deps --no-audit --no-fund || npm install --legacy-peer-deps --no-audit --no-fund
# Fix #1: COPY file & direktori dipisah agar tidak ambigu.
COPY vite.config.js ./
COPY tailwind.config.js ./
COPY postcss.config.js ./
COPY resources ./resources
COPY public/pdfjs ./public/pdfjs
RUN npm run build

# Stage 2: runtime PHP-FPM
# Fix #2: naikkan ke 8.4 agar cocok dengan symfony/* di composer.lock (butuh >=8.4.1).
FROM php:8.4-fpm-alpine

# --- system deps ---
# mariadb-client: wire-compatible dgn MySQL 8.4 server, menyediakan mysqldump/mysql
# untuk fitur system backup & restore (app/Services/SystemBackupService.php dkk).
RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    libpng-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    zlib-dev \
    oniguruma-dev \
    mariadb-client \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        opcache \
        gd \
        intl \
        zip \
        pcntl \
        exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# --- composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Install PHP deps (cache layer by copying manifests first)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --no-dev --prefer-dist --no-progress

# Copy seluruh source (BAKE ke image)
COPY . .

# Fix #7: pastikan CHANGELOG.md & VERSION selalu ada di image. ReleaseVersion
# membaca CHANGELOG (sumber utama = versi latest) lalu VERSION sebagai fallback.
# Eksplisit COPY agar tidak hilang bila .dockerignore berubah — sebelumnya
# *.md mengecualikan CHANGELOG.md dan menampilkan fallback 0.0.0.
COPY VERSION ./VERSION
COPY CHANGELOG.md ./CHANGELOG.md

# Ambil aset frontend yang sudah di-build dari stage assets
COPY --from=assets /build/public/build ./public/build

# Fix #6: simpan salinan public/ bawaan di /public-dist.
# Named volume /var/www/public (dibagi ke nginx) akan diisi dari sini
# oleh docker-entrypoint.sh bila volume masih kosong.
RUN mkdir -p /public-dist \
    && cp -a public/. /public-dist/ \
    && rm -rf public/storage

# Finalize autoload + assets
# Fix worker crash "PailServiceProvider not found":
# - Hapus cache stale bootstrap/cache/packages.php & services.php yang di-copy
#   dari lokal (COPY . .) — bisa memuat provider dev-only (laravel/pail, dll)
#   yang tidak terpasang di image production (--no-dev).
# - Jalankan composer install TANPA --no-scripts agar `package:discover`
#   meregenerasi provider hanya dari dependency yang benar-benar terpasang.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer install --no-interaction --no-dev --optimize-autoloader --no-progress \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

# PHP config: upload/post dinaikkan ke 512M agar ZIP restore (DB dump + seluruh
# storage) bisa diupload; max_execution_time 1800s (30 menit) karena system
# backup/restore sengaja tetap sinkron (bukan queued job) — mysqldump/mysql/zip
# untuk data besar butuh waktu lebih dari default 30s. Nginx fastcgi_read_timeout
# (nginx/default.conf) harus tetap selaras dengan nilai ini.
RUN { \
    echo 'upload_max_filesize=1G'; \
    echo 'post_max_size=1G'; \
    echo 'max_file_uploads=100'; \
    echo 'max_execution_time=1800'; \
    echo 'memory_limit=256M'; \
    echo 'date.timezone=Asia/Jakarta'; \
  } > "$PHP_INI_DIR/conf.d/logbook.ini"

# Fix #6: entrypoint menyalin public/ ke named volume bersama (nginx).
# Jika volume kosong, isi dari file yang di-bake agar aset & symlink tersedia.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

CMD ["php-fpm"]
ENTRYPOINT ["docker-entrypoint.sh"]
