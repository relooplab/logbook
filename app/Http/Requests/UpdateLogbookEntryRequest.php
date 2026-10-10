<?php

namespace App\Http\Requests;

use App\Models\Institution;
use App\Models\LogbookEntry;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLogbookEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validasi saat mengedit entri logbook (hanya untuk status draft/revisi).
     * Ukuran & jenis file upload diatur admin (institution settings).
     */
    public function rules(): array
    {
        $isRevisi = $this->route('logbook')?->jenis === 'revisi';

        $inst = Institution::current();
        $maxKb = $inst->maxUploadSizeMb() * 1024;
        $mimes = implode(',', $inst->allowedFileTypes());

        $rules = ['progres_kendala' => ['nullable', 'string', 'max:500']];

        // Alur anotasi-dulu: edit boleh menyimpan tabel kosong; tabel wajib
        // lengkap hanya saat kirim ke dosen (dijaga submit()). Berlaku untuk
        // revisi maupun logbook induk yang step 3-nya memakai kartu jawaban.
        $rules['riwayat_perbaikan'] = ['nullable', 'array'];
        $rules['riwayat_perbaikan.*.halaman'] = ['nullable', 'string', 'max:255'];
        $rules['riwayat_perbaikan.*.komentar_dosen'] = ['nullable', 'string', 'max:1000'];
        $rules['riwayat_perbaikan.*.perbaikan'] = ['nullable', 'string', 'max:2000'];
        $rules['riwayat_perbaikan.*.status'] = ['nullable', 'in:'.implode(',', LogbookEntry::PERBAIKAN_STATUSES)];

        if ($isRevisi) {
            $rules['tanggal_pengiriman'] = ['required', 'date', 'before_or_equal:today'];
        } else {
            $rules['tanggal_bimbingan'] = ['required', 'date', 'before_or_equal:today'];
            $rules['topik'] = ['required', 'string', 'max:255'];
        }

        $rules['lampiran'] = ['nullable', 'file', 'mimes:'.$mimes, 'max:'.$maxKb];

        return $rules;
    }

    public function messages(): array
    {
        $inst = Institution::current();
        $maxMb = $inst->maxUploadSizeMb();
        $types = strtoupper(implode(', ', $inst->allowedFileTypes()));

        return [
            'tanggal_bimbingan.required' => 'Tanggal bimbingan wajib diisi.',
            'tanggal_bimbingan.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'tanggal_pengiriman.required' => 'Tanggal pengiriman revisi wajib diisi.',
            'tanggal_pengiriman.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'topik.required' => 'Topik bimbingan wajib diisi.',
            'progres_kendala.max' => 'Pesan untuk dosen maksimal 500 karakter.',
            'riwayat_perbaikan.required' => 'Isi kartu jawaban minimal 1 (tandai di PDF lalu salin, atau tulis manual).',
            'riwayat_perbaikan.min' => 'Isi kartu jawaban minimal 1 (tandai di PDF lalu salin, atau tulis manual).',
            'riwayat_perbaikan.*.halaman.required' => 'Kolom Halaman/Bagian wajib diisi.',
            'riwayat_perbaikan.*.komentar_dosen.required' => 'Kolom Komentar dosen wajib diisi.',
            'riwayat_perbaikan.*.perbaikan.required' => 'Kolom Perbaikan Anda wajib diisi.',
            'riwayat_perbaikan.*.status.required' => 'Kolom Status wajib dipilih.',
            'lampiran.mimes' => 'Lampiran harus berupa file '.$types.'.',
            'lampiran.max' => 'Ukuran lampiran maksimal '.$maxMb.' MB.',
        ];
    }
}