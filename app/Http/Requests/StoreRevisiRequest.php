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
     * Validasi entri revisi. Revisi selalu menjawab satu entri (induk):
     * draf boleh yatim agar alur anotasi-dulu tetap jalan (upload file ->
     * anotasi di PDF -> tarik otomatis ke tabel), tapi saat dikirim ke dosen
     * (`submit`) induk wajib dipilih agar thread tidak putus.
     * Catatan perbaikan diisi sebagai tabel terstruktur (riwayat_perbaikan),
     * bukan upload file PDF. PDF catatan perbaikan dibuat otomatis oleh sistem.
     * Penerima revisi (addressed_dosen_id) boleh pembimbing ATAU dosen penguji
     * program; kosong = default pembimbing 1.
     *
     * Alur anotasi-dulu: tabel perbaikan boleh kosong saat simpan draf
     * (tanpa `submit`); tabel wajib lengkap hanya saat kirim ke dosen.
     */
    public function rules(): array
    {
        $inst = Institution::current();
        $maxKb = $inst->maxUploadSizeMb() * 1024;
        $mimes = implode(',', $inst->allowedFileTypes());

        $ta = ProgramContext::resolve($this->user(), $this);
        $allowedDosenIds = $ta ? $ta->allDosenIds() : [];
        $isSubmit = $this->boolean('submit');
        $cell = $isSubmit ? 'required' : 'nullable';

        // Refresh-kehilangan-file: bila draf wizard yang belum dikirim sudah
        // punya file, upload ulang tidak wajib (pakai file draf yang ada).
        $hasDraftFile = false;
        if ($ta && $this->filled('parent_entry_id')) {
            $hasDraftFile = LogbookEntry::where('parent_entry_id', $this->input('parent_entry_id'))
                ->where('mahasiswa_ta_id', $ta->id)
                ->where('status', LogbookEntry::STATUS_REVISION_IN_PROGRESS)
                ->whereNull('submitted_at')
                ->whereNotNull('lampiran_path')
                ->exists();
        }
        // Draf mandiri (tanpa induk) yang dilanjutkan: file miliknya sendiri.
        if (! $hasDraftFile && $ta && $this->filled('draft_id')) {
            $hasDraftFile = LogbookEntry::whereKey($this->input('draft_id'))
                ->where('mahasiswa_ta_id', $ta->id)
                ->whereNotNull('lampiran_path')
                ->exists();
        }

        return [
            'parent_entry_id' => [
                $isSubmit ? 'required' : 'nullable',
                'integer',
                Rule::exists('logbook_entries', 'id')->where(function ($query) use ($ta) {
                    $query->where('mahasiswa_ta_id', $ta?->id)
                        ->whereIn('status', ['revisi', 'revision_in_progress']);
                }),
            ],
            'draft_id' => [
                'nullable',
                'integer',
                Rule::exists('logbook_entries', 'id')->where(function ($query) use ($ta) {
                    $query->where('mahasiswa_ta_id', $ta?->id)
                        ->where('jenis', LogbookEntry::JENIS_REVISI);
                }),
            ],
            'addressed_dosen_id' => ['nullable', Rule::in($allowedDosenIds)],
            'addressed_comment_status' => ['nullable', 'array'],
            'addressed_comment_status.*' => ['in:Sudah,Sebagian,Belum'],
            'tanggal_pengiriman' => ['required', 'date', 'before_or_equal:today'],
            'progres_kendala' => ['nullable', 'string', 'max:500'],
            'riwayat_perbaikan' => $isSubmit ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'riwayat_perbaikan.*.halaman' => [$cell, 'string', 'max:255'],
            'riwayat_perbaikan.*.komentar_dosen' => [$cell, 'string', 'max:1000'],
            'riwayat_perbaikan.*.perbaikan' => [$cell, 'string', 'max:2000'],
            'riwayat_perbaikan.*.status' => [$cell, 'in:'.implode(',', LogbookEntry::PERBAIKAN_STATUSES)],
            'lampiran' => [$hasDraftFile ? 'nullable' : 'required', 'file', 'mimes:'.$mimes, 'max:'.$maxKb],
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
            'parent_entry_id.required' => 'Pilih dulu entri yang dijawab revisi ini — revisi harus menempel ke satu entri agar tidak terputus dari komentar dosen.',
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