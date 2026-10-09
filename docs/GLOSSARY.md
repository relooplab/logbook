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
| **Umpan Balik** | `feedback` | Respon/feedback dosen atas entri | Pilih satu: **Umpan Balik** (atau pertahankan “Feedback”). Bedakan dari **Komentar**. |
| **Komentar** | `PdfComment` | Anotasi PDF | Konsep berbeda dari Umpan Balik; jangan disatukan. |
| **Fase / Tahapan** | `fase` | Tahap bimbingan (proposal, sidang, dst.) | Judul UI → **Perjalanan Fase** / **Tahapan**. Ganti “Milestone Journey”. |
| **Pencapaian** | `Achievement` | Badge/pencapaian mahasiswa | Pilih satu: **Pencapaian**. Hindari campur “Badge”. |
| **Anggota** | `member` | Keanggotaan grup/kelompok | Pakai **Anggota** (Indonesia). |
| **Grup** | `group` | **Grup Dosen** | Jangan disamakan dengan Kelompok KP. |
| **Kelompok** | (KP) | **Kelompok KP** mahasiswa | Konsep berbeda dari Grup Dosen. |
| **Pengumuman** | `announcement` | Blast pengumuman | Pakai **Pengumuman**. |
| **Antrean** | `queue` / “Antrean Review” | Daftar menunggu review | Pakai **Antrean**. |
| **Entri** | `LogbookEntry` | Entri logbook (Entri #N) | Bedakan dari **Catatan**. |
| **Catatan** | — | Catatan perbaikan & logbook harian KP | Bedakan dari **Entri**. |
| **Revisi** | `revisi` (jenis) | Proses/entri revisi | Untuk entri & permintaan revisi. |
| **Catatan Perbaikan** | `catatan_perbaikan` | Dokumen PDF perbaikan (auto) | Konsep berbeda dari Revisi (jangan digabung). |
| **Sesi** | `sesi_ke` | Nomor sesi bimbingan | Konsisten. |
| **Seminar** | `seminar_*` | Seminar proposal/hasil/SKP | Berbeda dari Sidang. |
| **Sidang** | `sidang` | Sidang akhir | Berbeda dari Seminar. |
| **Mahasiswa** | `mahasiswa` | Akun mahasiswa | Hindari “Mhs”/“Siswa”. |
| **Dosen** | `dosen` | Akun dosen | Role. |
| **Permintaan Bimbingan** | `attachment` | Mahasiswa memilih dosen | Ganti “Attachment” di label UI. Label: **Memilih Dosen**. |
| **Masuk** | `login` | Login | Pakai **Masuk**. |
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
- **Revisi** (proses/entri) vs **Catatan Perbaikan** (dokumen).
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
- Utamakan **kata baku** di atas pada: label tombol, judul halaman, judul kartu, notifikasi, menu/sidebar, pesan sukses/error.
- Saat menambahkan fitur baru, periksa kamus ini dulu untuk memakai istilah yang sama.
