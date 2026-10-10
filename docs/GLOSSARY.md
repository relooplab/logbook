# Glossary — Istilah Baku Aplikasi

Dokumen ini menstandarkan istilah yang dipakai di UI (Indonesia) dan memetakannya ke
istilah teknis/enumerasi di kode (sering Inggris). Tujuannya: **satu konsep = satu istilah**, tanpa varian bermakna sama.

## Aturan Singkat
- **UI = Bahasa Indonesia** (kecuali nama resmi/modul yang dibiarkan Inggris).
- Satu konsep hanya memakai **satu istilah** (lihat peta di bawah).
- Nama **role/permission/route/model** di kode tetap Inggris dan TIDAK diubah; labelingnya saja yang distandarkan.

---

## Peta Istilah Baku

| Istilah baku (UI) | EN / kode | Dipakai untuk | Catatan & yang dihindari |
|---|---|---|---|
| **Dashboard** | `dashboard` | Halaman utama pengguna (sesuai role) | Jangan campur dengan **Beranda** (halaman depan publik). Sudah konsisten di seluruh UI. |
| **Beranda** | `/` (root) | Halaman depan publik untuk guest | Beda konsep dari Dashboard. Tombol error: **Ke Dashboard** + **Ke Beranda**. |
| **Universitas / Perguruan Tinggi** | `university` | Node direktori (universitas/fakultas/departemen/prodi) | **YANG DIPAKAI** untuk konteks direktori. Hindari “Instansi” untuk ini. |
| **Institusi** | `institution` | Konfigurasi aplikasi (brand, mail), “Workspace Institusi” | Bedakan dari Universitas. |
| **Workspace** | `workspace` | Keluarga modul file | Pecah: **Workspace Pribadi** (dosen), **Workspace Mahasiswa** (program), **Workspace Institusi**. Ganti “Penyimpanan Saya”. |
| **Penjaga** **Persetujuan** | `approval` | Permintaan mahasiswa→dosen & persetujuannya | Halaman = **Persetujuan**; tombol = **Setujui**; status = **Disetujui**. Hindari “Approve/Approval”. |
| **Umpan Balik** | `feedback` | Pesan dosen untuk mahasiswa | Di UI tulis **Pesan Dosen**. Bedakan dari **Komentar**. |
| **Komentar** | `PdfComment` | Yang ditandai di PDF | Di UI tulis **Komentar**. Bedakan dari Pesan Dosen. |
| **Fase / Tahapan** | `fase` | Tahap bimbingan (proposal, sidang, dst.) | Judul UI → **Perjalanan Fase** / **Tahapan**. Ganti “Milestone Journey”. |
| **Pencapaian** | `Achievement` | Badge/pencapaian mahasiswa | Pilih satu: **Pencapaian**. Hindari campur “Badge”. |
| **Anggota** | `member` | Keanggotaan grup/kelompok | Pakai **Anggota** (Indonesia). |
| **Grup** | `group` | **Grup Dosen** | Jangan disamakan dengan Kelompok KP. |
| **Kelompok** | (KP) | **Kelompok KP** mahasiswa | Konsep berbeda dari Grup Dosen. |
| **Pengumuman** | `announcement` | Blast pengumuman | Pakai **Pengumuman**. |
| **Antrean** | `queue` / “Antrean Review” | Daftar menunggu review | Pakai **Antrean**. |
| **Entri** | `LogbookEntry` | Satu kiriman (sesi / jawaban) | Bedakan dari **Catatan**. |
| **Catatan** | — | Logbook harian KP | Bedakan dari **Entri**. |
| **Revisi** | `revisi` (jenis) | Jawaban atas sesi | Di UI tombol tulis **Jawab Revisi**. |
| **Jawaban** | `riwayat_perbaikan` | Isi jawaban (komentar + yang dibetulkan) | Satu jawaban = satu baris. |
| **Utas** | rantai `parent_entry_id` | Satu sesi + seluruh jawabannya | Badge dihitung per-utas, bukan per-baris. |
| **Sesi** | `sesi_ke` | Nomor bimbingan | Konsisten. |
| **Seminar** | `seminar_*` | Seminar proposal/hasil/SKP | Berbeda dari Sidang. |
| **Sidang** | `sidang` | Sidang akhir | Berbeda dari Seminar. |
| **Mahasiswa** | `mahasiswa` | Akun mahasiswa | Hindari “Mhs”/“Siswa”. |
| **Dosen** | `dosen` | Akun dosen | Role. |
| **Permintaan Bimbingan** | `attachment` | Mahasiswa memilih dosen | Ganti “Attachment” di label UI. Label: **Memilih Dosen**. |
| **Masuk** | `login` | Login | Pakai **Masuk**. |
| **Draf Browser** | `localStorage` | Ketikan di perangkat ini saja (tanpa file) | Hilang bila ganti HP/browser. |
| **Draf Server** | `draft` | Tersimpan di akun + file | Lanjut di HP lain. Kirim kapan saja dari daftar. |
| **Daftar** | `register` | Registrasi akun | Pakai **Daftar**. |

