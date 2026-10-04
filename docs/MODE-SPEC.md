# Spesifikasi Mode Aplikasi — SaaS Unified

**Project:** Logbook
**Dokumen:** Desain mode penggunaan aplikasi (SaaS unified — user personal & institusi hidup bersamaan)
**Status:** Implementasi — acuan arsitektur

---

## 1. Tujuan

Aplikasi menggunakan **satu deployment SaaS** yang menampung **tiga jenis user**:

- **User personal/free** (`institution_id = NULL`) — dosen daftar mandiri, data TA milik dosen. Kuota storage dari plan individual (free 5 GB).
- **User individual paid** (`institution_id = NULL`) — dosen berlangganan plan berbayar (donasi 10 GB), data tetap milik dosen.
- **User institusi** (`institution_id = ID`) — dosen diadopsi ke institusi, data TA menjadi milik institusi. Kuota storage dari **shared pool institusi** (total semua directory_subscriptions) dengan batas per-user yang bisa diatur admin.

Tidak ada mode global yang membuat seluruh aplikasi menjadi individual atau institution. **Gate dilakukan per-user** berdasarkan `institution_id`, bukan `APP_MODE`.

---

## 2. Prinsip Desain

1. **Single codebase, single DB, single deploy** — tidak ada dua branch/dua aplikasi.
2. **Multitenancy ringan** via kolom `institution_id` (nullable) + **Global Scope** Laravel, aktif jika user login punya `institution_id`.
3. **Gate fitur per-user** — kelas `Feature` membaca `institution_id` user; `APP_MODE` hanya nilai netral (`saas`).
4. **Data pribadi bisa dibawa ke institusi** — mekanisme adopsi via System Admin (ubah `institution_id` user; data TA ikut diadopsi).
5. **Branding tetap ada** — satu record `institutions` bawaan dipakai sebagai fallback global.
6. **User free dan user institusi dapat hidup bersamaan** — karena gate dilakukan per user, bukan berdasarkan mode global.

---

## 3. Model Data

### 3.1 Kolom `institution_id` (nullable)

| Tabel | Kolom | Keterangan |
|---|---|---|
| `users` | `institution_id` (nullable) | Dosen/staff yang bergabung ke institusi. `NULL` = pemilik data pribadi (mode individual). |
| `mahasiswa_ta` | `institution_id` (nullable) | TA milik pribadi (`NULL`) atau milik institusi. |
| `sidangs` | `institution_id` (nullable) | Riwayat menguji — ada di kedua mode. |
| `institutions` | — (existing) | Satu record bawaan dipakai oleh mode individual. |

> Konvensi: **`institution_id = NULL` berarti "milik pemilik data" (individual)**. Saat data **diadopsi** ke institusi, kolom diisi `institution_id` dari institusi tujuan.

### 3.2 Konfigurasi mode

```env
# .env
APP_MODE=saas              # saas (default) — user personal & institusi hidup bersamaan
```

Dibaca di `config/app.php`:

```php
'mode' => env('APP_MODE', 'saas'),
```

> **Catatan**: `APP_MODE` hanya nilai netral untuk kompatibilitas. **Tidak lagi dipakai untuk meng-gate fitur.** Gate dilakukan per-user berdasarkan `institution_id`.

### 3.3 Kelas `Feature` (pintu cek fitur)

```php
// app/Support/Feature.php
class Feature
{
    public static function mode(): string
    {
        return config('app.mode', 'saas');
    }

    /**
     * Gate fitur dilakukan per-user berdasarkan institution_id.
     * User personal (institution_id null) dan user institusi (institution_id terisi)
     * hidup bersamaan dalam satu deployment.
     */
    public static function storageLimitMb(?User $user): int
    {
        // 1. Override admin — menang mutlak.
        // 2. User institusi: shared pool institusi (min dengan batas per-user).
        // 3. User personal: plan individual + addon.
    }

    public static function institutionStorageLimitMb(int $institutionId): int
    {
        // Total semua directory_subscriptions aktif milik institusi (shared pool).
    }

    public static function institutionStorageUsedMb(int $institutionId): int
    {
        // Total pemakaian storage seluruh user institusi.
    }
}
```

