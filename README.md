# Logbook

Logbook membantu mahasiswa, dosen, dan pengelola akademik mengelola bimbingan Tugas Akhir (TA) dan Kerja Praktik (KP). Catatan, dokumen, keputusan review, dan perkembangan mahasiswa terdokumentasi dalam satu aplikasi.

**Aplikasi:** [logbook.reloop.id](https://logbook.reloop.id) · **Panduan:** [Panduan Pengguna](docs/USER-GUIDE.md)

## Pratinjau

| Dashboard mahasiswa | Dashboard dosen |
| :---: | :---: |
| ![Dashboard mahasiswa: ringkasan progres dan aktivitas bimbingan](public/images/readme-dashboard-mahasiswa.jpeg) | ![Dashboard dosen: mahasiswa bimbingan dan antrean review](public/images/readme-dashboard-dosen.jpeg) |

## Fitur utama

- **Logbook dan revisi:** mahasiswa mencatat sesi bimbingan, mengunggah dokumen, dan mengikuti status pengajuan.
- **Keputusan dosen:** dosen dapat menyetujui, meminta revisi, atau mengarsipkan entri; catatan arsip bersifat opsional.
- **Peninjauan PDF:** anotasi dan komentar pada bagian dokumen yang relevan.
- **Dashboard sesuai peran:** ringkasan progres bagi mahasiswa, antrean review bagi dosen, dan alat administrasi bagi pengelola.
- **Berkas dan komunikasi:** ruang kerja dokumen, chat, pengumuman, dan notifikasi.
- **Alur akademik:** pengelolaan pembimbing dan penguji, bahan seminar, sidang, hingga finalisasi TA/KP.
- **Akses personal dan institusi:** hak akses berbasis peran dengan pemisahan data institusi.

## Teknologi

| Komponen | Teknologi |
| --- | --- |
| Backend | Laravel 11 · PHP 8.4 |
| Antarmuka | Blade · Tailwind CSS · React · PDF.js |
| Database, cache, dan antrean | MySQL 8.4 · Redis |
| Realtime | Laravel Reverb |
| Web server dan deployment | Nginx · Docker Compose |

## Menjalankan secara lokal

**Prasyarat:** PHP 8.4, Composer, Node.js 20+, npm, dan SQLite. Di direktori pilihan Anda, pasang dependensi dan salin contoh konfigurasi:

```bash
git clone https://github.com/relooplab/logbook.git
cd logbook
composer install
npm ci
cp .env.example .env
```

Sebelum menjalankan Artisan, sesuaikan nilai berikut di `.env` agar aplikasi lokal menggunakan SQLite tanpa MySQL atau Redis:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=log
MAIL_MAILER=log
```

Kemudian siapkan database, bangun aset, dan jalankan aplikasi:

```bash
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm run build
php artisan serve
```

Buka <http://127.0.0.1:8000>. Saat mengubah antarmuka, jalankan `npm run dev` di terminal lain untuk memuat ulang aset secara otomatis.

## Deployment dengan Docker Compose

Sebelum menjalankan Compose, salin `.env.example` menjadi `.env` dan isi `APP_KEY`, `APP_URL`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, serta `REVERB_APP_ID`, `REVERB_APP_KEY`, dan `REVERB_APP_SECRET`. Gunakan kredensial unik untuk setiap lingkungan; jangan masukkan `.env` ke Git.

```bash
cp .env.example .env
# Lengkapi .env terlebih dahulu, lalu:
docker compose config --quiet
docker compose up -d --build
docker compose exec logbook-ta-app php artisan migrate --force
docker compose ps
```

Untuk akses publik, gunakan reverse proxy HTTPS, tetapkan `APP_DEBUG=false`, dan konfigurasikan SMTP produksi melalui variabel `MAIL_*`. Secara default, port Mailpit hanya terikat ke localhost untuk pengujian; jangan membukanya ke internet. Cadangkan database dan berkas sebelum migrasi atau deployment. Rincian variabel tersedia di [`.env.example`](.env.example).

## Pengujian

Jalankan `php artisan test` menggunakan **database pengujian terpisah**. Jangan arahkan test suite ke database produksi.

## Dokumentasi dan lisensi

- [Panduan Pengguna](docs/USER-GUIDE.md) · [API](docs/API.md) · [Mode personal/institusi](docs/MODE-SPEC.md) · [Glosarium](docs/GLOSSARY.md)
- Kode ini menggunakan [Business Source License 1.1](LICENSE), **bukan lisensi open source**. Penggunaan produksi pribadi (noninstitusional) diizinkan sesuai ketentuan lisensi; penggunaan produksi oleh atau untuk institusi memerlukan lisensi komersial terpisah. Pertanyaan lisensi: [dev@reloop.id](mailto:dev@reloop.id).
