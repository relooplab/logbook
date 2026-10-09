<?php

namespace App\Http\Requests;

use App\Models\Institution;
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

        $rules = [
            'addressed_dosen_id' => ['nullable', Rule::in($ta?->allDosenIds() ?? [])],
            'tanggal_bimbingan' => ['required', 'date', 'before_or_equal:today'],
            'topik' => ['required', 'string', 'max:255'],
            'progres_kendala' => ['required', 'string'],
            'lampiran' => ['nullable', 'file', 'mimes:'.$mimes, 'max:'.$maxKb],
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
            'addressed_dosen_id.in' => 'Penerima logbook harus pembimbing atau dosen penguji program Anda.',
            'tanggal_bimbingan.required' => 'Tanggal bimbingan wajib diisi.',
            'tanggal_bimbingan.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'topik.required' => 'Topik bimbingan wajib diisi.',
            'progres_kendala.required' => 'Ringkasan perbaikan wajib diisi.',
            'lampiran.mimes' => 'Lampiran harus berupa file '.$types.'.',
            'lampiran.max' => 'Ukuran lampiran maksimal '.$maxMb.' MB.',
            'confirm_new_despite_revision.required' => 'Masih ada revisi yang belum selesai — selesaikan lewat revisi, atau centang pernyataan sesi baru di bawah.',
            'confirm_new_despite_revision.accepted' => 'Masih ada revisi yang belum selesai — selesaikan lewat revisi, atau centang pernyataan sesi baru di bawah.',
        ];
    }
}