Controller memanggil `Feature::storageLimitMb($user)` / `Feature::institutionStorageLimitMb($institutionId)` — satu titik, mudah dirawat.

---

## 4. Perbandingan Fitur

| Fitur | 🌱 User Personal | 🏛️ User Institusi |
|---|---|---|
| `institution_id` | `NULL` (data pribadi) | Assigned (multi-tenant) |
| Tenant scope | Tidak aktif | Aktif (filter per institusi) |
| Kuota storage | Plan individual + addon | Shared pool institusi (min dengan batas per-user) |
| Langganan direktori | Tidak berlaku | Berlaku (directory_subscriptions) |
| Admin scope | Tidak berlaku | Berlaku (admin_scopes) |
| Workspace institusi | Tidak dapat akses | Dapat akses sesuai scope |
| Dosen berperan sebagai | **Pembimbing + Penguji** (satu orang, semua peran) | Bisa pembimbing/penguji per-role resmi |
| Pengguna | 1 dosen + mahasiswa pribadinya | Banyak dosen, admin prodi, koordinator, mahasiswa |
| Profil institusi | Satu record bawaan (`Perguruan Tinggi`) | Profil institusi resmi (nama, fakultas, prodi, logo, kontak) |
| Data TA | Hanya yang dibuat/register oleh dosen tsb | Terpusat lintas dosen di institusi |
| Registrasi mahasiswa | Mahasiswa **register sederhana** → dosen **approve** | Via admin / import / register + approve |
| Peran/roles | `dosen` + `mahasiswa` | `admin` prodi + `dosen` + `penguji` + `mahasiswa` (+ koordinator) |
| Pembimbing | ✅ Pembimbing 1/2 (dosen tsb) | Pembimbing 1/2 lintas dosen |
| **Penguji** | ✅ **Bisa jadi penguji** (dosen tsb) | ✅ Penguji 1/2 resmi |
| **Sidang / riwayat menguji** | ✅ **Ada** (riwayat menguji dosen tsb) | ✅ CRUD sidang, riwayat, BKD, export PDF |
| Status siklus TA (aktif/tamat/nonaktif) | ✅ Sederhana | ✅ Penuh + otomatis tamat saat sidang lulus |
| Dashboard dosen | Kartu statistik (bimbingan + pengujian) | Kartu + agregasi lintas dosen (koordinator) |
| Health indicator | Per mahasiswa sendiri | Per mahasiswa + agregat prodi |
| Weekly digest / notifikasi | Per dosen | Per dosen + digest institusi |
| Workspace file | Per mahasiswa | Per mahasiswa (tetap) + kontrol institusi |
| **Import mahasiswa (Excel)** | 🔒 (manual per-mahasiswa) | ✅ Bulk import + assign massal |
| Global search (Cmd+K) | Terbatas data sendiri | Mencakup seluruh institusi |
| Laporan/export | Rekap per mahasiswa + riwayat menguji | Rekap per mahasiswa + per dosen/institusi |
| **Bawa data pribadi ke institusi** | — | ✅ Alur adopsi via System Admin |
| Manajemen multi-dosen / koordinator | 🔒 | ✅ |

> 🔒 = fitur **prodi** (bulk import, koordinator, multi-dosen, laporan institusi) — hanya tersedia untuk user institusi.
>
> **Penting**: sidang, penguji, dan riwayat menguji **TIDAK dikunci** — tersedia untuk semua user, karena satu dosen individual bisa bertindak sebagai pembimbing maupun penguji.

---

## 5. Global Scope Tenant (aktif jika user punya institution_id)

```php
// app/Models/Scopes/InstitutionScope.php
class InstitutionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = auth()->user()?->institution_id;
        if ($tenant) {
            $builder->where($model->qualifyColumn('institution_id'), $tenant);
        }
        // User personal (institution_id null): tanpa filter (data pribadi pemilik).
    }
}
```

Dipakai di model yang punya `institution_id` (mis. `MahasiswaTa`). Jika user login punya `institution_id`, semua query otomatis hanya mengambil data institusi aktif. User personal tidak ter-filter.

---

## 5A. Alur Registrasi Mahasiswa & Attachment Dosen

