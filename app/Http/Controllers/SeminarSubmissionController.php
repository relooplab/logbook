<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\SeminarSubmissionDocument;
use App\Models\Sidang;
use App\Models\WorkspaceFile;
use App\Notifications\SeminarSubmissionNotification;
use App\Services\StorageUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SeminarSubmissionController extends Controller
{
    /**
     * Batas dokumen tambahan (satu sumber untuk validasi + tampilan):
     * maks file dan total ukuran seluruh file tambahan.
     */
    private const TAMBAHAN_MAX_FILES = 3;

    private const TAMBAHAN_MAX_TOTAL_MB = 10;

    private const TAUTAN_MAX_LINKS = 10;

    /**
     * Mapping fase aktif -> jenis seminar. (Delegasi ke model agar satu sumber.)
     */
    private function jenisFromFase(MahasiswaTa $ta): string
    {
        return SeminarSubmission::jenisFromFase($ta);
    }

    /**
     * Form isi pemberian bahan seminar/sidang.
     */
    public function create(Request $request, MahasiswaTa $mahasiswaTa): View
    {
        abort_unless($mahasiswaTa->isMember($request->user()), 403);
        $this->authorize('viewWorkspace', $mahasiswaTa);

        $jenis = $this->jenisFromFase($mahasiswaTa);
        $jenisLabel = (new SeminarSubmission(['jenis' => $jenis]))->jenisLabel();
        $institution = Institution::current();
        $defaultCatatan = (string) ($institution->seminar_hardcopy_note ?: '');
        $maxMb = $institution->maxUploadSizeMb();
        $allowedTypes = $institution->allowedFileTypes();
        $fileAccept = $institution->fileAccept();

        // Daftar dosen untuk pilihan "undangan sebagai".
        $undanganOptions = $this->undanganOptions($mahasiswaTa);

        // File workspace untuk pilihan materi.
        $workspaceFiles = $mahasiswaTa->workspaceFiles()->orderByDesc('created_at')->get();

        // Relasi konteks sidebar (mahasiswa, pembimbing/penguji) — satu query.
        $mahasiswaTa->loadMissing(['mahasiswa', 'pembimbing1', 'pembimbing2', 'penguji1', 'penguji2']);

        $tambahanMaxFiles = self::TAMBAHAN_MAX_FILES;
        $tambahanMaxTotalMb = self::TAMBAHAN_MAX_TOTAL_MB;
        $tautanMaxLinks = self::TAUTAN_MAX_LINKS;

        return view('seminar-submission.create', compact(
            'mahasiswaTa', 'jenis', 'jenisLabel', 'defaultCatatan',
            'undanganOptions', 'workspaceFiles', 'maxMb', 'allowedTypes', 'fileAccept',
            'tambahanMaxFiles', 'tambahanMaxTotalMb', 'tautanMaxLinks'
        ));
    }

    /**
     * Simpan submission.
     */
    public function store(Request $request, MahasiswaTa $mahasiswaTa): RedirectResponse
    {
        abort_unless($mahasiswaTa->isMember($request->user()), 403);
        $this->authorize('viewWorkspace', $mahasiswaTa);

        $institution = Institution::current();
        $maxMb = $institution->maxUploadSizeMb();
        $allowedTypes = $institution->allowedFileTypes();
        $mimes = implode(',', array_map(fn ($t) => $t === 'pdf' ? 'pdf' : $t, $allowedTypes));

        $data = $request->validate([
            'tanggal' => ['required', 'date', 'after_or_equal:today'],
            'waktu' => ['required', 'date_format:H:i'],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url', 'max:2048'],
            'undangan' => ['required', 'file', 'mimes:'.$mimes, 'max:'.($maxMb * 1024)],
            'undangan_kepada' => ['required', 'array', 'min:1'],
            'undangan_kepada.*' => ['in:pembimbing_1,pembimbing_2,penguji_1,penguji_2'],
            'materi_upload' => ['nullable', 'file', 'mimes:'.$mimes, 'max:'.($maxMb * 1024)],
            'materi_workspace_id' => ['nullable', 'integer', 'exists:workspace_files,id'],
            'catatan_keterangan' => ['nullable', 'string'],
            'dokumen_tambahan' => ['nullable', 'array', 'max:'.self::TAMBAHAN_MAX_FILES],
            'dokumen_tambahan.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:'.(self::TAMBAHAN_MAX_TOTAL_MB * 1024)],
            'tautan' => ['nullable', 'array', 'max:'.self::TAUTAN_MAX_LINKS],
            'tautan.*' => ['nullable', 'url', 'max:2048'],
        ]);

        // Dokumen tambahan: total seluruh file maks TAMBAHAN_MAX_TOTAL_MB.
        $tambahanFiles = array_values(array_filter((array) $request->file('dokumen_tambahan')));
        $tambahanSize = array_sum(array_map(fn ($f) => $f->getSize(), $tambahanFiles));
        if ($tambahanSize > self::TAMBAHAN_MAX_TOTAL_MB * 1024 * 1024) {
            return back()->withErrors(['dokumen_tambahan' => 'Total ukuran file tambahan maksimal '.self::TAMBAHAN_MAX_TOTAL_MB.' MB.'])->withInput();
        }
        $tautanList = collect((array) $request->input('tautan', []))
            ->map(fn ($u) => trim((string) $u))->filter()->unique()->values()->all();

        // Materi wajib: salah satu dari upload baru ATAU dari workspace.
        if ($request->file('materi_upload') === null && !$request->filled('materi_workspace_id')) {
            return back()->withErrors(['materi_upload' => 'Pilih salah satu: upload file materi atau ambil dari workspace.'])->withInput();
        }

        $jenis = $this->jenisFromFase($mahasiswaTa);
        $defaultCatatan = (string) ($institution->seminar_hardcopy_note ?: '');

        // Cek kuota target pembebanan (dosen pembimbing saat aktif, mahasiswa 100 MB saat pending).
        $dosen = $mahasiswaTa->storageChargeTarget();

        $createSubmission = function () use ($request, $mahasiswaTa, $jenis, $data, $defaultCatatan, $tambahanFiles, $tautanList) {
            // Materi: upload baru ATAU dari workspace (salah satu, tidak boleh keduanya kosong).
            $materiPath = null;
            $materiOriginal = null;
            $materiWorkspaceId = null;

            if ($request->filled('materi_workspace_id') && $request->file('materi_upload') === null) {
                $file = WorkspaceFile::find($request->input('materi_workspace_id'));
                if ($file && $file->mahasiswa_ta_id === $mahasiswaTa->id) {
                    $materiPath = $file->path;
                    $materiOriginal = $file->original_name;
                    $materiWorkspaceId = $file->id;
                }
            } elseif ($request->file('materi_upload')) {
                $materiPath = $request->file('materi_upload')->store('seminar-materials/'.$mahasiswaTa->id, 'local');
                $materiOriginal = $request->file('materi_upload')->getClientOriginalName();
            }

            $undanganPath = $request->file('undangan')->store('seminar-materials/'.$mahasiswaTa->id, 'local');

            $submission = SeminarSubmission::create([
                'mahasiswa_ta_id' => $mahasiswaTa->id,
                'jenis' => $jenis,
                'tanggal' => $data['tanggal'],
                'waktu' => $data['waktu'],
                'lokasi' => $data['lokasi'] ?? null,
                'meeting_link' => $data['meeting_link'] ?? null,
                'undangan_path' => $undanganPath,
                'undangan_original_name' => $request->file('undangan')->getClientOriginalName(),
                'undangan_kepada' => $data['undangan_kepada'],
                'materi_path' => $materiPath,
                'materi_original_name' => $materiOriginal,
                'materi_workspace_file_id' => $materiWorkspaceId,
                'catatan_hardcopy' => $defaultCatatan,
                'catatan_keterangan' => $data['catatan_keterangan'] ?? null,
                'status' => SeminarSubmission::STATUS_SUBMITTED,
            ]);

            $this->storeTambahanDocuments($submission, $tambahanFiles, $tautanList);

            return $submission;
        };

        if ($dosen) {
            $incoming = $request->file('undangan')->getSize()
                + ($request->file('materi_upload') ? $request->file('materi_upload')->getSize() : 0)
                + $tambahanSize;
            $submission = app(StorageUsageService::class)->withUploadLock($dosen, $incoming, $createSubmission);
        } else {
            $submission = $createSubmission();
        }

        // Notifikasi ke dosen terkait.
        $this->notifyDosen($mahasiswaTa, $submission);

        // Kirim notifikasi & email yang sama ke mahasiswa pengirim sebagai
        // bukti/salinan bahan yang telah dikirim (sapaan dibedakan via peran).
        if ($mahasiswa = $mahasiswaTa->mahasiswa) {
            $this->bestEffort(fn () => $mahasiswa->notify(new SeminarSubmissionNotification($submission, 'mahasiswa')));
        }

        return redirect()->route('seminar-submission.show', $submission)
            ->with('success', 'Bahan seminar/sidang berhasil dikirim.');
    }

    /**
     * Detail submission.
     */
    public function show(Request $request, SeminarSubmission $submission): View|RedirectResponse
    {
        if ($r = $this->authorizeView($request->user(), $submission)) {
            return $r;
        }

        $submission->load(['mahasiswaTa.mahasiswa', 'mahasiswaTa.pembimbing1', 'mahasiswaTa.pembimbing2', 'mahasiswaTa.penguji1', 'mahasiswaTa.penguji2', 'workspaceFile', 'sidang.grades.user', 'documents']);

        $isDosen = $request->user()->isDosen();
        $isMember = $submission->mahasiswaTa->isMember($request->user());

        // Tandai sudah dibaca oleh dosen (untuk badge "Baru" di Agenda Seminar/Sidang).
        if ($isDosen) {
            $submission->markReadBy($request->user());
        }

        $meetingLink = $submission->effectiveMeetingLink();
        $locationText = $submission->locationText();

        return view('seminar-submission.show', compact('submission', 'isDosen', 'isMember', 'meetingLink', 'locationText'));
    }

    /**
     * Form edit (mahasiswa).
     */
    public function edit(Request $request, SeminarSubmission $submission): View
    {
        abort_unless($submission->mahasiswaTa->isMember($request->user()), 403);
        // Submission hanya bisa diubah selama belum dikonversi ke riwayat sidang
        // DAN jadwal seminar/sidang belum lewat.
        abort_unless($submission->isUpdatableByStudent(), 403, 'Submisi sudah lewat jadwal/dikonversi ke sidang dan tidak dapat diubah.');

        $institution = Institution::current();
        $maxMb = $institution->maxUploadSizeMb();
        $allowedTypes = $institution->allowedFileTypes();
        $fileAccept = $institution->fileAccept();

        $undanganOptions = $this->undanganOptions($submission->mahasiswaTa);
        $workspaceFiles = $submission->mahasiswaTa->workspaceFiles()->orderByDesc('created_at')->get();

        // Relasi konteks sidebar + dokumen existing — satu query.
        $submission->loadMissing(['documents', 'mahasiswaTa.mahasiswa', 'mahasiswaTa.pembimbing1', 'mahasiswaTa.pembimbing2', 'mahasiswaTa.penguji1', 'mahasiswaTa.penguji2']);

        $tambahanMaxFiles = self::TAMBAHAN_MAX_FILES;
        $tambahanMaxTotalMb = self::TAMBAHAN_MAX_TOTAL_MB;
        $tautanMaxLinks = self::TAUTAN_MAX_LINKS;

        return view('seminar-submission.edit', compact('submission', 'undanganOptions', 'workspaceFiles', 'maxMb', 'allowedTypes', 'fileAccept', 'tambahanMaxFiles', 'tambahanMaxTotalMb', 'tautanMaxLinks'));
    }

    /**
     * Update submission (mahasiswa).
     */
    public function update(Request $request, SeminarSubmission $submission): RedirectResponse
    {
        abort_unless($submission->mahasiswaTa->isMember($request->user()), 403);
        // Submission hanya bisa diubah selama belum dikonversi ke riwayat sidang
        // DAN jadwal seminar/sidang belum lewat.
        abort_unless($submission->isUpdatableByStudent(), 403, 'Submisi sudah lewat jadwal/dikonversi ke sidang dan tidak dapat diubah.');

        $institution = Institution::current();
        $maxMb = $institution->maxUploadSizeMb();
        $allowedTypes = $institution->allowedFileTypes();
        $mimes = implode(',', array_map(fn ($t) => $t === 'pdf' ? 'pdf' : $t, $allowedTypes));

        $data = $request->validate([
            'tanggal' => ['required', 'date', 'after_or_equal:today'],
            'waktu' => ['required', 'date_format:H:i'],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url', 'max:2048'],
            'undangan' => ['nullable', 'file', 'mimes:'.$mimes, 'max:'.($maxMb * 1024)],
            'undangan_kepada' => ['required', 'array', 'min:1'],
            'undangan_kepada.*' => ['in:pembimbing_1,pembimbing_2,penguji_1,penguji_2'],
            'materi_upload' => ['nullable', 'file', 'mimes:'.$mimes, 'max:'.($maxMb * 1024)],
            'materi_workspace_id' => ['nullable', 'integer', 'exists:workspace_files,id'],
            'catatan_keterangan' => ['nullable', 'string'],
            'dokumen_tambahan' => ['nullable', 'array', 'max:'.self::TAMBAHAN_MAX_FILES],
            'dokumen_tambahan.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:'.(self::TAMBAHAN_MAX_TOTAL_MB * 1024)],
            'hapus_dokumen' => ['nullable', 'array'],
            'hapus_dokumen.*' => ['integer', 'exists:seminar_submission_documents,id'],
            'tautan' => ['nullable', 'array', 'max:'.self::TAUTAN_MAX_LINKS],
            'tautan.*' => ['nullable', 'url', 'max:2048'],
        ]);

        // Dokumen tambahan: file dipertahankan + file baru maks 3, total maks 10 MB.
        $submission->loadMissing('documents');
        $hapusIds = collect((array) $request->input('hapus_dokumen', []))->map(fn ($v) => (int) $v)->all();
        $keptFiles = $submission->documents->where('type', SeminarSubmissionDocument::TYPE_FILE)
            ->reject(fn ($d) => in_array($d->id, $hapusIds, true));
        $newFiles = array_values(array_filter((array) $request->file('dokumen_tambahan')));
        if ($keptFiles->count() + count($newFiles) > self::TAMBAHAN_MAX_FILES) {
            return back()->withErrors(['dokumen_tambahan' => 'Jumlah file tambahan maksimal '.self::TAMBAHAN_MAX_FILES.' file.'])->withInput();
        }
        $totalTambahan = $keptFiles->sum('size') + array_sum(array_map(fn ($f) => $f->getSize(), $newFiles));
        if ($totalTambahan > self::TAMBAHAN_MAX_TOTAL_MB * 1024 * 1024) {
            return back()->withErrors(['dokumen_tambahan' => 'Total ukuran file tambahan maksimal '.self::TAMBAHAN_MAX_TOTAL_MB.' MB.'])->withInput();
        }
        $tautanList = collect((array) $request->input('tautan', []))
            ->map(fn ($u) => trim((string) $u))->filter()->unique()->values()->all();

        $payload = [
            'tanggal' => $data['tanggal'],
            'waktu' => $data['waktu'],
            'lokasi' => $data['lokasi'] ?? null,
            'meeting_link' => $data['meeting_link'] ?? null,
            'undangan_kepada' => $data['undangan_kepada'],
            'catatan_keterangan' => $data['catatan_keterangan'] ?? null,
        ];

        // Ringkasan perubahan untuk notifikasi (dibandingkan sebelum update).
        $changedFields = $this->diffSubmissionChanges($submission, $data, $request);
        $oldLinks = $submission->documents->where('type', SeminarSubmissionDocument::TYPE_LINK)->pluck('url')->sort()->values()->all();
        if (! empty($newFiles) || ! empty($hapusIds) || array_values($tautanList) !== array_values($oldLinks)) {
            $changedFields[] = 'Dokumen tambahan';
        }

        // Cek kuota target pembebanan (dosen pembimbing saat aktif, mahasiswa 100 MB saat pending).
        $dosen = $submission->mahasiswaTa->storageChargeTarget();

        $applyUpdate = function () use ($request, $submission, $payload, $newFiles, $hapusIds, $tautanList) {
            // Ganti undangan bila ada file baru.
            if ($request->file('undangan')) {
                Storage::disk('local')->delete($submission->undangan_path);
                $payload['undangan_path'] = $request->file('undangan')->store('seminar-materials/'.$submission->mahasiswa_ta_id, 'local');
                $payload['undangan_original_name'] = $request->file('undangan')->getClientOriginalName();
            }

            // Ganti materi bila ada pilihan baru.
            if ($request->filled('materi_workspace_id') && $request->file('materi_upload') === null) {
                $file = WorkspaceFile::find($request->input('materi_workspace_id'));
                if ($file && $file->mahasiswa_ta_id === $submission->mahasiswa_ta_id) {
                    $this->deleteMateriFile($submission);
                    $payload['materi_path'] = $file->path;
                    $payload['materi_original_name'] = $file->original_name;
                    $payload['materi_workspace_file_id'] = $file->id;
                }
            } elseif ($request->file('materi_upload')) {
                $this->deleteMateriFile($submission);
                $payload['materi_path'] = $request->file('materi_upload')->store('seminar-materials/'.$submission->mahasiswa_ta_id, 'local');
                $payload['materi_original_name'] = $request->file('materi_upload')->getClientOriginalName();
                $payload['materi_workspace_file_id'] = null;
            }

            $submission->update($payload);

            // Dokumen tambahan: hapus yang dicentang, tambah file baru,
            // tautan diganti seluruhnya dengan daftar yang dikirim.
            foreach ($submission->documents()->whereIn('id', $hapusIds)->get() as $doc) {
                $this->deleteTambahanDocument($doc);
            }
            if (! empty($newFiles)) {
                $this->storeTambahanDocuments($submission, $newFiles, []);
            }
            $submission->documents()->where('type', SeminarSubmissionDocument::TYPE_LINK)->delete();
            $this->storeTambahanDocuments($submission, [], $tautanList);
        };

        $incoming = ($request->file('undangan') ? $request->file('undangan')->getSize() : 0)
            + ($request->file('materi_upload') ? $request->file('materi_upload')->getSize() : 0)
            + array_sum(array_map(fn ($f) => $f->getSize(), $newFiles));

        if ($dosen && $incoming > 0) {
            app(StorageUsageService::class)->withUploadLock($dosen, $incoming, $applyUpdate);
        } else {
            $applyUpdate();
        }

        $submission->refresh();

        // Notifikasi perubahan ke dosen terkait + salinan ke mahasiswa pengirim,
        // dibedakan dari notifikasi kiriman pertama (subject/isi "Perubahan").
        $this->notifyDosen($submission->mahasiswaTa, $submission, true, $changedFields);
        if ($mahasiswa = $submission->mahasiswaTa->mahasiswa) {
            $this->bestEffort(fn () => $mahasiswa->notify(new SeminarSubmissionNotification($submission, 'mahasiswa', true, $changedFields)));
        }

        return redirect()->route('seminar-submission.show', $submission)
            ->with('success', 'Bahan seminar/sidang berhasil diperbarui.');
    }

    /**
     * Dosen mengedit catatan hardcopy.
     */
    public function updateHardcopyNote(Request $request, SeminarSubmission $submission): RedirectResponse
    {
        if ($r = $this->authorizeDosen($request->user(), $submission)) {
            return $r;
        }

        $validated = $request->validate([
            'catatan_hardcopy' => ['required', 'string'],
        ]);

        $submission->update(['catatan_hardcopy' => $validated['catatan_hardcopy']]);

        return back()->with('success', 'Catatan hardcopy diperbarui.');
    }

    /**
     * Download surat undangan.
     */
    public function downloadUndangan(Request $request, SeminarSubmission $submission)
    {
        if ($r = $this->authorizeView($request->user(), $submission)) {
            return $r;
        }

        return Storage::disk('local')->download($submission->undangan_path, $submission->undangan_original_name);
    }

    /**
     * Download materi.
     */
    public function downloadMateri(Request $request, SeminarSubmission $submission)
    {
        if ($r = $this->authorizeView($request->user(), $submission)) {
            return $r;
        }

        return Storage::disk('local')->download($submission->materi_path, $submission->materi_original_name);
    }

    /**
     * Download file dokumen tambahan.
     */
    public function downloadDokumen(Request $request, SeminarSubmission $submission, SeminarSubmissionDocument $document)
    {
        if ($r = $this->authorizeView($request->user(), $submission)) {
            return $r;
        }
        abort_unless($document->seminar_submission_id === $submission->id && $document->isFile(), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    /**
     * Preview surat undangan di browser — PDF dirender inline tanpa unduhan,
     * non-PDF otomatis diunduh sebagai attachment.
     */
    public function previewUndangan(Request $request, SeminarSubmission $submission)
    {
        if ($r = $this->authorizeView($request->user(), $submission)) {
            return $r;
        }

        if (! $submission->undangan_path || ! Storage::disk('local')->exists($submission->undangan_path)) {
            abort(404);
        }

        return $this->streamOrInline(
            $submission->undangan_path,
            $submission->undangan_original_name,
            $submission->isUndanganPdf()
        );
    }

    /**
     * Preview dokumen materi di browser — PDF dirender inline tanpa unduhan,
     * non-PDF otomatis diunduh sebagai attachment.
     */
    public function previewMateri(Request $request, SeminarSubmission $submission)
    {
        if ($r = $this->authorizeView($request->user(), $submission)) {
            return $r;
        }

        if (! $submission->materi_path || ! Storage::disk('local')->exists($submission->materi_path)) {
            abort(404);
        }

        return $this->streamOrInline(
            $submission->materi_path,
            $submission->materi_original_name,
            $submission->isMateriPdf()
        );
    }

    /**
     * Download surat undangan via tautan berbagi (signed URL) — tanpa login.
     * Dipakai oleh email notifikasi bahan seminar/sidang. Signature & masa
     * berlaku tautan sudah divalidasi middleware `signed`.
     */
    public function sharedDownloadUndangan(SeminarSubmission $submission)
    {
        if (! $submission->undangan_path || ! Storage::disk('local')->exists($submission->undangan_path)) {
            abort(404);
        }

        return $this->streamOrInline(
            $submission->undangan_path,
            $submission->undangan_original_name,
            $submission->isUndanganPdf()
        );
    }

    /**
     * Download materi via tautan berbagi (signed URL) — tanpa login.
     */
    public function sharedDownloadMateri(SeminarSubmission $submission)
    {
        if (! $submission->materi_path || ! Storage::disk('local')->exists($submission->materi_path)) {
            abort(404);
        }

        return $this->streamOrInline(
            $submission->materi_path,
            $submission->materi_original_name,
            $submission->isMateriPdf()
        );
    }

    /**
     * Kirim file: PDF ditampilkan inline di browser (preview tanpa unduhan),
     * sedangkan non-PDF tetap diunduh sebagai attachment.
     */
    private function streamOrInline(string $path, string $name, bool $isPdf)
    {
        if (! $isPdf) {
            return Storage::disk('local')->download($path, $name);
        }

        $fullPath = Storage::disk('local')->path($path);
        $size = (int) filesize($fullPath);

        return response()->streamDownload(function () use ($fullPath) {
            readfile($fullPath);
        }, $name, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => $size,
            'Cache-Control' => 'private, no-transform',
        ], 'inline');
    }

    /**
     * Daftar pilihan "undangan sebagai" dari data mahasiswa.
     */
    private function undanganOptions(MahasiswaTa $ta): array
    {
        $options = [];
        if ($ta->pembimbing1) {
            $options['pembimbing_1'] = 'Pembimbing 1 — '.$ta->pembimbing1->name;
        }
        if ($ta->pembimbing2) {
            $options['pembimbing_2'] = 'Pembimbing 2 — '.$ta->pembimbing2->name;
        }
        if ($ta->penguji1) {
            $options['penguji_1'] = 'Penguji 1 — '.$ta->penguji1->name;
        }
        if ($ta->penguji2) {
            $options['penguji_2'] = 'Penguji 2 — '.$ta->penguji2->name;
        }

        return $options;
    }

    /**
     * Notifikasi ke dosen terkait (pembimbing + penguji).
     */
    private function notifyDosen(MahasiswaTa $ta, SeminarSubmission $submission, bool $isUpdate = false, array $changedFields = []): void
    {
        foreach ($ta->allDosenIds() as $dosenId) {
            if ($dosen = \App\Models\User::find($dosenId)) {
                $this->bestEffort(fn () => $dosen->notify(new SeminarSubmissionNotification($submission, 'dosen', $isUpdate, $changedFields)));
            }
        }
    }

    /**
     * Bandingkan nilai lama vs baru untuk ringkasan "yang berubah" pada notifikasi.
     *
     * @return string[]
     */
    private function diffSubmissionChanges(SeminarSubmission $submission, array $data, Request $request): array
    {
        $changed = [];

        $oldTanggal = $submission->tanggal?->toDateString();
        $newTanggal = isset($data['tanggal']) ? date('Y-m-d', strtotime((string) $data['tanggal'])) : null;
        if ($newTanggal !== null && $newTanggal !== $oldTanggal) {
            $changed[] = 'Tanggal';
        }

        $oldWaktu = $submission->waktu?->format('H:i');
        if (isset($data['waktu']) && $data['waktu'] !== $oldWaktu) {
            $changed[] = 'Waktu';
        }

        if (($data['lokasi'] ?? null) !== $submission->lokasi) {
            $changed[] = 'Lokasi';
        }

        if (($data['meeting_link'] ?? null) !== $submission->meeting_link) {
            $changed[] = 'Tautan video conference';
        }

        $oldKepada = (array) ($submission->undangan_kepada ?? []);
        $newKepada = (array) ($data['undangan_kepada'] ?? []);
        sort($oldKepada);
        sort($newKepada);
        if ($oldKepada !== $newKepada) {
            $changed[] = 'Daftar undangan';
        }

        if (($data['catatan_keterangan'] ?? null) !== $submission->catatan_keterangan) {
            $changed[] = 'Catatan';
        }

        if ($request->file('undangan')) {
            $changed[] = 'Surat undangan';
        }

        if ($request->file('materi_upload') || $request->filled('materi_workspace_id')) {
            $changed[] = 'Materi';
        }

        return array_values(array_unique($changed));
    }

    /**
     * Hapus file materi (jika bukan dari workspace).
     */
    private function deleteMateriFile(SeminarSubmission $submission): void
    {
        if (!$submission->materiFromWorkspace() && $submission->materi_path) {
            Storage::disk('local')->delete($submission->materi_path);
        }
    }

    /**
     * Simpan dokumen tambahan (file + tautan) untuk submission.
     *
     * @param  array  $files  UploadedFile[]
     * @param  array  $links  string URL[]
     */
    private function storeTambahanDocuments(SeminarSubmission $submission, array $files, array $links): void
    {
        foreach ($files as $file) {
            $submission->documents()->create([
                'type' => SeminarSubmissionDocument::TYPE_FILE,
                'path' => $file->store('seminar-materials/'.$submission->mahasiswa_ta_id.'/tambahan', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);
        }

        foreach ($links as $url) {
            $submission->documents()->create([
                'type' => SeminarSubmissionDocument::TYPE_LINK,
                'url' => $url,
            ]);
        }
    }

    /**
     * Hapus satu dokumen tambahan file (record + berkas fisik).
     */
    private function deleteTambahanDocument(SeminarSubmissionDocument $document): void
    {
        if ($document->isFile() && $document->path) {
            Storage::disk('local')->delete($document->path);
        }
        $document->delete();
    }

    /**
     * Otorisasi akses view. Mengembalikan redirect bila dosen belum
     * menyetujui program (arahkan ke halaman persetujuan), selain itu null/403.
     */
    private function authorizeView($user, SeminarSubmission $submission): ?RedirectResponse
    {
        $ta = $submission->mahasiswaTa;

        if ($user->isAdmin() || $ta->isMember($user)) {
            return null;
        }

        if ($user->isDosen() && ($ta->isPembimbing($user) || $ta->isPenguji($user))) {
            if ($ta->dosenHasGrantedAccess()) {
                return null;
            }

            return redirect()->route('approval.index')
                ->with('warning', 'Mahasiswa ini belum disetujui. Setujui programnya untuk mengakses materinya.');
        }

        abort(403);
    }

    /**
     * Otorisasi dosen terkait (pembimbing/penguji) atau admin.
     * Redirect ke halaman persetujuan bila program belum disetujui.
     */
    private function authorizeDosen($user, SeminarSubmission $submission): ?RedirectResponse
    {
        $ta = $submission->mahasiswaTa;

        if ($user->isAdmin()) {
            return null;
        }

        if ($user->isDosen() && ($ta->isPembimbing($user) || $ta->isPenguji($user))) {
            if ($ta->dosenHasGrantedAccess()) {
                return null;
            }

            return redirect()->route('approval.index')
                ->with('warning', 'Mahasiswa ini belum disetujui. Setujui programnya untuk mengakses materinya.');
        }

        abort(403);
    }

}