---

## Status — Label Baku

### Status program (`mahasiswa_ta.status_ta`)
| Kode | UI |
|---|---|
| `pending_approval` | Menunggu Persetujuan |
| `aktif` | Aktif |
| `tamat` | Selesai |
| `ditolak` | Ditolak |

### Status mahasiswa (`users.registration_status`)
| Kode | UI |
|---|---|
| `active` | Aktif |
| `verified` | Terverifikasi / Disetujui |

### Status entri logbook (`logbook_entries.status`)
| Kode | UI |
|---|---|
| `draft` | Draf |
| `submitted` | Menunggu Review |
| `approved` | Disetujui |
| `revisi` | Revisi Diminta |
| `revision_in_progress` | Revisi Sedang Dikerjakan |

### Status afiliasi (`user_university.status`)
| Kode | UI |
|---|---|
| `active` | Aktif |
| `pending` | Menunggu Persetujuan Admin |
| `revoked` | Dicabut |

---

## Istilah yang Tampak Ganda tapi SEBENARNYA Berbeda
Jangan disatukan — pertegas konteks:
- **Revisi** (jawaban atas sesi) vs **Jawaban** (isi jawaban per baris).
- **Pesan Dosen** (keputusan tertulis) vs **Komentar** (yang ditandai di PDF).
- **Grup** (dosen) vs **Kelompok** (KP).
- **Seminar** vs **Sidang**.
- **Entri** (logbook) vs **Catatan** (harian/perbaikan).
- **Universitas** (direktori) vs **Institusi** (konfigurasi app).
- **Dashboard** (login) vs **Beranda** (publik).

---

## Peta Sidebar / Menu
| Menu (UI) | Route |
|---|---|
| Dashboard | `dashboard` |
| Chat | `chat.*` |
| Pengumuman | `announcements.*` |
| Antrean Review | `logbook.index` |
| Quick Review | `quick-review.*` |
| Workspace (mahasiswa/pribadi) | `workspace.role`, `workspace.index` |
| Workspace Mahasiswa (penyimpanan dosen) | `storage.index` |
| Workspace Institusi | `workspace-institusi.*` |
| Grup Dosen | `groups.*` |
| Catat Sidang | `dosen-sidang.*` |
| Persetujuan | `approval.*` |

---

## Paket Naming (inti)
- Tombol maks. **3 kata**. Banner maks. **2 baris** (fakta + aksi).
- Kata yang tidak dipakai di UI: anotasi, otomatis, thread, induk, yatim, pending, kartu, kompilasi, prefill. Ganti: tandai, salin, jawaban, sesi, menunggu, dipilih.
- Tidak ada tooltip yang mengulang label. Tooltip hanya untuk ikon tanpa teks.
- Satu aksi = satu nama di semua layar: **Jawab Revisi** (menjawab), **Setujui / Minta Revisi / Arsipkan** (menilai), **Pesan Dosen** (keputusan tertulis), **Komentar** (yang ditandai di PDF).
- Tawarkan jalan benar dulu, jalan lain kecil di bawah. Tanpa kalimat larangan.