Di mode individual, **mahasiswa dapat mendaftar sendiri** dengan form sederhana, **memverifikasi email**, lalu **memilih dosen** (pembimbing/penguji) yang akan menyetujui atau menolak permintaan attachment.

### 5A.1 Alur

```
1. Mahasiswa membuka halaman "Daftar" (register).
2. Form sederhana: NAMA, EMAIL, PASSWORD.
   → Akun dibuat ber-role `mahasiswa`, status ACTIVE (sudah verifikasi email, belum attach dosen).
3. Sistem mengirim email verifikasi; mahasiswa harus verifikasi sebelum login penuh.
4. Setelah login, mahasiswa melihat banner "Pilih Dosen untuk Memulai Program" di dashboard.
5. Mahasiswa membuka halaman "Pilih Dosen" (/profil/pilih-dosen):
   - pilih jenis program (TA/KP),
   - pilih Pembimbing 1 (wajib), Pembimbing 2, Penguji 1, Penguji 2 (opsional).
   → Sistem membuat `mahasiswa_ta` dengan status `pending_approval`.
6. Dosen yang dipilih menerima notifikasi & melihat daftar "Permintaan Menunggu" di /approval.
7. Dosen klik "Setujui & Assign":
   - pilih peran dosen terhadap TA-nya:
       • Pembimbing 1 / Pembimbing 2
       • Penguji 1 / Penguji 2
   - isi data TA (judul/tempat KP, target sesi) bila perlu.
   → `mahasiswa_ta` menjadi `aktif`, mahasiswa menjadi `verified`.
   Atau dosen klik "Tolak" → `mahasiswa_ta` menjadi `ditolak`, mahasiswa dapat memilih dosen lain.
8. Mahasiswa dapat memakai aplikasi; TA ter-assign ke dosen tsb.
```

### 5A.2 Skema data untuk status registrasi

```php
// users
$table->string('registration_status')->default('active');
// 'active'   → mahasiswa sudah verifikasi email, belum attach dosen
// 'verified' → mahasiswa sudah punya MahasiswaTa dengan dosen (disetujui)
// 'rejected' → ditolak (tidak bisa login)
// 'pending'  → khusus dosen: menunggu persetujuan admin
```

- `registration_status = 'active'` → mahasiswa bisa login, tetapi belum punya program/dosen.
- Dosen yang approve → set `verified` + `mahasiswa_ta.status_ta = aktif`.
- Mahasiswa yang ditolak → `rejected` (tidak bisa login).

### 5A.3 Form registrasi (individual)

- Hanya membutuhkan **nama**, **email** (wajib, validasi unik), **password**.
- Tidak perlu NIM/NIDN di awal (bisa dilengkapi nanti).
- Setelah submit → email verifikasi dikirim; mahasiswa diarahkan ke halaman verifikasi.
- **Dosen** yang mendaftar tetap berstatus `pending` dan menunggu persetujuan admin.

### 5A.4 Fase/Milestone saat memilih dosen

Saat mahasiswa memilih dosen, form **"Pilih Dosen"** juga menampilkan **dropdown Fase/Milestone** yang disesuaikan dengan jenis program (TA/KP). Mahasiswa memilih fase yang sedang dijalani, dan dosen dapat menyesuaikannya saat menyetujui permintaan attachment.

### 5A.5 Menetapkan peran (approve)

Saat dosen menyetujui permintaan attachment, dosen memilih bagaimana dirinya terhubung ke mahasiswa tsb:

| Pilihan | Efek pada `mahasiswa_ta` |
|---|---|
| Pembimbing 1 | `pembimbing_1_id = dosen` |
| Pembimbing 2 | `pembimbing_2_id = dosen` |
| Penguji 1 | `penguji_1_id = dosen` |
| Penguji 2 | `penguji_2_id = dosen` |

Karena di individual dosen memegang semua peran, dosen bisa menetapkan dirinya **sebagai pembimbing sekaligus penguji** (kolom berbeda di baris TA yang sama, atau dua entri sidang).

### 5A.6 Pembersihan mahasiswa tidak aktif

