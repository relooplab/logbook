# Panduan Pengguna — Logbook

Selamat datang di **Logbook**, aplikasi pencatatan dan monitoring bimbingan Tugas Akhir (TA) mahasiswa. Panduan ini menjelaskan seluruh fitur aplikasi serta alur kerja (mekanisme) untuk setiap peran pengguna.

---

## Daftar Isi

1. [Tentang Aplikasi](#1-tentang-aplikasi)
2. [Peran Pengguna](#2-peran-pengguna)
3. [Fitur Detail Keseluruhan](#3-fitur-detail-keseluruhan)
4. [Alur Kerja Per Peran](#4-alur-kerja-per-peran)
5. [Tanya Jawab (FAQ)](#5-tanya-jawab-faq)

---

## 1. Tentang Aplikasi

Aplikasi ini membantu dosen pembimbing/penguji dan mahasiswa memantau proses bimbingan Tugas Akhir secara digital. Beberapa hal utama yang didukung:

- **Pencatatan logbook bimbingan** — mahasiswa mencatat setiap sesi bimbingan beserta progres dan kendala.
- **Alur review** — dosen menyetujui (approve) atau meminta revisi atas entri logbook.
- **Revisi berkelanjutan** — perbaikan hasil review dicatat sebagai entri revisi tersendiri.
- **Workspace file** — mahasiswa menyimpan dan membagikan dokumen TA (Bab, revisi, dll) secara terpusat.
- **Chat & komunikasi** — percakapan langsung antara dosen dan mahasiswa.
- **Pengumuman** — pengumuman resmi ke seluruh mahasiswa.
- **Catatan sidang & riwayat menguji** — dosen mencatat hasil sidang/pengujian.
- **Pemberian bahan seminar/sidang** — mahasiswa mengirim undangan & materi seminar/sidang ke dosen.
- **Finalisasi TA/KP** — mahasiswa mengirim kelengkapan akhir (abstrak, cover, pengesahan, file lengkap) untuk persetujuan dosen.
- **Notifikasi & pelacakan kesehatan bimbingan** — indikator keteraturan bimbingan.

### Mode Aplikasi

Aplikasi menggunakan **satu deployment SaaS** — user personal dan user institusi hidup bersamaan:

| Jenis User | Deskripsi |
|---|---|
| 🌱 **User Personal** | Dosen daftar mandiri, data TA milik dosen. Kuota storage dari plan individual (free 5 GB / donasi 10 GB). |
| 🏛️ **User Institusi** | Dosen diadopsi ke institusi, data TA menjadi milik institusi. Kuota storage dari **shared pool institusi** (total semua langganan direktori) dengan batas per-user yang bisa diatur admin. |

Gate fitur dilakukan **per-user** berdasarkan `institution_id`, bukan mode global. User personal dan user institusi dapat hidup bersamaan dalam satu deployment.

---

## 2. Peran Pengguna

| Peran | Deskripsi |
|---|---|
| **System Admin** | Role tertinggi — mengelola akun admin lain (buat, reset password, hapus), pengaturan paket/plan, dan memiliki akses penuh ke semua menu admin. |
| **Admin** | Mengelola pengguna, data TA, review massal, data sidang, dan pengaturan institusi. Tidak dapat mengelola admin lain. |
| **Dosen** | Membimbing dan menguji mahasiswa, me-review logbook, mencatat sidang, menyetujui registrasi mahasiswa. |
| **Mahasiswa** | Mencatat logbook bimbingan, mengunggah revisi, mengelola workspace, dan memantau progres TA. |

---

## 3. Fitur Detail Keseluruhan

Berikut rincian fitur yang tersedia di aplikasi, terlepas dari peran (ketersediaan per peran dijelaskan di bagian 4).

### 3.1 Autentikasi

- **Login** — Masuk dengan email dan kata sandi.
- **Verifikasi email** — Setiap akun baru harus **memverifikasi email** sebelum dapat mengakses aplikasi sepenuhnya. Setelah mendaftar, sistem mengirim email verifikasi; pengguna diarahkan ke halaman verifikasi dan dapat mengirim ulang link jika perlu.
- **Registrasi mandiri mahasiswa** — Mahasiswa dapat mendaftar sendiri (nama, email, kata sandi). Setelah verifikasi email, akun berstatus **active** (belum terhubung dosen). Mahasiswa kemudian **memilih dosen** (pembimbing/penguji) melalui halaman **"Pilih Dosen"**; dosen yang dipilih akan menyetujui atau menolak permintaan attachment.
- **Status registrasi mahasiswa** — `active` (email terverifikasi, belum punya dosen), `verified` (sudah disetujui dosen), `rejected` (ditolak, tidak bisa login).
- **Pembersihan otomatis** — Mahasiswa berstatus `active` yang tidak memilih dosen dalam 1 bulan akan dihapus otomatis oleh sistem (terjadwal harian).
- **Lupa kata sandi** — Reset kata sandi melalui email. Pesan yang ditampilkan seragam untuk mencegah penebakan email terdaftar.
- **Keluar** — Tombol keluar tersedia di menu profil.

### 3.2 Dashboard

Setiap peran memiliki dashboard ringkas:

- **Admin**: statistik mahasiswa, dosen, data TA, dan entri menunggu review; import mahasiswa via Excel; daftar data TA terbaru.
- **Dosen**: statistik total bimbingan, sedang progres, tamat, diuji, dan menunggu review; antrean review; health indicator; manajemen fase TA.
- **Mahasiswa**: pengumuman belum dibaca, status kesehatan bimbingan, milestone journey (fase), judul & pembimbing TA, progres bimbingan, achievement, statistik & streak, heatmap aktivitas 12 bulan, dan timeline bimbingan.

> **🔒 Label "Admin" Tersembunyi** — Label peran "admin" dan "system_admin" **tidak pernah ditampilkan** di halaman profil siapa pun (profil sendiri maupun profil orang lain). Status administratif tetap bersifat pribadi.
>
> **🛡️ System Admin** — Role **System Admin** memiliki akses penuh ke semua menu admin **plus** menu khusus **"Kelola Admin"** (membuat, reset password, dan menghapus akun admin) serta pengaturan **Paket/Plan**. Role **Admin** biasa tidak dapat mengelola admin lain.
>
> **🔗 Admin & Dosen Terpisah** — Akun admin dan dosen **dipisah** (tidak bisa satu akun memegang kedua role). Login sebagai dosen untuk dashboard dosen, login sebagai admin untuk dashboard admin.

### 3.3 Logbook Bimbingan

Entri logbook mencatat satu sesi bimbingan.

- **Status entri**: `Draf` → `Dikirim` → `Disetujui` atau `Revisi`.
- **Jenis entri**: `Logbook` (sesi bimbingan) dan `Revisi` (perbaikan hasil review).
- **Penerima revisi**: entri revisi dapat ditujukan ke **dosen pembimbing** atau **dosen penguji** program. Penerima menjadi reviewer entri tersebut; pembimbing tetap menerima notifikasi (CC).
- **Batas sesi revisi**: maksimal 3 sesi per entri (peringatan muncul bila tercapai).
- **Auto-save draf**: isian logbook tersimpan otomatis di browser (localStorage) setiap 5 detik.
- **Filter**: status, jenis, rentang tanggal, dan kata kunci.

### 3.4 Review PDF & Anotasi

- Dokumen lampiran (PDF) dapat dibuka dalam **viewer PDF**.
- Dosen dapat **menandai area** pada PDF dan **memberi komentar** (anotasi) per halaman.
- **Dosen penguji** yang menerima revisi juga dapat membuka viewer dan memberi anotasi.
- Komentar memiliki status **terbuka/resolved**.
- Dosen dapat **membangun feedback otomatis** dari komentar yang belum di-resolve.

### 3.5 Workspace File

- Mahasiswa dapat **mengunggah file** (PDF, DOC, DOCX, XLS, XLSX) — maks. 25 MB, hingga 5 file sekaligus, dengan *drag & drop*.
- File diberi **label Bab** (opsional) dan **catatan** (deskripsi).
- **Filter** berdasarkan Bab, tipe file, dan kata kunci.
- **Preview** untuk PDF, **download** untuk semua file.
- Mahasiswa dapat **mengedit metadata** (Bab, catatan) dan **menghapus** file.
- Indikator **penggunaan penyimpanan** (MB) ditampilkan.

### 3.6 Chat

- Percakapan langsung antara dosen dan mahasiswa.
- Dosen dapat memulai percakapan dari **halaman detail mahasiswa**.
- Tampilan daftar percakapan menampilkan lawan bicara, jumlah pesan belum dibaca, dan TA terkait.
- Pesan dapat **dilampirkan** ke entitas terkait (opsi lampiran).

### 3.7 Pengumuman

- **Pembuat pengumuman** (dosen/admin) dapat membuat pengumuman yang dikirim ke mahasiswa.
- **Mahasiswa** melihat pengumuman belum dibaca di dashboard dan dapat **menandai dibaca**.
- Pembuat dapat melihat **laporan** (jumlah terkirim, sudah baca) dan **mengingatkan** yang belum membaca.

### 3.8 Notifikasi

- Notifikasi masuk untuk aktivitas penting (entri baru, status berubah, komentar PDF, dll).
- **Lonjong notifikasi** di header menampilkan jumlah belum dibaca; tersedia **dropdown** dan **halaman semua notifikasi**.
- **Notifikasi real-time** (via Reverb/Pusher) menampilkan toast saat ada perubahan status entri atau komentar PDF.
- **Notifikasi berkala** (dapat dimatikan system admin di panel **Pengaturan** → *Notifikasi Berkala*):
  - **Digest Mingguan** — setiap Senin 07:00 WIB, ringkasan bimbingan ke dosen & mahasiswa.
  - **Reminder Harian** — setiap 08:00 WIB, pengingat mahasiswa tidak aktif & antrean review dosen (termasuk pengingat inaktivitas > 3 minggu + CC pembimbing).

### 3.9 Catatan Sidang & Riwayat Menguji

- Dosen mencatat **sidang** (Seminar Proposal / Sidang Akhir) untuk mahasiswa bimbingan **atau mahasiswa lain** (di luar sistem).
- Data dicatat: mahasiswa, jenis, tanggal, hasil (Lulus / Lulus + Revisi / Mengulang), dan nama pembimbing (maks 3).
- **Export PDF** riwayat menguji untuk keperluan portofolio/BKD.
- Sidang Akhir dengan hasil **Lulus/Lulus + Revisi** otomatis menandai mahasiswa **tamat**.

### 3.10 Jadwal Bimbingan

- Halaman **"Jadwalkan Bimbingan"** menampilkan daftar dosen beserta tautan jadwal bimbingan mereka.
- Klik kartu dosen untuk membuka tautan jadwal (di tab baru).

### 3.11 Pencarian Global (Cmd+K)

- Pencarian cepat di seluruh data (mahasiswa/dosen, entri, file workspace).
- Tekan `Cmd+K` (Mac) atau `Ctrl+K` (Windows/Linux) atau klik kolom pencarian di header.

### 3.12 Profil & Pengaturan

- Setiap pengguna dapat memperbarui **profil** (foto, kontak WhatsApp/Telegram/LinkedIn, dll) dan **kata sandi**.
- Halaman profil pengguna lain dapat dilihat (dihubungkan dari berbagai halaman).

### 3.13 Kesehatan Bimbingan (Health Indicator)

Keteraturan bimbingan dihitung dari tanggal bimbingan terakhir:

| Status | Arti |
|---|---|
| 🟢 **Sehat** | Bimbingan terakhir < 15 hari |
| 🟡 **Perhatian** | 15–40 hari |
| 🔴 **Kritis** | > 40 hari atau belum pernah bimbingan |

- Dosen melihat indikator ini di dashboard (dengan filter).
- Mahasiswa melihat status kesehatan bimbingannya sendiri.
- Mahasiswa yang lama tidak bimbingan dapat menerima **email inaktivitas** (ditandai ikon ⚠).

### 3.14 Milestone Journey & Achievement

- **Milestone Journey** menampilkan fase perjalanan TA mahasiswa: Proposal → Pengumpulan Data → Analisis → Seminar Hasil → Draft Sidang → Sidang → Achievement Unlocked.
- **Achievement** (badge) yang terbuka saat mahasiswa mencapai pencapaian tertentu, ditampilkan di dashboard mahasiswa.

### 3.15 Import & Export

- **Import mahasiswa (Excel)** — admin (institusi) dapat mengimpor data mahasiswa secara massal (nama, NIM, email, pembimbing1_nidn, pembimbing2_nidn) dengan pembimbing default.
- **Export rekap** — rekap bimbingan dalam **PDF** dan **Excel** per mahasiswa.
- **Export riwayat menguji** — PDF untuk dosen.

### 3.16 Manajemen Fase & Status TA

- **Fase** TA dapat diperbarui oleh dosen (dari dashboard atau halaman detail mahasiswa).
- **Status TA**: `Aktif`, `Tamat`, `Nonaktif` — dikelola admin.

### 3.17 Pemberian Bahan Seminar/Sidang

Mahasiswa mengirim **bahan seminar/sidang** (undangan + materi) kepada dosen pembimbing/penguji:

- **Jenis otomatis** — jenis seminar (Seminar Proposal, Seminar Hasil, Sidang Akhir, Seminar KP) ditentukan otomatis dari fase TA/KP saat ini.
- **Data yang diisi**: tanggal, waktu, lokasi, surat undangan (file), pilihan "undangan sebagai" (Pembimbing 1/2 atau Penguji 1/2), dan materi.
- **Materi** dapat diunggah langsung **atau dipilih dari file workspace** (salah satu wajib diisi).
- **Catatan hardcopy** — dosen dapat menambahkan/mengubah catatan hardcopy pada submission.
- **Notifikasi** — dosen terkait (pembimbing & penguji) menerima notifikasi saat bahan dikirim.
- **Edit** — mahasiswa dapat mengubah submission selama belum dikonversi ke riwayat sidang.
- **Konversi ke riwayat sidang** — dosen dapat mengonversi submission menjadi catatan sidang (memilih penguji & hasil).

### 3.18 Finalisasi TA/KP

Mahasiswa mengirim **kelengkapan akhir** TA/KP untuk persetujuan dosen pembimbing:

- **Item TA**: Abstrak, Kata Kunci, Cover, Lembar Pengesahan, dan File Lengkap (PDF).
- **Item KP**: File Lengkap (PDF) saja.
- **Alur persetujuan** — setiap item harus **disetujui oleh kedua pembimbing** (Pembimbing 1 & 2) sebelum dianggap final.
- **Penolakan & buka kembali** — dosen dapat menolak item (status menjadi `rejected`) atau membuka kembali item yang sudah disetujui.
- **Input nilai** — dosen dapat mengisi nilai akhir (0–100).
- **Milestone otomatis** — jika semua item disetujui dan fase TA adalah `sidang`, fase otomatis maju ke **Achievement Unlocked**.

### 3.19 Direktori Organisasi (Perguruan Tinggi → Fakultas → Departemen → Prodi)

Aplikasi memiliki **direktori organisasi hierarkis** untuk memetakan afiliasi pengguna:

```
Perguruan Tinggi (universities)
  └── Fakultas (faculties)
        └── Departemen (departments)
              └── Program Studi (study_programs)
```

- **Deduplikasi otomatis** — nama perguruan tinggi tidak muncul dua kali; fakultas/departemen/prodi unik di dalam induknya (constraint unik).
- **Registrasi dosen** — dosen mendaftar dengan **NIDN** dan memilih/membuat perguruan tinggi (jika sudah ada, langsung dipakai; jika belum, dibuat baru).
- **Multi-universitas** — satu dosen dapat terhubung ke **banyak perguruan tinggi**.
- **Mahasiswa otomatis mengikuti institusi dosen** — saat dosen meng-invite atau menyetujui mahasiswa, universitas dosen otomatis disalin ke mahasiswa (mahasiswa tidak perlu input ulang).
- **Tampilan** — universitas ditampilkan di dashboard (dosen & mahasiswa), sidebar, dan halaman profil.

### 3.20 Grup & Cross-link Dosen

Dosen dapat membentuk **grup** untuk kolaborasi dan cross-link dengan dosen lain di universitas yang sama.

- **Level grup**: Universitas, Fakultas, Departemen, atau Program Studi.
- **Buat grup**: dosen membuat grup dan otomatis menjadi owner.
- **Undang kolega**: dosen mengundang dosen lain dari universitas yang sama (data tidak diinput ulang — langsung dipilih dari direktori).
- **Approval**: dosen yang diundang harus **menyetujui** (approve) undangan sebelum menjadi anggota.
- **Undangan pending**: dosen melihat undangan yang menunggu di halaman **Grup Dosen** dan dapat menerima/menolak.
- **Akses "hanya hubungan langsung"**: dosen dalam grup yang sama (atau TA bersama) dapat melihat data bimbingan rekan — data hanya bisa diakses jika ada hubungan langsung.

### 3.21 Workspace Dosen

Selain workspace mahasiswa, dosen juga memiliki **workspace pribadi** melalui menu **Workspace Saya**:

- Unggah file pribadi (PDF, DOC, DOCX, XLS, XLSX) — maks. 25 MB, hingga 5 file sekaligus.
- Kelola file dengan label Bab dan catatan.
- Filter & pencarian file.
- Hanya dosen yang bersangkutan yang dapat mengakses file workspace pribadinya.

### 3.22 Dashboard & UI (Institusi & Grup)

- **Dashboard dosen** menampilkan kartu **"Institusi & Grup"**: perguruan tinggi (NPSN), NIDN, dan jumlah grup yang diikuti.
- **Dashboard mahasiswa** menampilkan kartu **"Universitas"**.
- **Sidebar** menampilkan badge universitas utama pengguna.
- **Profil** menampilkan NIDN (dosen) dan universitas.

---

## 4. Alur Kerja Per Peran

### 4.1 Alur Kerja Mahasiswa

#### 4.1.1 Registrasi & Memilih Dosen

1. Buka halaman **Daftar**.
2. Isi **nama**, **email**, dan **kata sandi**.
3. Submit → sistem mengirim **email verifikasi**. Buka email dan klik link verifikasi.
4. Setelah verifikasi, Anda dapat login. Akun berstatus **active** (belum terhubung dosen).
5. Di dashboard, klik banner **"Pilih Dosen untuk Memulai Program"** (atau menu **Pilih Dosen**).
6. Pilih **jenis program** (TA/KP), **fase/milestone saat ini**, **Pembimbing 1** (wajib), serta **Pembimbing 2**, **Penguji 1/2** (opsional) → klik **Kirim Permintaan**.
7. Dosen yang dipilih akan **menyetujui** atau **menolak** permintaan. Setelah disetujui, program Anda **aktif** dan data TA tersedia.
   - Jika ditolak, Anda dapat memilih dosen lain.
   - Jika Anda tidak memilih dosen dalam 1 bulan, akun dapat dihapus otomatis oleh sistem.

#### 4.1.2 Mencatat Logbook Bimbingan

1. Masuk ke **Dashboard** → klik **+ Logbook** (atau menu **Tambah Logbook**).
2. Nomor **Sesi** terisi otomatis (sesi berikutnya).
3. Isi **Tanggal Bimbingan**, **Topik Bimbingan**, dan **Ringkasan Perbaikan** (progres & kendala).
4. (Opsional) Lampirkan file (PDF, dll).
5. Pilih:
   - **Simpan Draf** — menyimpan sebagai draf, dapat diedit dan dikirim nanti.
   - **Kirim ke dosen** — langsung mengirim ke dosen untuk direview.
6. Isian tersimpan otomatis (auto-save) setiap 5 detik; draf dipulihkan jika halaman tertutup.

#### 4.1.3 Menanggapi Review Dosen

1. Saat dosen meminta revisi, status entri menjadi **Revisi**.
2. Buka detail entri → klik **Buat Revisi dari Feedback Ini**.
3. Isi tabel **catatan perbaikan** (halaman, komentar dosen, perbaikan yang dilakukan, status) dan unggah **file perbaikan**.
4. Pada langkah pertama wizard, pilih **Kirim kepada**: dosen **pembimbing** atau dosen **penguji** (default: pembimbing 1 / penerima entri induk).
5. Kirim → penerima menerima notifikasi (pembimbing ikut diberi tahu) dan mereview kembali.

#### 4.1.4 Mengelola Workspace

1. Buka menu **Workspace**.
2. Klik zona unggah (atau tarik & lepas file) — maks. 5 file, 25 MB per file.
3. Isi label **Bab** (opsional) dan **Catatan**.
4. Klik **Upload** → file muncul di daftar, dikelompokkan per Bab.
5. Gunakan **filter** (Bab, tipe, kata kunci) atau **search** untuk menemukan file.
6. Klik ikon **edit** untuk mengubah metadata, atau **hapus** untuk menghapus file.

#### 4.1.5 Berkomunikasi & Memantau

- **Chat** dengan dosen pembimbing Anda.
- Baca **pengumuman** dan **notifikasi** secara berkala.
- Pantau **milestone journey**, **progres bimbingan**, dan **health indicator** Anda di dashboard.
- Unduh **rekap PDF/Excel** bimbingan Anda.

#### 4.1.6 (Jika Menjadi Penguji) Mencatat Sidang

Jika akun Anda diizinkan menjadi penguji, Anda dapat mencatat riwayat sidang/menguji mahasiswa lain melalui menu **Catat Sidang** (lihat alur dosen di 4.2.5).

#### 4.1.7 Mengirim Bahan Seminar/Sidang

1. Buka halaman detail TA/KP Anda → klik **Kirim Bahan Seminar/Sidang** (atau dari dashboard).
2. Jenis seminar (Seminar Proposal / Seminar Hasil / Sidang Akhir / Seminar KP) terisi otomatis dari fase Anda.
3. Isi **Tanggal**, **Waktu**, dan **Lokasi** seminar/sidang.
4. Unggah **Surat Undangan** (file) dan pilih **"Undangan sebagai"** (Pembimbing 1/2 atau Penguji 1/2).
5. Pilih **Materi**: unggah file baru **atau** ambil dari **Workspace** (salah satu wajib).
6. (Opsional) Tambahkan **Catatan Keterangan**.
7. Klik **Kirim** → dosen terkait menerima notifikasi.
8. Anda dapat **mengedit** submission selama belum dikonversi menjadi riwayat sidang oleh dosen.

#### 4.1.8 Finalisasi TA/KP

1. Buka menu **Finalisasi** pada halaman detail TA/KP Anda.
2. Isi **Abstrak** dan **Kata Kunci** (khusus TA).
3. Unggah **Cover** dan **Lembar Pengesahan** (PDF, khusus TA).
4. Unggah **File Lengkap** (PDF) — wajib untuk TA dan KP.
5. Klik **Kirim untuk Persetujuan** → setiap item dikirim ke kedua pembimbing.
6. Pantau status persetujuan: `pending` → `approved` (jika kedua pembimbing menyetujui) atau `rejected` (jika ada yang menolak).
7. Jika ada item ditolak, perbaiki dan kirim ulang.

---

### 4.2 Alur Kerja Dosen

#### 4.2.1 Menyetujui Permintaan Attachment Dosen

1. Buka menu **Persetujuan** (di sidebar) — halaman **"Persetujuan Attachment Dosen"**.
2. Lihat daftar mahasiswa yang **memilih Anda** sebagai pembimbing/penguji (status **Menunggu**).
3. Untuk menyetujui, isi:
   - **Judul TA** (atau **Tempat KP** untuk program KP)
   - **Peran Anda** untuk mahasiswa tersebut (Pembimbing 1 / Pembimbing 2 / Penguji 1 / Penguji 2)
   - **Target Sesi**
4. Klik **Setujui & Assign** → program menjadi **aktif** dan mahasiswa berstatus **verified**.
5. Alternatif, klik **Tolak** untuk menolak permintaan — mahasiswa dapat memilih dosen lain.
6. Anda juga dapat **menambah mahasiswa manual** (input email) — mahasiswa perlu verifikasi email & memilih dosen.

#### 4.2.2 Me-review Logbook

1. Di **Dashboard**, lihat **Antrean Review** (jumlah entri menunggu review).
2. Klik **Review** pada entri, atau gunakan menu **Quick Review** untuk meninjau satu per satu.
3. Pada entri:
   - Buka **PDF & Anotasi** untuk membaca dokumen dan memberi komentar pada area PDF.
   - Pilih **Setujui (Approve)** untuk menyetujui, atau
   - Isi **feedback** (wajib, min. 20 karakter) lalu **Minta Revisi**.
4. Saat menyetujui dengan lampiran PDF yang belum dibuka, aplikasi mengonfirmasi terlebih dahulu.
5. Bila Anda **dosen penguji** yang dipilih mahasiswa sebagai penerima revisi, entri tersebut juga tampil di **Antrean Review** Anda; Anda dapat menyetujui atau meminta revisi seperti pembimbing.

#### 4.2.3 Quick Review

Mode cepat untuk meninjau antrean satu per satu:

1. Buka menu **Quick Review**.
2. Baca ringkasan entri, feedback sebelumnya, dan anotasi sesi sebelumnya.
3. Gunakan **feedback terakhir** untuk mahasiswa tersebut (klik untuk memakai) atau **template feedback**.
4. Klik **"Jadikan dari Komentar"** untuk membangun feedback dari komentar PDF yang belum di-resolve.
5. Pilih **Setujui & Next** atau **Revisi & Next** untuk lanjut ke entri berikutnya.
6. Simpan feedback sebagai **template** untuk dipakai ulang.

#### 4.2.4 Mengelola Fase & Health Bimbingan

1. Di dashboard, gunakan **Health Indicator Bimbingan** untuk memantau keteraturan bimbingan tiap mahasiswa (filter Sehat/Perhatian/Kritis).
2. Gunakan **Manajemen Fase TA** untuk memperbarui fase tiap mahasiswa (mis. dari Proposal ke Pengumpulan Data).
3. Mahasiswa yang tidak aktif bimbingan dapat ditandai (⚠) dan menerima email inaktivitas.

#### 4.2.5 Mencatat Sidang / Riwayat Menguji

1. Buka menu **Catat Sidang**.
2. Pilih mahasiswa dari daftar bimbingan, atau ketik **nama mahasiswa di luar sistem**.
3. Isi **Jenis** (Seminar Proposal / Sidang Akhir), **Tanggal**, dan **Hasil** (Lulus / Lulus + Revisi / Mengulang).
4. (Opsional) Isi nama pembimbing mahasiswa yang diuji (maks 3).
5. Klik **Simpan Sidang** → tercatat di riwayat menguji.
6. Gunakan **Export PDF** untuk mengunduh riwayat menguji.

#### 4.2.6 Mereview Bahan Seminar/Sidang

1. Saat mahasiswa mengirim bahan seminar/sidang, Anda menerima **notifikasi**.
2. Buka detail submission (dari notifikasi, dashboard, atau halaman mahasiswa).
3. **Unduh Surat Undangan** dan **Materi** untuk diperiksa.
4. (Opsional) Perbarui **Catatan Hardcopy** pada submission.
5. Jika sudah sesuai, Anda dapat **Konversi ke Riwayat Sidang**: pilih **Penguji** dan **Hasil** (Lulus / Lulus + Revisi / Mengulang).
6. Submission yang sudah dikonversi tidak dapat diubah lagi oleh mahasiswa.

#### 4.2.7 Menyetujui Finalisasi TA/KP

1. Buka menu **Review Finalisasi** (di sidebar) untuk melihat daftar finalisasi mahasiswa bimbingan Anda.
2. Periksa setiap item (Abstrak, Kata Kunci, Cover, Pengesahan, File Lengkap).
3. Klik **Setujui** atau **Tolak** per item.
4. Item dianggap **final** hanya jika **kedua pembimbing** menyetujui.
5. (Opsional) Isi **Nilai** akhir (0–100).
6. Jika semua item disetujui dan fase TA adalah `sidang`, fase otomatis maju ke **Achievement Unlocked**.

#### 4.2.8 Berkomunikasi & Mengumumkan

- **Chat** dengan mahasiswa bimbingan (mulai dari halaman detail mahasiswa).
- Buat **pengumuman** dan pantau laporan pembacaannya; kirim pengingat ke yang belum membaca.
- Pantau **notifikasi** untuk entri baru dan komentar PDF.

#### 4.2.9 Deret Utama Alur Bimbingan (Ringkasan)

```
Mahasiswa mendaftar → Dosen menyetujui & assign
   → Mahasiswa mencatat logbook (draf)
   → Mahasiswa kirim ke dosen (submitted)
   → Dosen review: approve ATAU minta revisi
   → Jika revisi: Mahasiswa buat entri revisi → kirim lagi → dosen review
   → Setiap approve menambah sesi disetujui
   → Dosen monitor health & fase → hingga tamat
```

---

### 4.3 Alur Kerja System Admin

#### 4.3.1 Mengelola Akun Admin

1. Buka menu **Kelola Admin** (hanya tampil untuk System Admin).
2. Lihat daftar semua akun admin operasional.
3. **Tambah admin**: isi nama, email, identifier (opsional), dan kata sandi → klik **Simpan**.
4. **Reset password** admin: klik **Reset PW** → isi kata sandi baru.
5. **Hapus** akun admin: klik **Hapus** (dengan konfirmasi).
6. Proteksi: Anda **tidak dapat menghapus akun sendiri** atau akun System Admin lain.

#### 4.3.2 Mengelola Paket/Plan

1. Buka menu **Pengguna** → klik **Paket** pada user yang ingin diatur.
2. Pilih paket (Gratis / Donasi).
3. Atur override: izin export, izin import, dan batas penyimpanan (MB).
4. Klik **Simpan** → paket user diperbarui.

#### 4.3.3 Akses Penuh Menu Admin

- System Admin memiliki akses ke **semua** menu admin: Pengguna, Persetujuan Dosen, Data TA, Review Massal, Sidang, dan Institusi.
- System Admin juga dapat membuat user dengan role **admin** dari halaman **Pengguna** (checkbox "Admin" hanya muncul untuk System Admin).

---

### 4.4 Alur Kerja Admin

#### 4.4.1 Mengelola Pengguna

1. Buka menu **Pengguna**.
2. **Cari/filter** pengguna berdasarkan nama, email, identifier, atau role.
3. **Tambah pengguna**: isi nama, email, identifier (NIM/NIDN), kata sandi, dan pilih role (admin/dosen/mahasiswa).
4. **Reset kata sandi** pengguna (klik **Reset PW**).
5. **Hapus** pengguna bila diperlukan.

#### 4.4.2 Mengelola Data TA

1. Buka menu **Data TA**.
2. **Buat data TA**: pilih mahasiswa (tanpa TA), isi judul, dan tentukan pembimbing 1/2, penguji 1/2, serta target sesi.
3. **Edit & assign** data TA per mahasiswa (judul, pembimbing, penguji, target sesi, status).
4. **Aksi massal**: centang beberapa baris → pilih dosen → **Assign Pembimbing 1** secara massal.
5. **Import mahasiswa (Excel)** dari dashboard: unggah file (nama, NIM, email, pembimbing1_nidn, pembimbing2_nidn) dan pilih pembimbing default.

#### 4.4.3 Review Massal Entri

1. Buka menu **Review Massal**.
2. **Filter** entri berdasarkan status, jenis, dan kata kunci.
3. Centang entri yang dipilih.
4. Pilih aksi massal: **Setujui**, **Tandai Revisi**, atau **Hapus**.

#### 4.4.4 Mengelola Data Sidang

1. Buka menu **Sidang**.
2. **Tambah data sidang**: pilih mahasiswa, dosen penguji, jenis, tanggal, dan hasil.
3. **Hapus** data sidang bila diperlukan.
4. Catatan: Sidang Akhir dengan hasil **Lulus/Lulus + Revisi** otomatis menandai mahasiswa **tamat**.

#### 4.4.5 Mengelola Profil Institusi

1. Buka menu **Institusi**.
2. Isi informasi institusi: nama aplikasi, nama institusi, fakultas, prodi, alamat, kota, telepon, email, website, catatan kaki dokumen, dan logo.
3. Atur **pengaturan upload & template**: link template catatan perbaikan, maks ukuran upload (MB), dan jenis file yang diizinkan.
4. Atur **pengaturan email (SMTP)**: mailer, host, port, enkripsi, username, password, dari-alamat, dan dari-nama.
5. **Kirim email uji** untuk memverifikasi konfigurasi SMTP.

#### 4.4.6 Memantau Dashboard

- Dashboard admin menampilkan statistik **mahasiswa, dosen, data TA**, dan **menunggu review**.
- Daftar **data TA terbaru** ditampilkan untuk pemantauan cepat.

---

## 5. Tanya Jawab (FAQ)

**Q: Apa arti error "419 Page Expired"?**
A: Error ini terjadi karena token keamanan (CSRF) sesi tidak valid atau kedaluwarsa. Solusi: muat ulang halaman login, dan pastikan halaman tidak dibiarkan terbuka terlalu lama. Jika terus terjadi, hubungi administrator untuk memeriksa konfigurasi sesi.

**Q: Saya mahasiswa, tetapi tidak bisa login setelah mendaftar.**
A: Pastikan Anda sudah **memverifikasi email** (klik link di email yang dikirim setelah mendaftar). Jika akun berstatus **rejected**, hubungi admin. Jika berstatus **active**, Anda sudah bisa login — selanjutnya pilih dosen melalui menu **"Pilih Dosen"** di dashboard.

**Q: Bagaimana cara mengubah atau mengirim draf logbook?**
A: Buka entri draf dari halaman **Logbook**, klik **Edit** untuk mengubah, lalu **Kirim ke dosen** untuk mengirim.

**Q: Saya dosen, bagaimana cara memberi komentar pada PDF?**
A: Buka entri (status **Dikirim**) → klik **Review PDF & Beri Anotasi** → buka PDF, tandai area, dan beri komentar. Komentar dapat dijadikan feedback otomatis.

**Q: Berapa batas ukuran file di workspace?**
A: Maksimal 25 MB per file, hingga 5 file sekaligus, dengan format PDF, DOC, DOCX, XLS, XLSX.

**Q: Bagaimana mahasiswa ditandai "tamat"?**
A: Otomatis saat dicatat **Sidang Akhir** dengan hasil **Lulus** atau **Lulus + Revisi**, atau diatur manual oleh admin.

**Q: Apa itu health indicator?**
A: Indikator keteraturan bimbingan: 🟢 Sehat (<15 hari), 🟡 Perhatian (15–40 hari), 🔴 Kritis (>40 hari). Membantu dosen dan mahasiswa memantau konsistensi bimbingan.

**Q: Saya dosen sekaligus admin, bagaimana cara berpindah dashboard?**
A: Akun admin dan dosen **dipisah** — tidak bisa satu akun memegang kedua role. Login sebagai dosen untuk dashboard dosen, login sebagai admin untuk dashboard admin.

**Q: Mengapa label "admin" tidak muncul di profil saya?**
A: Label peran "admin" sengaja disembunyikan dari semua halaman profil agar status administratif tetap pribadi. Label "dosen" dan "mahasiswa" tetap ditampilkan.

**Q: Bagaimana cara mengirim bahan seminar/sidang?**
A: Buka halaman detail TA/KP → klik **Kirim Bahan Seminar/Sidang** → isi tanggal, waktu, lokasi, unggah surat undangan, pilih materi (upload atau dari workspace), lalu kirim. Dosen terkait akan menerima notifikasi.

**Q: Bagaimana cara menyetujui finalisasi TA/KP?**
A: Buka menu **Review Finalisasi** → periksa setiap item → klik **Setujui** atau **Tolak**. Item dianggap final jika **kedua pembimbing** menyetujui.

**Q: Apa perbedaan System Admin dan Admin?**
A: **System Admin** adalah role tertinggi yang dapat mengelola akun admin lain (buat, reset password, hapus) dan pengaturan paket/plan, serta memiliki akses penuh ke semua menu admin. **Admin** mengelola data akademik (pengguna, TA, sidang, review) tetapi tidak dapat mengelola admin lain.

**Q: Bagaimana cara membuat akun admin baru?**
A: Login sebagai **System Admin** → buka menu **Kelola Admin** → isi form **Tambah Admin** → klik **Simpan**. System Admin juga dapat membuat user dengan role admin dari halaman **Pengguna**.

**Q: Mengapa saya tidak bisa membuat akun admin?**
A: Hanya **System Admin** yang dapat membuat akun dengan role admin. Jika Anda login sebagai admin biasa, opsi role "Admin" tidak akan muncul di form tambah pengguna.

---

## Catatan

- Gunakan **Cmd+K / Ctrl+K** untuk pencarian global.
- Gunakan **tombol mode gelap/terang** di header untuk mengubah tampilan.
- Sidebar dapat diciutkan/lebarkan dengan tombol di sisi kiri.
- Untuk laporan masalah atau ide pengembangan, gunakan menu **"Kirim Masukan"**.