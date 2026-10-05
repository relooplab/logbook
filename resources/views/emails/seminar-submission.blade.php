<x-mail::message>
# Halo {{ $penerima }}

@if (($isUpdate ?? false) && ($role ?? 'dosen') === 'mahasiswa')
Anda memperbarui bahan **{{ $jenisLabel }}**. Berikut salinan ringkas terbaru untuk acuan Anda.
@elseif (($isUpdate ?? false))
Mahasiswa **{{ $namaMahasiswa }}** memperbarui bahan **{{ $jenisLabel }}**. Mohon periksa kembali detail terbaru di bawah sebelum jadwal dimulai.
@elseif (($role ?? 'dosen') === 'mahasiswa')
Anda telah mengirim bahan **{{ $jenisLabel }}**. Berikut salinan ringkas untuk acuan Anda.
@else
Mahasiswa **{{ $namaMahasiswa }}** telah mengirim bahan **{{ $jenisLabel }}** beserta dokumen pendukungnya. Mohon untuk direview sebelum jadwal dimulai.
@endif

@if (($isUpdate ?? false) && !empty($changedFields ?? []))
> **Yang berubah:** {{ implode(', ', $changedFields) }}
@endif

<x-mail::panel>
**Detail Jadwal**

- **Jenis:** {{ $jenisLabel }}
- **Mahasiswa:** {{ $namaMahasiswa }}
- **Tanggal:** {{ $tanggal }}
- **Waktu:** {{ $waktuMulai }} – {{ $waktuSelesai }} ({{ $durasiMenit }} menit)
- **Lokasi:** {{ $lokasi }}
@if (!empty($meetingLink ?? null))
- **Video Conference:** {{ $meetingLink }}
@endif
- **Diundang:** {{ $diundang }}
@if ($catatan)
- **Catatan:** {{ $catatan }}
@endif
</x-mail::panel>

Dokumen dapat dibuka langsung di browser tanpa harus diunduh:

<x-mail::button :url="$urlUndangan">
👁 Buka Surat Undangan di Browser
</x-mail::button>

@if ($urlMateri)
<x-mail::button :url="$urlMateri">
👁 Buka Materi di Browser
</x-mail::button>
@endif

<x-mail::button :url="$urlDetail">
Lihat Detail Bahan
</x-mail::button>

Lampiran **.ics** memuat jadwal di atas — klik untuk menambahkannya ke kalender Anda.

<x-slot:subcopy>
Jika tombol di atas tidak berfungsi, salin tautan berikut ke browser Anda:

**Surat Undangan ({{ $namaFileUndangan }}):** <span class="break-all">{{ $urlUndangan }}</span>

@if ($urlMateri)
**Materi ({{ $namaFileMateri }}):** <span class="break-all">{{ $urlMateri }}</span>
@endif

**Detail Bahan:** <span class="break-all">{{ $urlDetail }}</span>
</x-slot:subcopy>
</x-mail::message>