- Command `students:delete-inactive` (terjadwal harian 03:30) menghapus mahasiswa berstatus `active` yang **tidak memilih dosen dalam 1 bulan** (akun + data terkait dihapus bersih dalam transaksi).

### 5A.7 Kapan berlaku
- **Mode individual**: alur registrasi+attachment ini adalah **jalur utama** menambah mahasiswa.
- **Mode institusi**: registrasi juga bisa ada, tetapi biasanya lewat **admin/import**; approval dilakukan admin/koordinator.

---

## 5B. Sidang & Riwayat Menguji di Mode Individual

- Dosen individual tetap bisa mencatat **sidang** (seminar proposal / sidang akhir) dan menyimpan **riwayat menguji** (untuk pelaporan/portofolio).
- **Penguji bisa menguji mahasiswa ORANG LAIN** (di luar bimbingannya), bukan hanya mahasiswa bimbingan sendiri.
- `sidangs.penguji_id` = dosen tsb (atau mahasiswa yang diizinkan jadi penguji); `sidangs.institution_id` = NULL (milik pribadi).
- Fitur ini **tidak dikunci** — sama seperti institusi, hanya cakupannya terbatas pada data dosen itu sendiri.

### 5B.1 Siapa bisa jadi penguji di mode individual
1. **Dosen** — mencatat sidang mahasiswa mana pun (termasuk mahasiswa orang lain), untuk riwayat menguji/portofolio.

### 5B.2 Konteks sidang (mahasiswa lain)
Saat dosen mencatat sidang mahasiswa yang bukan bimbingannya, dosen perlu konteks siapa pembimbing mahasiswa tsb (mengisi manual, sesuai 5A.4): Pembimbing 1/2/3. Ini dipakai untuk laporan & identifikasi.

---

## 6. Alur "Bawa Data Pribadi ke Institusi"

### 6.1 Konsep
Saat dosen **bergabung/join** ke institusi, mahasiswa pribadinya (`institution_id = NULL` dan `pembimbing = dosen tsb`) dapat **diadopsi** ke institusi tersebut.

### 6.2 Alur adopsi via System Admin

System Admin mengubah `institution_id` user via halaman **Kelola Pengguna** (dropdown institusi di kolom aksi):

```php
// AdminController::updateUserInstitution()
// Route: POST /admin/users/{user}/institution
```

Logika:

1. System Admin memilih institusi tujuan (atau "Personal" untuk mengeluarkan user dari institusi).
2. Jika mengadopsi ke institusi, pastikan institusi punya langganan aktif (`Feature::institutionHasActiveDirectorySubscription()`).
3. Update `users.institution_id`.
4. **Adopsi data TA**: semua `mahasiswa_ta` milik user ikut pindah institusi (`institution_id` ikut terisi).
5. Jika dosen, adopsi juga TA yang dibimbingnya (pembimbing 1/2).

### 6.3 Keamanan
- Hanya System Admin yang dapat mengubah institusi user.
- Institusi tujuan harus punya langganan aktif.
- Data yang sudah `institution_id` terisi akan ikut pindah saat user dipindahkan.

---

## 7. Penjagaan (Safeguards)

1. **Global Scope aktif jika user punya `institution_id`** — user personal tidak ter-filter.
2. **Gate fitur per-user** — `Feature::storageLimitMb()` membedakan user personal vs institusi.
3. **Adopsi satu arah & tercatat** — hanya System Admin, dengan validasi langganan aktif.
4. **Migration `institution_id` nullable** — tidak memaksa data lama.
5. **Seeder** menciptakan satu record `institutions` bawaan sebagai fallback global.
6. **Shared pool kuota** — `Feature::institutionStorageLimitMb()` + `institutionStorageUsedMb()` memastikan institusi tidak melebihi kuota langganan.

---

## 8. Roadmap Implementasi (sudah selesai)

