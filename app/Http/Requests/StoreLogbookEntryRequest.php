<?php

namespace App\Http\Requests;

use App\Models\Institution;
use App\Models\LogbookEntry;
use App\Support\ProgramContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLogbookEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validasi entri logbook (sesi bimbingan biasa).
     * Ukuran & jenis file upload diatur admin (institution settings).
     *
     * Gerbang lunak revisi: bila program masih punya revisi pending,
     * mahasiswa wajib mencentang konfirmasi "sesi baru, bukan jawaban
     * revisi" (flag confirm_new_despite_revision) agar thread revisi
     * tidak putus diam-diam lewat entri baru.
     */
    public function rules(): array
    {
        $inst = Institution::current();
        $maxKb = $inst->maxUploadSizeMb() * 1024;
        $mimes = implode(',', $inst->allowedFileTypes());
        $ta = ProgramContext::resolve($this->user(), $this);

        // Lanjutan draf (?draft_id=) yang filenya sudah ada: upload ulang
        // tidak wajib (pakai file draf), sama seperti alur revisi.
        $hasDraftFile = false;
        if ($ta && $this->filled('draft_id')) {
            $hasDraftFile = \App\Models\LogbookEntry::whereKey($this->input('draft_id'))
                ->where('mahasiswa_ta_id', $ta->id)
                ->whereNotNull('lampiran_path')
                ->exists();
        }

        $submit = $this->boolean('submit');

        $rules = [
            'draft_id' => ['nullable', 'integer', Rule::exists('logbook_entries', 'id')->where(function ($query) use ($ta) {
                $query->where('mahasiswa_ta_id', $ta?->id)
                    ->where('jenis', \App\Models\LogbookEntry::JENIS_LOGBOOK)
                    ->where('status', \App\Models\LogbookEntry::STATUS_DRAFT);
            })],
            'addressed_dosen_id' => ['nullable', Rule::in($ta?->allDosenIds() ?? [])],
            'tanggal_bimbingan' => ['required', 'date', 'before_or_equal:today'],
            'topik' => ['required', 'string', 'max:255'],
            // Step 3 memakai kartu jawaban seperti revisi: draf boleh kosong
            // (alur upload → tandai → salin), kirim wajib minimal 1 kartu
            // lengkap. Pesan untuk dosen opsional (maks 500).
            'progres_kendala' => ['nullable', 'string', 'max:500'],
            'riwayat_perbaikan' => $submit ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'riwayat_perbaikan.*.halaman' => [$submit ? 'required' : 'nullable', 'string', 'max:255'],
            'riwayat_perbaikan.*.komentar_dosen' => [$submit ? 'required' : 'nullable', 'string', 'max:1000'],
            'riwayat_perbaikan.*.perbaikan' => [$submit ? 'required' : 'nullable', 'string', 'max:2000'],
            'riwayat_perbaikan.*.status' => [$submit ? 'required' : 'nullable', 'in:'.implode(',', LogbookEntry::PERBAIKAN_STATUSES)],
            'lampiran' => [$hasDraftFile ? 'nullable' : 'required', 'file', 'mimes:'.$mimes, 'max:'.$maxKb],
        ];

        if (\App\Models\LogbookEntry::pendingRevisionsFor($ta)->isNotEmpty()) {
            $rules['confirm_new_despite_revision'] = ['required', 'accepted'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $inst = Institution::current();
        $maxMb = $inst->maxUploadSizeMb();
        $types = strtoupper(implode(', ', $inst->allowedFileTypes()));

        return [
            'addressed_dosen_id.in' => 'Pilih pembimbing atau penguji programmu.',
            'tanggal_bimbingan.required' => 'Isi tanggal bimbingan.',
            'tanggal_bimbingan.before_or_equal' => 'Tanggal jangan hari esok.',
            'topik.required' => 'Isi topik.',
            'progres_kendala.max' => 'Pesan untuk dosen maksimal 500 karakter.',
            'riwayat_perbaikan.required' => 'Isi kartu jawaban minimal 1 (tandai di PDF lalu salin, atau tulis manual).',
            'riwayat_perbaikan.min' => 'Isi kartu jawaban minimal 1 (tandai di PDF lalu salin, atau tulis manual).',
            'riwayat_perbaikan.*.halaman.required' => 'Kolom Halaman/Bagian wajib diisi.',
            'riwayat_perbaikan.*.komentar_dosen.required' => 'Kolom Komentar dosen wajib diisi.',
            'riwayat_perbaikan.*.perbaikan.required' => 'Kolom Perbaikan Anda wajib diisi.',
            'riwayat_perbaikan.*.status.required' => 'Kolom Status wajib dipilih.',
            'lampiran.required' => 'Unggah file dulu.',
            'lampiran.mimes' => 'File harus '.$types.'.',
            'lampiran.max' => 'File maks. '.$maxMb.' MB.',
            'confirm_new_despite_revision.required' => 'Centang "Ini topik baru" dulu, atau jawab revisinya.',
            'confirm_new_despite_revision.accepted' => 'Centang "Ini topik baru" dulu, atau jawab revisinya.',
        ];
    }
}
