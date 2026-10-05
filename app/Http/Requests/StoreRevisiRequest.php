<?php

namespace App\Http\Requests;

use App\Models\Institution;
use App\Models\LogbookEntry;
use App\Support\ProgramContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRevisiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validasi entri revisi. Mahasiswa dapat membuat entri revisi tanpa harus
     * ada logbook terlebih dahulu (parent_entry_id opsional).
     * Catatan perbaikan diisi sebagai tabel terstruktur (riwayat_perbaikan),
     * bukan upload file PDF. PDF catatan perbaikan dibuat otomatis oleh sistem.
     * Penerima revisi (addressed_dosen_id) boleh pembimbing ATAU dosen penguji
     * program; kosong = default pembimbing 1.
     */
    public function rules(): array
    {
        $inst = Institution::current();
        $maxKb = $inst->maxUploadSizeMb() * 1024;
        $mimes = implode(',', $inst->allowedFileTypes());

        $ta = ProgramContext::resolve($this->user(), $this);
        $allowedDosenIds = $ta ? $ta->allDosenIds() : [];

        return [
            'parent_entry_id' => [
                'nullable',
                'integer',
                Rule::exists('logbook_entries', 'id')->where(function ($query) use ($ta) {
                    $query->where('mahasiswa_ta_id', $ta?->id)
                        ->whereIn('status', ['revisi', 'revision_in_progress']);
                }),
            ],
            'addressed_dosen_id' => ['nullable', Rule::in($allowedDosenIds)],
            'addressed_comment_ids' => ['nullable', 'array'],
            'addressed_comment_ids.*' => ['integer', 'distinct'],
            'tanggal_pengiriman' => ['required', 'date', 'before_or_equal:today'],
            'progres_kendala' => ['nullable', 'string', 'max:500'],
            'riwayat_perbaikan' => ['required', 'array', 'min:1'],
            'riwayat_perbaikan.*.halaman' => ['required', 'string', 'max:255'],
            'riwayat_perbaikan.*.komentar_dosen' => ['required', 'string', 'max:1000'],
            'riwayat_perbaikan.*.perbaikan' => ['required', 'string', 'max:2000'],
            'riwayat_perbaikan.*.status' => ['required', 'in:'.implode(',', LogbookEntry::PERBAIKAN_STATUSES)],
            'lampiran' => ['required', 'file', 'mimes:'.$mimes, 'max:'.$maxKb],
        ];
    }

    public function messages(): array
    {
        $inst = Institution::current();
        $maxMb = $inst->maxUploadSizeMb();
        $types = strtoupper(implode(', ', $inst->allowedFileTypes()));

        return [
            'tanggal_pengiriman.required' => 'Tanggal pengiriman revisi wajib diisi.',
            'tanggal_pengiriman.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'parent_entry_id.required' => 'Entri asal revisi wajib dipilih.',
            'addressed_dosen_id.in' => 'Penerima revisi harus pembimbing atau dosen penguji program Anda.',
            'progres_kendala.max' => 'Pesan untuk dosen maksimal 500 karakter.',
            'riwayat_perbaikan.required' => 'Tabel catatan perbaikan wajib diisi minimal 1 baris.',
            'riwayat_perbaikan.min' => 'Tabel catatan perbaikan wajib diisi minimal 1 baris.',
            'riwayat_perbaikan.*.halaman.required' => 'Kolom Halaman/Bagian wajib diisi.',
            'riwayat_perbaikan.*.komentar_dosen.required' => 'Kolom Komentar Dosen wajib diisi.',
            'riwayat_perbaikan.*.perbaikan.required' => 'Kolom Perbaikan yang Dilakukan wajib diisi.',
            'riwayat_perbaikan.*.status.required' => 'Kolom Status wajib dipilih.',
            'lampiran.required' => 'File perbaikan wajib diunggah.',
            'lampiran.mimes' => 'File perbaikan harus berupa file '.$types.'.',
            'lampiran.max' => 'File perbaikan maksimal '.$maxMb.' MB.',
        ];
    }
}