| Fase | Isi | Status |
|---|---|---|
| **A** | Migration `institution_id` (users, mahasiswa_ta, sidangs) + `registration_status` + config `APP_MODE` + kelas `Feature` | ✅ Selesai |
| **B** | Global Scope `InstitutionScope` (aktif jika user punya `institution_id`) | ✅ Selesai |
| **C** | **Registrasi mahasiswa + approve & assign peran**: form sederhana, list pending, approve-set peran | ✅ Selesai |
| **D** | Sidang & riwayat menguji berjalan untuk semua user (dosen bisa jadi penguji) | ✅ Selesai |
| **E** | Alur adopsi via System Admin (`updateUserInstitution`) | ✅ Selesai |
| **F** | Gate fitur per-user (bukan mode global) | ✅ Selesai |
| **G** | Shared pool kuota institusi + batas per-user | ✅ Selesai |
| **H** | Laporan/agregasi koordinator (user institusi) | 🔄 Berjalan |

---

## 9. Catatan Keputusan

- **NULL = personal** dipertahankan sebagai konvensi sederhana.
- **Satu record `institutions` bawaan** sebagai fallback global (pre-auth, console, queue).
- **`Feature` + Global Scope** = dua mekanisme komplementer: fitur-gate untuk UI/controller, scope untuk data isolation.
- **Sidang, penguji, & riwayat menguji TIDAK dikunci** — tersedia untuk semua user.
- **Gate fitur per-user** — bukan berdasarkan `APP_MODE` global. `APP_MODE=saas` hanya nilai netral.
- **Shared pool kuota institusi** — institusi membeli 100 GB, seluruh data TA institusi memakai pool 100 GB. Batas per-user bisa diatur admin (min(pool, batas per-user)).
- **Alur adopsi** — System Admin mengubah `institution_id` user; data TA ikut diadopsi.

---

## 10. Keputusan yang Sudah Dikonfirmasi

1. **Mode deployment** — `APP_MODE=saas` (unified). User personal & institusi hidup bersamaan.
2. **Dosen boleh join > 1 institusi** — saat ini satu `institution_id`; multi-institusi = tabel pivot `user_institution`, lebih kompleks (belum diimplementasikan).
3. **Mahasiswa pribadi yang diadopsi** — tetap bisa dikelola dosen yang sama setelah pindah ke institusi (dosen tetap pembimbing).
4. **Dosen perlu approve mahasiswa** — mahasiswa bisa register + dosen juga bisa tambah manual.
5. **Penguji di personal** — dosen bisa mencatat sidang/riwayat menguji mahasiswa di luar bimbingannya.
6. **Registrasi publik di mode institusi** — user self-register tetap bisa, tapi `institution_id = null` (terisolasi). Keputusan: biarkan seperti sekarang.

---

## 11. Direktori Organisasi & Grup Dosen (Penambahan)

### 11.1 Direktori Organisasi Hierarkis (4 level)

Untuk mendukung penggunaan oleh dosen dari berbagai perguruan tinggi di Indonesia, aplikasi memiliki **direktori organisasi hierarkis**:

```
universities (perguruan tinggi)
  └── faculties (fakultas)
        └── departments (departemen)
              └── study_programs (program studi)
```

- **Deduplikasi alami** via constraint unik — nama perguruan tinggi tidak muncul dua kali; fakultas/departemen/prodi unik di dalam induknya.
- **`user_university`** (pivot) — mendukung **multi-universitas**: satu dosen bisa terhubung ke banyak perguruan tinggi. `is_primary` menandai universitas utama.
- **`users.nidn`** — identitas dosen (unique).

### 11.2 Alur Registrasi & Afiliasi

- **Dosen** mendaftar dengan **NIDN** + memilih/membuat perguruan tinggi (dedup case-insensitive via `OrganizationalDirectoryService`).
- **Mahasiswa** yang di-invite/disetujui dosen **otomatis mengikuti institusi dosen** (universitas dosen disalin ke mahasiswa, tanpa input ulang).

### 11.3 Grup & Cross-link Dosen

- **`groups`** — grup di level universitas/fakultas/departemen/prodi.
- **`group_members`** — anggota grup dengan status `pending/approved/rejected` dan role `owner/member`.
- **Alur**: dosen membuat grup → mengundang dosen lain dari universitas yang sama → yang diundang **approve** → terhubung (cross-link).
- **Akses "hanya hubungan langsung"** — data hanya bisa diakses jika ada hubungan langsung (TA bersama, grup bersama, atau dosen-mahasiswa bimbingan). Diimplementasikan via `User::hasDirectRelation()` dan diperkuat di `LogbookEntryPolicy` & `MahasiswaTaPolicy`.

### 11.4 Workspace Dosen

- **`workspace_files.user_id`** (nullable) — mendukung workspace pribadi dosen (selain `mahasiswa_ta_id` untuk mahasiswa).
- Halaman `/workspace-saya` untuk mengelola file pribadi dosen.

---

## 12. Manajemen File & Storage

### 12.1 Prinsip

- Semua file yang diupload user tersimpan di salah satu dari dua disk:
  - **`local`** (privat) — hanya bisa diakses lewat controller yang memverifikasi otorisasi (mis. `LogbookController::inlinePdf()`, `WorkspaceController::download()`). Dipakai untuk seluruh dokumen/lampiran.
  - **`public`** (dilayani langsung webserver, tanpa otorisasi) — hanya dipakai untuk konten yang memang perlu tampil langsung di UI (foto profil), dengan nama file acak (`hashName()`) agar tidak bisa ditebak/di-enumerasi.
- Ekstensi file **selalu** ditentukan dari deteksi konten server (mis. `getimagesize()` untuk gambar, `guessExtension()` bawaan Laravel), **bukan** dari nama/klaim client — mencegah file polyglot dieksekusi sebagai skrip server.
- Kuota penyimpanan (`StorageUsageService`) **dibebankan ke dosen pembimbing 1** (fallback pembimbing 2) untuk seluruh file terkait mahasiswa bimbingannya; dosen/admin punya kuota terpisah untuk file pribadi mereka sendiri (workspace pribadi, foto profil).
- File yang kehilangan referensi database (mis. akun/program dihapus, cascade delete) dibersihkan otomatis oleh command terjadwal `files:prune-orphans` (mingguan, buffer 30 hari). File yang **masih** direferensikan baris DB manapun **tidak pernah** disentuh, berapa pun umurnya — job ini hanya query ulang referensi terkini tiap kali jalan, bukan berdasar usia semata.

### 12.2 Inventaris File Upload

| # | File | Disk & Lokasi | Pemilik Kuota | Siapa Bisa Upload | Siapa Bisa Edit/Ganti | Siapa Bisa Hapus Manual | Auto-terhapus (Orphan Job) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Lampiran logbook (draft/revisi) | `local` / `lampiran/{entry_id}/` | Dosen pembimbing 1 (fallback 2) | Mahasiswa pemilik/anggota TA-KP | Mahasiswa, hanya saat entri masih *draft/revisi* (`isEditable()`) | Mahasiswa via `removeLampiran()`, kondisi sama | ✅ Ya |
| 2 | Catatan perbaikan (PDF auto-generate) | `local` / `catatan/{entry_id}/` | Dosen pembimbing 1 (fallback 2) | Sistem (otomatis dari `riwayat_perbaikan` saat mahasiswa submit revisi) | Regenerate otomatis tiap entri revisi diedit | Mahasiswa via `removeCatatan()`, saat editable | ✅ Ya |
| 3 | Foto logbook harian KP | `local` / `logbook-harian/{entry_id}/` | Dosen pembimbing 1 (fallback 2) | Mahasiswa anggota kelompok KP | Hanya penulis asli (`created_by`) | Penulis asli, via `destroy()`/ganti | ✅ Ya |
| 4 | Workspace file mahasiswa | `local` / `workspace/{ta_id}/` | Dosen pembimbing 1 (fallback 2) | Anggota kelompok TA/KP | Metadata (bab/deskripsi) oleh anggota | Anggota kelompok, via `destroy()` | ✅ Ya |
| 5 | Workspace file pribadi dosen | `local` / `workspace/dosen/{user_id}/` | Dosen itu sendiri | Dosen/admin (untuk diri sendiri) | Metadata oleh pemilik | Pemilik, via `destroy()` | ✅ Ya |
| 6 | Undangan seminar/sidang | `local` / `seminar-materials/{ta_id}/` | Dosen pembimbing 1 (fallback 2) | Mahasiswa anggota TA/KP | Mahasiswa, selama belum dikonversi ke sidang (`sidang_id` null) | Tidak ada hapus mandiri (hanya ganti) | ✅ Ya (file lama ter-orphan saat ganti) |
| 7 | Materi seminar/sidang | `local` / `seminar-materials/{ta_id}/` (atau rujuk workspace) | Dosen pembimbing 1 (fallback 2) | Mahasiswa anggota TA/KP | Sama seperti #6 | Tidak ada hapus mandiri | ✅ Ya (dilindungi bila berasal dari workspace, cek `materiFromWorkspace()`) |
| 8 | Finalisasi TA/KP (cover/pengesahan/full file) | `local` / `finalization/{ta_id}/{jenis}/` | Dosen pembimbing 1 (fallback 2) | Mahasiswa anggota TA/KP | Mahasiswa, upload ulang kapan saja (selalu replace) | Tidak ada hapus mandiri | ✅ Ya (file lama selalu ter-orphan tiap upload ulang) |
| 9 | Foto profil | `public` / `profiles/` | Dosen (miliknya sendiri) atau dosen pembimbing mahasiswa (untuk foto mahasiswa bimbingannya) | User sendiri (mahasiswa/dosen/admin) | User sendiri (file lama dihapus otomatis saat ganti) | Tidak ada tombol hapus mandiri (hanya ganti) | ✅ Ya |
| 10 | Logo institusi | `local` / `institution/` | Tidak dibebankan ke kuota user manapun (resource singleton institusi) | Admin | Admin (file lama dihapus otomatis saat ganti) | Tidak ada | ✅ Ya |

> **Penerima revisi (TA/KP bimbingan)** — `logbook_entries.dosen_id` pada entri revisi menyimpan penerima yang dipilih mahasiswa: **dosen pembimbing** atau **dosen penguji** (`addressed_dosen_id` pada form). Penerima menjadi reviewer entri (`LogbookEntryPolicy::reviewerIds()`), muncul di Antrean Review, dan dapat menyetujui / meminta revisi. Pembimbing tetap menerima notifikasi (CC) dan tetap dapat mereview. Entri lama (`dosen_id` = pembimbing) berperilaku sama seperti sebelumnya — tidak ada migrasi/backfill.

> **Toggle notifikasi berkala (per institusi)** — `institutions.weekly_digest_enabled` (default ON) mengontrol `ta:weekly-digest` (Senin 07:00); `institutions.daily_reminder_enabled` (default ON) mengontrol `logbook:send-reminders` + `ta:notify-inactive` (harian 08:00). Command memeriksa `Institution::forUser($penerima)` per penerima sehingga satu institusi bisa OFF sementara institusi lain tetap menerima. Diatur system admin di panel Pengaturan → *Notifikasi Berkala* (`updateSystemSettings`, form parsial `_notification_form` agar tidak menimpa SMTP).

### 12.3 Mekanisme Orphan Cleanup

`files:prune-orphans` (`app/Console/Commands/PruneOrphanFiles.php`, terjadwal mingguan Minggu 03:00, buffer 30 hari):

1. Kumpulkan ulang seluruh path yang **masih** direferensikan dari kolom terkait di setiap tabel (query fresh, bukan cache).
2. Scan seluruh file fisik di folder-folder disk `local` (`lampiran`, `catatan`, `workspace`, `seminar-materials`, `finalization`, `logbook-harian`, `institution`) dan disk `public` (`profiles`).
3. File yang pathnya **tidak** ada di daftar referensi DAN sudah berumur ≥30 hari → dihapus.
4. File yang masih direferensikan → selalu di-skip, tidak peduli usianya.

Skenario file kehilangan referensi (jadi kandidat orphan):

- Baris DB diganti saat user upload ulang/replace (pola "file lama di-orphan, bukan langsung dihapus").
- Cascade delete berantai: `User` → `MahasiswaTa` → (`LogbookEntry`, `LogbookHarianKp`, `SeminarSubmission`, `ThesisFinalization`, `WorkspaceFile`) — semua FK `cascadeOnDelete()`. Baris DB hilang seketika, file fisik baru terhapus setelah buffer 30 hari via job ini.
