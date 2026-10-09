<?php

namespace App\Http\Controllers;

use App\Events\EntryStatusChanged;
use App\Events\PdfCommentCreated;
use App\Http\Requests\StoreLogbookEntryRequest;
use App\Http\Requests\StoreRevisiRequest;
use App\Http\Requests\UpdateLogbookEntryRequest;
use App\Models\FeedbackTemplate;
use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\PdfComment;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\AchievementService;
use App\Services\ArchiveLogbookReview;
use App\Services\LogbookReviewTransition;
use App\Services\StorageUsageService;
use App\Support\ProgramContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use setasign\Fpdi\Fpdi;

class LogbookController extends Controller
{
    // ---------------------------------------------------------------- create

    public function create(Request $request): View
    {
        $ta = ProgramContext::resolve($request->user(), $request);
        abort_unless($ta, 403, 'Anda belum memiliki program aktif (TA/KP).');

        // Auto-fill: sesi berikutnya & topik sebelumnya.
        $lastEntry = $ta->entries()
            ->where('jenis', LogbookEntry::JENIS_LOGBOOK)
            ->orderByDesc('sesi_ke')
            ->first();

        $nextSesi = ($lastEntry?->sesi_ke ?? 0) + 1;
        $lastTopik = $lastEntry?->topik;
        $dosenOptions = $ta->dosenRecipientOptions();
        $defaultRecipientId = old('addressed_dosen_id', $ta->pembimbing_1_id ?: array_key_first($dosenOptions));

        // Daftar entri yang masih bisa dihapus massal (isEditable: draf atau
        // revisi sedang dikerjakan yang belum dikirim) untuk kartu bulk delete.
        $deletableEntries = $ta->entries()
            ->withExists('revisionChildren')
            ->whereIn('status', [LogbookEntry::STATUS_DRAFT, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (LogbookEntry $e) => $e->isEditable())
            ->values();

        // Gerbang lunak: revisi yang masih menggantung + satu aksi lanjutan.
        // Dipakai banner + checkbox konfirmasi agar jawaban revisi tidak
        // dikirim diam-diam lewat sesi logbook baru.
        $pendingRevisions = LogbookEntry::pendingRevisionsFor($ta);
        $pendingRevisionAction = LogbookEntry::pendingRevisionActionFor($ta);

        return view('logbook.create', compact('ta', 'nextSesi', 'lastTopik', 'dosenOptions', 'defaultRecipientId', 'deletableEntries', 'pendingRevisions', 'pendingRevisionAction'));
    }

    public function createRevisi(Request $request): View
    {
        $ta = ProgramContext::resolve($request->user(), $request);
        abort_unless($ta, 403, 'Anda belum memiliki program aktif (TA/KP).');

        // Mode lanjutkan draf (?draft_id=): wizard memuat draf revisi yang
        // sudah ada (satu pintu editor). Draf harus milik user + editable.
        // Kembali dari viewer (?step=3&autopull=1): wizard langsung buka
        // langkah 3 dan menarik yang ditandai di PDF jadi isian form.
        $requestedStep = (int) $request->query('step', 0);
        $requestedStep = ($requestedStep >= 1 && $requestedStep <= 4) ? $requestedStep : 0;
        $autoPullOnLoad = $request->boolean('autopull');
        $draft = null;
        $draftId = $request->query('draft_id');
        if ($draftId) {
            $candidate = LogbookEntry::with(['mahasiswaTa', 'comments.user'])->find($draftId);
            $ownsDraft = $candidate && $request->user()->allPrograms()->where('id', $candidate->mahasiswaTa_id)->exists();
            abort_unless($candidate && $candidate->jenis === LogbookEntry::JENIS_REVISI && $ownsDraft, 404);
            abort_unless($request->user()->can('update', $candidate), 403);
            $draft = $candidate;
            if ($draft->mahasiswaTa && $draft->mahasiswaTa->id !== $ta->id) {
                $ta = $draft->mahasiswaTa;
            }
        }

        // Bila parent entry diberikan, program mengikuti parent agar tautan
        // dari feedback/detail logbook selalu mengarah ke program yang benar
        // meskipun query ?program= tidak dibawa.
        $selectedParentId = $draft?->parent_entry_id ?: $request->query('parent_entry_id');
        if ($selectedParentId) {
            $parent = LogbookEntry::with('mahasiswaTa')->find($selectedParentId);
            if ($parent && $parent->mahasiswaTa && $parent->mahasiswaTa->id !== $ta->id) {
                $ownsProgram = $request->user()->allPrograms()->where('id', $parent->mahasiswaTa_id)->exists();
                if ($ownsProgram) {
                    $ta = $parent->mahasiswaTa;
                }
            }
        }

        // Mahasiswa dapat membuat entri revisi tanpa harus ada logbook dulu.
        // Daftar parent (entri berstatus revisi / revisi sedang dikerjakan)
        // tetap tersedia untuk dipilih.
        $parents = $ta->entries()
            ->whereIn('status', [LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
            ->whereDoesntHave('revisionChildren')
            ->with('comments.user')
            ->latest('reviewed_at')
            ->get();

        // Induk milik draf yang dilanjutkan ikut ditampilkan (terkunci untuk
        // induk lain, tapi valid sebagai jawaban draf ini).
        if ($draft?->parentEntry && ! $parents->firstWhere('id', $draft->parentEntry->id)) {
            $draft->parentEntry->loadMissing('comments.user');
            $parents->push($draft->parentEntry);
        }

        // Draf yang sedang dibuka tidak boleh menjadi induk bagi dirinya sendiri.
        if ($draft) {
            $parents = $parents->reject(fn ($p) => $p->id === $draft->id)->values();
        }


        $selectedParent = $selectedParentId
            ? $parents->firstWhere('id', $selectedParentId)
            : null;

        $parentComments = collect();
        if ($selectedParent) {
            $parentComments = $selectedParent->comments()
                ->where('resolution_status', '!=', PdfComment::STATUS_RESOLVED)
                ->get();
        }

        // Pilihan penerima revisi: pembimbing 1/2 ATAU dosen penguji 1/2.
        // Default: penerima draf (mode lanjutkan), penerima entri induk
        // (bila ada), selain itu pembimbing 1.
        $dosenOptions = $ta->dosenRecipientOptions();
        $defaultRecipientId = old('addressed_dosen_id')
            ?: ($draft?->dosen_id ?: ($selectedParent?->dosen_id ?: $ta->pembimbing_1_id));

        if ($defaultRecipientId && ! isset($dosenOptions[(int) $defaultRecipientId])) {
            // Dosen default sudah tidak lagi terkait program (mis. penguji diganti).
            $defaultRecipientId = $ta->pembimbing_1_id ?: array_key_first($dosenOptions);
        }

        // Draf aktif yang menghalangi (untuk kotak aksi "Lanjutkan draf"):
        // dari submit yang baru ditolak, atau dari pilihan induk yang sudah
        // terkunci (tab basi / query parent_entry_id langsung).
        $activeRevision = null;
        $flashed = session('active_revision');
        if (is_array($flashed) && ! empty($flashed['id'])) {
            $child = $ta->entries()->whereKey($flashed['id'])->first();
            if ($child && $child->parent_entry_id && in_array($child->status, [LogbookEntry::STATUS_DRAFT, LogbookEntry::STATUS_SUBMITTED, LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS], true)) {
                $activeRevision = $this->activeRevisionPayload($child);
            }
        }
        if (! $activeRevision) {
            $attemptedParentId = $request->old('parent_entry_id', $selectedParentId);
            if ($attemptedParentId && ! $parents->firstWhere('id', (int) $attemptedParentId)) {
                $blockedParent = $ta->entries()->whereKey($attemptedParentId)->first();
                if ($blockedParent && ($child = $this->activeRevisionChild($blockedParent))) {
                    $activeRevision = $this->activeRevisionPayload($child);
                }
            }
        }

        // Nilai awal form: draf yang dilanjutkan menang atas default baru.
        $defaultTanggal = old('tanggal_pengiriman', $draft?->tanggal_pengiriman?->format('Y-m-d') ?? now()->format('Y-m-d'));
        $defaultPesan = old('progres_kendala', $draft?->progres_kendala);
        $draftLampiran = $draft?->lampiran_path
            ? ['name' => $draft->lampiran_original_name ?: basename($draft->lampiran_path), 'url' => route('logbook.pdf', $draft)]
            : null;
        $draftBoot = $draft ? [
            'entry_id' => $draft->id,
            'viewer_url' => route('logbook.pdf-viewer', $draft),
            'pull_url' => route('logbook.annotations.pull', $draft),
            'file_name' => $draftLampiran['name'] ?? null,
            'has_file' => (bool) $draftLampiran,
        ] : null;

        return view('logbook.create-revisi', compact(
            'ta', 'parents', 'selectedParentId', 'parentComments', 'dosenOptions', 'defaultRecipientId', 'activeRevision',
            'draft', 'defaultTanggal', 'defaultPesan', 'draftLampiran', 'draftBoot', 'requestedStep', 'autoPullOnLoad'
        ));
    }

    public function store(StoreLogbookEntryRequest $request): RedirectResponse
    {
        $ta = ProgramContext::resolve($request->user(), $request);
        abort_unless($ta, 403);

        $data = $request->validated();

        // Tombol "Kirim ke Dosen" langsung mengirim (bukan draf).
        $submit = $request->boolean('submit');

        // Buat entry dulu agar path unik {entry_id}/{uuid} bisa memakai id.
        // sesi_ke dihitung secara atomik & di-retry bila bentrok (unique index)
        // untuk menghindari race condition pada request paralel (TOCTOU).
        $entry = null;
        for ($attempt = 0; $attempt < 3 && ! $entry; $attempt++) {
            try {
                $entry = DB::transaction(function () use ($ta, $data, $submit) {
                    $sesiKe = (int) $ta->entries()
                        ->where('jenis', LogbookEntry::JENIS_LOGBOOK)
                        ->max('sesi_ke') + 1;

                    return $ta->entries()->create([
                        'dosen_id' => $data['addressed_dosen_id'] ?? $ta->pembimbing_1_id,
                        'tanggal_bimbingan' => $data['tanggal_bimbingan'],
                        'topik' => $data['topik'],
                        'sesi_ke' => $sesiKe,
                        'jenis' => LogbookEntry::JENIS_LOGBOOK,
                        'progres_kendala' => $data['progres_kendala'] ?? null,
                        'status' => $submit ? LogbookEntry::STATUS_SUBMITTED : LogbookEntry::STATUS_DRAFT,
                        'submitted_at' => $submit ? now() : null,
                    ]);
                });
            } catch (UniqueConstraintViolationException $e) {
                // Request paralel menghitung sesi yang sama — coba lagi.
            }
        }
        abort_unless($entry, 500, 'Gagal membuat entri logbook. Silakan coba kembali.');

        // Simpan lampiran dengan path unik + nama asli.
        if ($request->hasFile('lampiran')) {
            // Cek kuota target pembebanan (dosen pembimbing saat aktif, mahasiswa 100 MB saat pending).
            $dosen = $ta->storageChargeTarget();
            $storeLampiran = function () use ($request, $entry) {
                $entry->update([
                    'lampiran_path' => $this->storeUniqueFile($request->file('lampiran'), 'lampiran', $entry->id),
                    'lampiran_original_name' => $request->file('lampiran')->getClientOriginalName(),
                    'lampiran_size' => $request->file('lampiran')->getSize(),
                ]);
            };

            if ($dosen) {
                app(StorageUsageService::class)->withUploadLock($dosen, $request->file('lampiran')->getSize(), $storeLampiran);
            } else {
                $storeLampiran();
            }
        }

        if ($submit) {
            $this->bestEffort(fn () => EntryStatusChanged::dispatch($entry, 'Ada entri baru menunggu review.'));
            $entry->notifyReviewers(
                $entry->reviewSubmissionMessage(),
                route('logbook.show', $entry),
                'Entri Baru Menunggu Review',
            );
        }

        return redirect()->route('logbook.index')
            ->with('success', $submit
                ? 'Entri logbook dikirim ke dosen.'
                : 'Entri logbook tersimpan sebagai draf.');
    }

    /**
     * Draf-cepat untuk alur wizard: langkah Upload -> Lanjut -> PDF anotasi.
     * Menyimpan file + membuat entri draf (tabel boleh kosong), lalu
     * mengembalikan URL viewer/edit/pull agar wizard bisa membuka PDF di tab
     * baru dan mengisi kartu otomatis tanpa pindah halaman.
     * Idempoten per entri: panggil sekali, pakai ulang entry_id berikutnya.
     */
    public function storeRevisiDraft(StoreRevisiRequest $request): JsonResponse
    {
        $ta = ProgramContext::resolve($request->user(), $request);
        abort_unless($ta, 403);

        $data = $request->validated();

        // Mode lanjutkan draf (?draft_id=): draf sudah ada — simpan file baru
        // bila diunggah, lalu kembalikan URL alur anotasi draf tersebut.
        if (! empty($data['draft_id'])) {
            $explicit = LogbookEntry::whereKey($data['draft_id'])
                ->where('jenis', LogbookEntry::JENIS_REVISI)
                ->first();
            abort_unless($explicit && $request->user()->can('update', $explicit), 404);
            $ta = $explicit->mahasiswaTa;
            abort_unless($ta, 403);

            $explicit->update(array_filter([
                'tanggal_pengiriman' => $data['tanggal_pengiriman'] ?? null,
                'progres_kendala' => $data['progres_kendala'] ?? null,
                'dosen_id' => $data['addressed_dosen_id'] ?? null,
            ], fn ($v) => $v !== null));

            if ($request->hasFile('lampiran')) {
                $dosen = $ta->storageChargeTarget();
                $replaceLampiran = function () use ($request, $explicit) {
                    $explicit->update([
                        'lampiran_path' => $this->storeUniqueFile($request->file('lampiran'), 'lampiran', $explicit->id),
                        'lampiran_original_name' => $request->file('lampiran')->getClientOriginalName(),
                        'lampiran_size' => $request->file('lampiran')->getSize(),
                    ]);
                };
                if ($dosen) {
                    app(StorageUsageService::class)->withUploadLock($dosen, $request->file('lampiran')->getSize(), $replaceLampiran);
                } else {
                    $replaceLampiran();
                }
                $explicit = $explicit->fresh();
            }

            return response()->json([
                'ok' => true,
                'reused' => true,
                'entry_id' => $explicit->id,
                'viewer_url' => route('logbook.pdf-viewer', $explicit),
                'edit_url' => route('logbook.edit', $explicit),
                'pull_url' => route('logbook.annotations.pull', $explicit),
            ], 200);
        }
        // Idempoten: dua tab menekan Lanjut bersamaan → satu menang, satunya
        // memakai ulang. Tanpa induk hanya draf mandiri yang dipakai ulang
        // (draf berinduk milik alur lain tidak boleh terbajak).
        $reuse = $ta->entries()
            ->where('jenis', LogbookEntry::JENIS_REVISI)
            ->where('status', LogbookEntry::STATUS_REVISION_IN_PROGRESS)
            ->whereNull('submitted_at')
            ->when(! empty($data['parent_entry_id']),
                fn ($q) => $q->where('parent_entry_id', $data['parent_entry_id']),
                fn ($q) => $q->whereNull('parent_entry_id'))
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('id')->first();
        if ($reuse && $reuse->lampiran_path) {
            return response()->json([
                'ok' => true,
                'reused' => true,
                'entry_id' => $reuse->id,
                'viewer_url' => route('logbook.pdf-viewer', $reuse),
                'edit_url' => route('logbook.edit', $reuse),
                'pull_url' => route('logbook.annotations.pull', $reuse),
            ], 200);
        }

        [$parent, $entry] = DB::transaction(function () use ($ta, $data) {
            $parent = null;
            if (! empty($data['parent_entry_id'])) {
                $parent = $ta->entries()
                    ->whereKey($data['parent_entry_id'])
                    ->whereIn('status', [LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($parent->revisionChildren()
                    ->whereIn('status', [LogbookEntry::STATUS_DRAFT, LogbookEntry::STATUS_SUBMITTED, LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'parent_entry_id' => $this->activeRevisionMessage($parent),
                    ]);
                }

                if (($parent->revision_round ?? 0) + 1 > LogbookEntry::MAX_REVISION_ROUND) {
                    throw ValidationException::withMessages([
                        'parent_entry_id' => 'Entri ini sudah mencapai batas maksimal '.LogbookEntry::MAX_REVISION_ROUND.' sesi revisi.',
                    ]);
                }
            }

            $entry = $ta->entries()->create([
                'parent_entry_id' => $parent?->id,
                'revision_round' => $parent ? ($parent->revision_round ?? 0) + 1 : null,
                'sesi_ke' => null,
                'jenis' => LogbookEntry::JENIS_REVISI,
                'dosen_id' => $data['addressed_dosen_id']
                    ?? ($parent?->dosen_id ?: $parent?->reviewDosen()?->id ?: $ta->pembimbing_1_id),
                'topik' => $parent?->topik,
                'progres_kendala' => $data['progres_kendala'] ?? null,
                'tanggal_pengiriman' => $data['tanggal_pengiriman'],
                'status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS,
                'submitted_at' => null,
            ]);

            if ($parent) {
                $parent->update(['status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS]);
            }

            return [$parent, $entry];
        });

        $dosen = $ta->storageChargeTarget();
        $riwayat = collect($data['riwayat_perbaikan'] ?? [])
            ->filter(fn ($row) => filled($row['halaman'] ?? null) || filled($row['komentar_dosen'] ?? null) || filled($row['perbaikan'] ?? null))
            ->values()->all();
        $storeLampiran = function () use ($request, $entry, $riwayat) {
            $entry->update([
                'lampiran_path' => $this->storeUniqueFile($request->file('lampiran'), 'lampiran', $entry->id),
                'lampiran_original_name' => $request->file('lampiran')->getClientOriginalName(),
                'lampiran_size' => $request->file('lampiran')->getSize(),
                'riwayat_perbaikan' => $riwayat ?: null,
            ]);
        };

        // Edge: kuota penuh / file tak valid → withUploadLock melempar 422 SEBELUM
        // entri yatim tersimpan; bersihkan draf tanpa lampiran agar tidak menumpuk.
        if (! $request->hasFile('lampiran') && ! $entry->fresh()->lampiran_path) {
            $entry->delete();
            throw ValidationException::withMessages(['lampiran' => 'File perbaikan wajib diunggah.']);
        }

        try {
            if ($request->hasFile('lampiran')) {
                if ($dosen) {
                    app(StorageUsageService::class)->withUploadLock($dosen, $request->file('lampiran')->getSize(), $storeLampiran);
                } else {
                    $storeLampiran();
                }
            }
        } catch (\Throwable $e) {
            if (! $entry->fresh()->lampiran_path) {
                $entry->delete();
            }
            throw $e;
        }

        if (! empty($entry->fresh()->riwayat_perbaikan)) {
            $this->generateCatatanPerbaikanPdf($entry);
        }

        return response()->json([
            'ok' => true,
            'entry_id' => $entry->id,
            'viewer_url' => route('logbook.pdf-viewer', $entry),
            'edit_url' => route('logbook.edit', $entry),
            'pull_url' => route('logbook.annotations.pull', $entry),
        ], 201);
    }

    public function storeRevisi(StoreRevisiRequest $request): RedirectResponse
    {
        $ta = ProgramContext::resolve($request->user(), $request);
        abort_unless($ta, 403);

        $data = $request->validated();
        $submit = $request->boolean('submit');

        [$parent, $entry] = DB::transaction(function () use ($ta, $data, $submit, $request) {
            // Mode lanjutkan draf (?draft_id=): pakai draf yang dimaksud.
            // Kepemilikan + editable diverifikasi; program mengikuti draf
            // lewat field program tersembunyi (divalidasi di request).
            if (! empty($data['draft_id'])) {
                $explicit = LogbookEntry::whereKey($data['draft_id'])
                    ->where('jenis', LogbookEntry::JENIS_REVISI)
                    ->lockForUpdate()
                    ->first();
                abort_unless($explicit && $request->user()->can('update', $explicit), 404);
                abort_unless($explicit->mahasiswaTa, 403);

                // Penautan draf yatim: bila request membawa parent_entry_id dan
                // draf belum punya induk, tempelkan dengan validasi yang sama
                // seperti jalur buat-baru (milik program + eligible + ronde).
                $parent = $explicit->parentEntry;
                if (! $parent && ! empty($data['parent_entry_id'])) {
                    $candidate = $ta->entries()
                        ->whereKey($data['parent_entry_id'])
                        ->whereIn('status', [LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
                        ->where('id', '!=', $explicit->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $candidate) {
                        throw ValidationException::withMessages([
                            'parent_entry_id' => 'Entri yang dipilih tidak bisa dijawab (bukan milik program atau statusnya tidak meminta revisi).',
                        ]);
                    }
                    if ($this->activeRevisionChild($candidate, $explicit->id)) {
                        session()->flash('active_revision', $this->activeRevisionPayload($this->activeRevisionChild($candidate, $explicit->id)));
                        throw ValidationException::withMessages([
                            'parent_entry_id' => $this->activeRevisionMessage($candidate),
                        ]);
                    }
                    if (($candidate->revision_round ?? 0) + 1 > LogbookEntry::MAX_REVISION_ROUND) {
                        throw ValidationException::withMessages([
                            'parent_entry_id' => 'Entri ini sudah mencapai batas maksimal '.LogbookEntry::MAX_REVISION_ROUND.' sesi revisi.',
                        ]);
                    }
                    $explicit->update([
                        'parent_entry_id' => $candidate->id,
                        'revision_round' => ($candidate->revision_round ?? 0) + 1,
                    ]);
                    $parent = $candidate;
                }

                $entry = $explicit;
                $entry->update([
                    'dosen_id' => $data['addressed_dosen_id']
                        ?? ($explicit->dosen_id ?: ($parent?->dosen_id ?: $parent?->reviewDosen()?->id ?: $ta->pembimbing_1_id)),
                    'topik' => $parent?->topik ?? $explicit->topik,
                    'progres_kendala' => $data['progres_kendala'] ?? null,
                    'tanggal_pengiriman' => $data['tanggal_pengiriman'],
                    'status' => $submit ? LogbookEntry::STATUS_SUBMITTED : LogbookEntry::STATUS_REVISION_IN_PROGRESS,
                    'submitted_at' => $submit ? now() : null,
                ]);

                if ($parent && ! $submit) {
                    $parent->update(['status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS]);
                }

                return [$parent, $entry];
            }

            // Mahasiswa dapat membuat entri revisi tanpa harus ada logbook dulu.
            // Jika parent dipilih, validasi & tautkan ke entri induk.
            $parent = null;
            $reuse = null;
            if (! empty($data['parent_entry_id'])) {
                $parent = $ta->entries()
                    ->whereKey($data['parent_entry_id'])
                    ->whereIn('status', [LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
                    ->lockForUpdate()
                    ->firstOrFail();

                // Pakai ulang draf wizard yang belum dikirim (langkah 2 "Lanjut
                // ke anotasi" sudah membuat anak) agar submit akhir tidak
                // menabrak deteksi anak-aktif. Baris anak dikunci agar
                // double-submit tidak membuat duplikat.
                $reuse = $parent->revisionChildren()
                    ->where('mahasiswa_ta_id', $ta->id)
                    ->where('status', LogbookEntry::STATUS_REVISION_IN_PROGRESS)
                    ->whereNull('submitted_at')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($reuse && ! $request->user()->can('update', $reuse)) {
                    $reuse = null;
                }

                if (! $reuse && ($activeChild = $this->activeRevisionChild($parent))) {
                    session()->flash('active_revision', $this->activeRevisionPayload($activeChild));
                    throw ValidationException::withMessages([
                        'parent_entry_id' => $this->activeRevisionMessage($parent, $activeChild),
                    ]);
                }

                if (! $reuse && ($parent->revision_round ?? 0) + 1 > LogbookEntry::MAX_REVISION_ROUND) {
                    throw ValidationException::withMessages([
                        'parent_entry_id' => 'Entri ini sudah mencapai batas maksimal '.LogbookEntry::MAX_REVISION_ROUND.' sesi revisi.',
                    ]);
                }
            }

            // Nilai field sama untuk buat-baru maupun pakai-ulang draf.
            $wants = [
                'dosen_id' => $data['addressed_dosen_id']
                    ?? ($parent?->dosen_id ?: $parent?->reviewDosen()?->id ?: $ta->pembimbing_1_id),
                'topik' => $parent?->topik,
                'progres_kendala' => $data['progres_kendala'] ?? null,
                'tanggal_pengiriman' => $data['tanggal_pengiriman'],
                'status' => $submit ? LogbookEntry::STATUS_SUBMITTED : LogbookEntry::STATUS_REVISION_IN_PROGRESS,
                'submitted_at' => $submit ? now() : null,
            ];

            if ($reuse) {
                $reuse->update($wants);
                $entry = $reuse;
            } else {
                // Buat entry dulu agar path unik bisa memakai id.
                $entry = $ta->entries()->create($wants + [
                    'parent_entry_id' => $parent?->id,
                    'revision_round' => $parent ? ($parent->revision_round ?? 0) + 1 : null,
                    'sesi_ke' => null, // revisi: sesi tidak dipakai (null agar unique index (mahasiswa_ta_id, sesi_ke) mengizinkan banyak revisi)
                    'jenis' => LogbookEntry::JENIS_REVISI,
                    // `dosen_id` = reviewer entri. Penerima revisi yang dipilih
                    // mahasiswa (pembimbing ATAU dosen penguji) menjadi reviewer;
                    // kosong = rantai entri induk / pembimbing 1.
                ]);
            }

            // Parent yang sedang dikerjakan revisinya ditandai "Revisi sedang dikerjakan".
            if ($parent && ! $submit) {
                $parent->update(['status' => LogbookEntry::STATUS_REVISION_IN_PROGRESS]);
            }

            return [$parent, $entry];
        });

        // Cek kuota target pembebanan (dosen pembimbing saat aktif, mahasiswa 100 MB saat pending).
        $dosen = $ta->storageChargeTarget();
        // Alur anotasi-dulu: draf boleh disimpan tanpa tabel perbaikan agar
        // mahasiswa bisa upload -> anotasi di PDF -> tarik otomatis ke tabel.
        // Tabel tetap wajib lengkap saat kirim ke dosen (dijaga validasi).
        $riwayat = collect($data['riwayat_perbaikan'] ?? [])
            ->filter(fn ($row) => filled($row['halaman'] ?? null) || filled($row['komentar_dosen'] ?? null) || filled($row['perbaikan'] ?? null))
            ->values()->all();
        $storeLampiranRevisi = function () use ($request, $entry, $data, $riwayat) {
            $entry->update([
                'lampiran_path' => $this->storeUniqueFile($request->file('lampiran'), 'lampiran', $entry->id),
                'lampiran_original_name' => $request->file('lampiran')->getClientOriginalName(),
                'lampiran_size' => $request->file('lampiran')->getSize(),
                'riwayat_perbaikan' => $riwayat ?: null,
            ]);
        };

        if ($request->hasFile('lampiran')) {
            if ($dosen) {
                app(StorageUsageService::class)->withUploadLock($dosen, $request->file('lampiran')->getSize(), $storeLampiranRevisi);
            } else {
                $storeLampiranRevisi();
            }
        } else {
            // Tanpa upload baru (mis. refresh wizard lalu kirim): pertahankan
            // file draf yang ada, tetap simpan tabel perbaikan yang dikirim.
            $entry->update(['riwayat_perbaikan' => $riwayat ?: null]);
        }

        // Generate PDF catatan perbaikan otomatis dari tabel riwayat perbaikan
        // (dilewati bila draf anotasi-dulu belum punya tabel).
        if (! empty($entry->fresh()->riwayat_perbaikan)) {
            $this->generateCatatanPerbaikanPdf($entry);
        }

        // Komentar yang diberi status di langkah 1 dianggap dijawab mahasiswa.
        $commentIds = collect($data['addressed_comment_status'] ?? [])
            ->filter()
            ->keys()
            ->map(fn ($k) => (int) $k)
            ->values();
        if ($submit && $parent && $commentIds->isNotEmpty()) {
            $parent->comments()
                ->whereIn('id', $commentIds)
                ->update([
                    'resolution_status' => PdfComment::STATUS_ADDRESSED,
                    'is_resolved' => false,
                ]);
        }

        // Penerima revisi (pembimbing ATAU dosen penguji) + pembimbing sebagai CC.
        $recipient = $entry->reviewDosen();
        $recipientRole = $recipient ? $ta->dosenRoleLabel($recipient) : null;
        $recipientLabel = $recipient
            ? ($recipientRole ? $recipientRole.' — '.$recipient->name : $recipient->name)
            : 'dosen';

        if ($submit) {
            $this->bestEffort(fn () => EntryStatusChanged::dispatch($entry, 'Ada entri revisi baru menunggu review.'));
            $entry->notifyReviewers(
                $entry->reviewSubmissionMessage($recipientRole),
                route('logbook.show', $entry),
                'Entri Baru Menunggu Review',
            );
        }

        if ($submit) {
            return redirect()->route('logbook.show', $entry)
                ->with('success', 'Entri revisi dikirim ke '.$recipientLabel.'.');
        }

        // P4: draf mendarat di halaman Edit agar mahasiswa langsung lanjut
        // langkah 2 (buka PDF & tandai) -> langkah 3 (isi otomatis) tanpa putus.
        return redirect()->route('logbook.edit', $entry)
            ->with('success', 'Draf tersimpan. Buka PDF & tandai perbaikan, lalu isi otomatis ke kartu.');
    }

    // ---------------------------------------------------------------- index

    public function index(Request $request): View
    {
        $user = $request->user();
        $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', LogbookEntry::STATUSES)],
            'jenis' => ['nullable', 'in:logbook,revisi'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'mahasiswa_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'in:20,50,100'],
        ]);
        $filters = $request->only(['status', 'jenis', 'date_from', 'date_to', 'keyword', 'mahasiswa_id', 'per_page']);

        if ($user->isMahasiswa()) {
            $ta = ProgramContext::resolve($user, $request);
            $query = $ta
                ? $ta->entries()->with('comments')
                : LogbookEntry::query()->whereRaw('1 = 0');
        } elseif ($user->isDosen()) {
            // Semua entry dari TA yang dibimbing dosen ini.
            // TA di mana dosen adalah pembimbing ATAU penguji.
            $taIds = MahasiswaTa::where('pembimbing_1_id', $user->id)
                ->orWhere('pembimbing_2_id', $user->id)
                ->orWhere('penguji_1_id', $user->id)
                ->orWhere('penguji_2_id', $user->id)
                ->pluck('id');

            // TA dari dosen lain yang punya hubungan langsung (grup/TA bersama).
            $relatedDosenIds = $user->relatedDosenIds();
            $relatedTaIds = MahasiswaTa::where(function ($q) use ($relatedDosenIds) {
                $q->whereIn('pembimbing_1_id', $relatedDosenIds)
                    ->orWhereIn('pembimbing_2_id', $relatedDosenIds)
                    ->orWhereIn('penguji_1_id', $relatedDosenIds)
                    ->orWhereIn('penguji_2_id', $relatedDosenIds);
            })->pluck('id');

            $query = LogbookEntry::where(fn ($q) => $q->whereIn('mahasiswa_ta_id', $taIds)
                ->orWhereIn('mahasiswa_ta_id', $relatedTaIds)
                ->orWhere('dosen_id', $user->id))
                ->with(['mahasiswaTa.mahasiswa', 'dosen']);
        } else {
            $query = LogbookEntry::with(['mahasiswaTa.mahasiswa']);
        }

        // All aggregates and student options inherit the same visibility scope.
        $summary = (clone $query)->reorder()->selectRaw(
            'COUNT(*) as total, SUM(CASE WHEN jenis = ? THEN 1 ELSE 0 END) as logbook, '
            .'SUM(CASE WHEN jenis = ? THEN 1 ELSE 0 END) as revisi, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as revision_requested',
            [LogbookEntry::JENIS_LOGBOOK, LogbookEntry::JENIS_REVISI, LogbookEntry::STATUS_SUBMITTED, LogbookEntry::STATUS_REVISI]
        )->toBase()->first();
        $students = User::query()->whereIn('id', MahasiswaTa::query()
            ->select('user_id')->whereIn('id', (clone $query)->select('mahasiswa_ta_id')->toBase()))
            ->orderBy('name')->get(['id', 'name', 'nim']);

        // Date filters intentionally preserve the existing guidance-date semantics.
        $query->when($request->filled('status'), fn ($q) => $q->status($request->query('status')))
            ->when($request->filled('jenis'), fn ($q) => $q->jenis($request->query('jenis')))
            ->when($request->filled('mahasiswa_id'), fn ($q) => $q->whereHas('mahasiswaTa', fn ($ta) => $ta->where('user_id', $request->query('mahasiswa_id'))))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('tanggal_bimbingan', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('tanggal_bimbingan', '<=', $request->query('date_to')))
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $kw = $request->query('keyword');
                $q->where(function ($qq) use ($kw) {
                    $qq->where('topik', 'like', "%{$kw}%")
                        ->orWhere('progres_kendala', 'like', "%{$kw}%")
                        ->orWhereHas('mahasiswaTa.mahasiswa', fn ($m) => $m->where(fn ($identity) => $identity
                            ->where('name', 'like', "%{$kw}%")->orWhere('nim', 'like', "%{$kw}%")));
                });
            });

        if (! $user->isMahasiswa()) {
            $query->select(['id', 'mahasiswa_ta_id', 'dosen_id', 'jenis', 'sesi_ke', 'topik', 'status',
                'tanggal_bimbingan', 'tanggal_pengiriman', 'submitted_at', 'created_at']);
        }
        $entries = $query->with(['mahasiswaTa.mahasiswa.universities', 'dosen'])->withExists('revisionChildren')
            ->latest()->orderByDesc('id')->paginate((int) $request->query('per_page', 20))->withQueryString();

        // Banner daftar mahasiswa: revisi pending + satu aksi lanjutan.
        $pendingRevisions = $user->isMahasiswa() ? LogbookEntry::pendingRevisionsFor($ta ?? null) : collect();
        $pendingRevisionAction = $user->isMahasiswa() ? LogbookEntry::pendingRevisionActionFor($ta ?? null) : null;

        return view('logbook.index', compact('entries', 'filters', 'summary', 'students', 'pendingRevisions', 'pendingRevisionAction'));
    }

    // ---------------------------------------------------------------- feedback page

    /**
     * Halaman "Logbook Feedback": semua feedback dosen untuk mahasiswa ini.
     * Kolom: tanggal | topik | feedback | note (dapat diisi mahasiswa).
     */
    public function feedback(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isMahasiswa(), 403);

        $ta = ProgramContext::resolve($user, $request);
        abort_unless($ta, 403, 'Anda belum memiliki program aktif (TA/KP).');

        // Muat seluruh entri program sekaligus (tanpa konten PDF — hanya path)
        // agar rantai parent -> revision dapat dibangun di memori tanpa N+1.
        $all = $ta->entries()
            ->with(['mahasiswaTa', 'dosen', 'actionItems', 'comments.user'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $byId = $all->keyBy('id');
        $childrenMap = [];
        foreach ($all as $e) {
            if ($e->parent_entry_id) {
                $childrenMap[$e->parent_entry_id][] = $e;
            }
        }

        // Thread = seluruh rantai yang berakar pada ancestor teratas
        // (relasi parent_entry_id yang sudah ada — bukan tebakan).
        $rootOf = function (LogbookEntry $e) use ($byId): LogbookEntry {
            $seen = [];
            $cur = $e;
            while ($cur->parent_entry_id && isset($byId[$cur->parent_entry_id]) && ! isset($seen[$cur->id])) {
                $seen[$cur->id] = true;
                $cur = $byId[$cur->parent_entry_id];
            }

            return $cur;
        };

        $groups = [];
        foreach ($all as $e) {
            $groups[$rootOf($e)->id][] = $e;
        }

        $activeChildStatuses = [
            LogbookEntry::STATUS_DRAFT,
            LogbookEntry::STATUS_SUBMITTED,
            LogbookEntry::STATUS_REVISI,
            LogbookEntry::STATUS_REVISION_IN_PROGRESS,
        ];

        $threads = [];
        foreach ($groups as $nodes) {
            $nodes = collect($nodes)
                ->sortBy(fn (LogbookEntry $e) => [($e->revision_round ?? 0), $e->created_at?->timestamp ?? 0, $e->id])
                ->values();

            // Halaman ini hanya menampilkan rantai yang punya umpan balik dosen.
            if (! $nodes->contains(fn (LogbookEntry $e) => filled($e->feedback_dosen))) {
                continue;
            }

            $isEditable = function (LogbookEntry $e) use ($childrenMap): bool {
                if (! empty($childrenMap[$e->id])) {
                    return false; // terkunci: sudah punya revisi anak
                }

                return $e->status === LogbookEntry::STATUS_DRAFT
                    || ($e->status === LogbookEntry::STATUS_REVISION_IN_PROGRESS && $e->submitted_at === null);
            };

            $canCreateRevision = function (LogbookEntry $e) use ($childrenMap, $activeChildStatuses): bool {
                if ($e->status !== LogbookEntry::STATUS_REVISI) {
                    return false;
                }
                foreach ($childrenMap[$e->id] ?? [] as $child) {
                    if (in_array($child->status, $activeChildStatuses, true)) {
                        return false;
                    }
                }

                return true;
            };

            $root = $nodes->first();
            $feedbacks = $nodes->filter(fn (LogbookEntry $e) => filled($e->feedback_dosen))->values();
            $revisions = $nodes->where('jenis', LogbookEntry::JENIS_REVISI)->values();

            // Aktivitas terakhir di seluruh rantai menentukan status & urutan thread.
            $latestEntry = $root;
            $latestAt = null;
            foreach ($nodes as $e) {
                foreach (['reviewed_at', 'submitted_at', 'created_at'] as $field) {
                    $t = $e->{$field};
                    // >= (bukan >): node diproses dari terlama ke terbaru,
                    // sehingga timestamp yang seri dimenangkan node terbaru.
                    if ($t && ($latestAt === null || ! $t->lt($latestAt))) {
                        $latestAt = $t;
                        $latestEntry = $e;
                    }
                }
            }

            // Satu aksi primer: draf yang bisa dilanjutkan > revisi baru >
            // menunggu dosen > selesai.
            $action = null;
            $actionInfo = null;
            $editable = $nodes->filter($isEditable)->last();
            if ($editable) {
                $action = ['label' => 'Lanjutkan Revisi', 'url' => route('logbook.edit', $editable)];
            } else {
                $revisionParent = $nodes->filter($canCreateRevision)->last();
                if ($revisionParent) {
                    $action = [
                        'label' => 'Buat Revisi',
                        'url' => route('logbook.create-revisi', [
                            'parent_entry_id' => $revisionParent->id,
                            'program' => $revisionParent->mahasiswaTa?->jenis,
                        ]),
                    ];
                } elseif (in_array($latestEntry->status, [LogbookEntry::STATUS_SUBMITTED, LogbookEntry::STATUS_REVISION_IN_PROGRESS], true)
                    && $latestEntry->submitted_at) {
                    $actionInfo = 'Menunggu review dosen';
                } elseif ($latestEntry->status === LogbookEntry::STATUS_APPROVED) {
                    $actionInfo = 'Selesai';
                }
            }

            $firstFeedback = $feedbacks->first();
            $reviewer = $firstFeedback?->dosen;

            $threads[] = [
                'key' => 'thread-'.$root->id,
                'root' => $root,
                'nodes' => $nodes,
                'title' => $root->topik ?: 'Rangkaian Revisi',
                'reviewerName' => $reviewer?->name,
                'reviewerRole' => $reviewer ? $ta->dosenRoleLabel($reviewer) : null,
                'firstFeedbackAt' => $firstFeedback?->reviewed_at,
                'feedbackCount' => $feedbacks->count(),
                'revisionCount' => $revisions->count(),
                'latestEntry' => $latestEntry,
                'latestAt' => $latestAt,
                'action' => $action,
                'actionInfo' => $actionInfo,
                'actionable' => $action !== null,
                'childrenMap' => $childrenMap,
            ];
        }

        $threads = collect($threads)
            ->sortByDesc(fn (array $t) => [$t['actionable'] ? 1 : 0, $t['latestAt']?->timestamp ?? 0])
            ->values();

        $summary = [
            'total' => $threads->count(),
            'menunggu' => $threads->where(fn (array $t) => $t['latestEntry']->status === LogbookEntry::STATUS_SUBMITTED)->count(),
            'diminta' => $threads->where(fn (array $t) => $t['latestEntry']->status === LogbookEntry::STATUS_REVISI)->count(),
            'selesai' => $threads->where(fn (array $t) => $t['latestEntry']->status === LogbookEntry::STATUS_APPROVED && ! $t['actionable'])->count(),
        ];

        return view('logbook.feedback', compact('threads', 'summary', 'ta'));
    }

    /**
     * Simpan catatan (note) mahasiswa untuk feedback dosen tertentu.
     * Pemilik TA dapat mengisi/mengubah note kapan saja (tidak terbatas
     * oleh status entri draft/revisi).
     */
    public function updateFeedbackNote(Request $request, LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('owner', $logbook);

        $validated = $request->validate([
            'feedback_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $logbook->update(['feedback_note' => $validated['feedback_note'] ?? null]);

        return back()->with('success', 'Catatan feedback berhasil disimpan.');
    }

    // ---------------------------------------------------------------- show

    public function show(Request $request, LogbookEntry $logbook): View
    {
        $this->authorize('view', $logbook);
        $logbook->load([
            'mahasiswaTa.mahasiswa', 'mahasiswaTa.pembimbing1', 'mahasiswaTa.pembimbing2', 'dosen', 'comments.user',
            'parentEntry.comments.user', 'parentEntry.parentEntry', 'revisionChildren',
            'actionItems',
        ]);

        $draftPdf = $logbook->lampiran_path ? Storage::disk('local')->path($logbook->lampiran_path) : null;
        $catatanPdf = $logbook->catatan_perbaikan_path ? Storage::disk('local')->path($logbook->catatan_perbaikan_path) : null;

        // Fitur keputusan review (template + pakai-ulang) disamakan dengan quick-review.
        $templates = FeedbackTemplate::where('user_id', $request->user()->id)->get();
        $lastFeedback = LogbookEntry::where('mahasiswa_ta_id', $logbook->mahasiswa_ta_id)
            ->whereNotNull('feedback_dosen')
            ->orderByDesc('id')
            ->value('feedback_dosen');

        // Konteks untuk reviewer: bila program masih punya revisi pending di
        // thread lain, dosen diberi tahu agar tidak mereview duplikat —
        // arahkan mahasiswa menjawab lewat jalur revisi.
        $otherPendingRevisions = LogbookEntry::pendingRevisionsFor($logbook->mahasiswaTa)
            ->reject(fn (LogbookEntry $e) => $e->id === $logbook->id || $e->parent_entry_id === $logbook->id)
            ->values();

        return view('logbook.show', compact('logbook', 'draftPdf', 'catatanPdf', 'templates', 'lastFeedback', 'otherPendingRevisions'));
    }

    // ---------------------------------------------------------------- edit

    public function edit(Request $request, LogbookEntry $logbook): View|RedirectResponse
    {
        $this->authorize('update', $logbook);

        // Satu pintu editor: draf revisi dilanjutkan lewat wizard create-revisi
        // (?draft_id=) agar layout & perilakunya sama; draf logbook biasa
        // tetap memakai halaman edit.
        if ($logbook->jenis === LogbookEntry::JENIS_REVISI) {
            return redirect()->route('logbook.create-revisi', array_filter([
                'draft_id' => $logbook->id,
                'program' => $logbook->mahasiswaTa?->jenis,
            ]));
        }

        return view('logbook.edit', compact('logbook'));
    }

    public function update(UpdateLogbookEntryRequest $request, LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('update', $logbook);

        $data = $request->validated();
        $resolvedCount = 0;

        if ($request->hasFile('lampiran')) {
            $oldPath = $logbook->lampiran_path;
            $newPath = $this->storeUniqueFile($request->file('lampiran'), 'lampiran', $logbook->id);

            $logbook->update([
                'lampiran_path' => $newPath,
                'lampiran_original_name' => $request->file('lampiran')->getClientOriginalName(),
                'lampiran_size' => $request->file('lampiran')->getSize(),
            ]);

            // File lama DI-ORPHAN (tidak dihapus). Auto-resolve komentar pada
            // file_type yang diganti (komentar kontekstual terhadap versi file).
            $resolvedCount = $this->resolveCommentsForType($logbook, PdfComment::FILE_TYPE_DRAFT);
            $this->logAttachmentChange($logbook, 'lampiran_path', $oldPath, $newPath, $resolvedCount);
        }

        if ($logbook->jenis === LogbookEntry::JENIS_REVISI) {
            // Alur anotasi-dulu: baris kosong tidak disimpan; catatan PDF hanya
            // dibuat ulang bila tabel punya isi (submit menjaga kelengkapan).
            $riwayat = collect($data['riwayat_perbaikan'] ?? [])
                ->filter(fn ($row) => filled($row['halaman'] ?? null) || filled($row['komentar_dosen'] ?? null) || filled($row['perbaikan'] ?? null))
                ->values()->all();
            $logbook->update([
                'tanggal_pengiriman' => $data['tanggal_pengiriman'],
                'progres_kendala' => $data['progres_kendala'] ?? null,
                'riwayat_perbaikan' => $riwayat ?: null,
            ]);

            // Generate ulang PDF catatan perbaikan dari tabel.
            if (! empty($logbook->fresh()->riwayat_perbaikan)) {
                $this->generateCatatanPerbaikanPdf($logbook);
            }
        } else {
            $logbook->update([
                'tanggal_bimbingan' => $data['tanggal_bimbingan'],
                'topik' => $data['topik'],
                'progres_kendala' => $data['progres_kendala'] ?? null,
            ]);
        }

        return redirect()->route('logbook.show', $logbook)
            ->with('success', $resolvedCount > 0
                ? "Entri berhasil diperbarui. {$resolvedCount} komentar PDF di-resolve otomatis karena file diganti."
                : 'Entri berhasil diperbarui.');
    }

    /**
     * Hapus lampiran (hanya status draft/revisi). File lama di-orphan.
     */
    public function removeLampiran(Request $request, LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('update', $logbook);

        if ($logbook->jenis === LogbookEntry::JENIS_REVISI) {
            return back()->with('error', 'Entri revisi wajib memiliki file perbaikan. Gunakan "Ganti" untuk mengganti file.');
        }

        $oldPath = $logbook->lampiran_path;
        $logbook->update(['lampiran_path' => null, 'lampiran_original_name' => null, 'lampiran_size' => null]);
        $resolvedCount = $this->resolveCommentsForType($logbook, PdfComment::FILE_TYPE_DRAFT);
        $this->logAttachmentChange($logbook, 'lampiran_path', $oldPath, null, $resolvedCount);

        return back()->with('success', 'Lampiran dihapus.');
    }

    /**
     * Hapus catatan perbaikan (hanya status draft/revisi). File lama di-orphan.
     */
    public function removeCatatan(Request $request, LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('update', $logbook);

        if ($logbook->jenis === LogbookEntry::JENIS_REVISI) {
            return back()->with('error', 'Entri revisi wajib memiliki catatan perbaikan.');
        }

        $oldPath = $logbook->catatan_perbaikan_path;
        $logbook->update(['catatan_perbaikan_path' => null, 'catatan_original_name' => null, 'catatan_perbaikan_size' => null]);
        $resolvedCount = $this->resolveCommentsForType($logbook, PdfComment::FILE_TYPE_CATATAN);
        $this->logAttachmentChange($logbook, 'catatan_perbaikan_path', $oldPath, null, $resolvedCount);

        return back()->with('success', 'Catatan perbaikan dihapus.');
    }

    /**
     * Hapus entri logbook — hanya pemilik & hanya saat masih dapat diedit
     * (draf / revisi sedang dikerjakan). Komentar PDF & action items
     * terhapus otomatis (cascade), file dihapus dari penyimpanan lokal.
     */
    public function destroy(LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('delete', $logbook);
        abort_unless($logbook->isEditable(), 403, 'Hanya entri yang masih berupa draf yang dapat dihapus.');

        // Lepas relasi anak revisi agar FK tidak menghalangi penghapusan.
        $logbook->revisionChildren()->update(['parent_entry_id' => null]);

        // Hapus file dari penyimpanan lokal.
        foreach (['lampiran_path', 'catatan_perbaikan_path'] as $field) {
            if ($logbook->{$field}) {
                Storage::disk('local')->delete($logbook->{$field});
            }
        }

        $parent = $logbook->parentEntry;
        $logbook->delete();

        // Bila ini revisi aktif terakhir milik induk, kembalikan induk yatim
        // ke "revisi" agar tidak menggantung tanpa aksi (kasus entri #25).
        $this->restoreStrandedParent($parent);

        return redirect()->route('logbook.index')->with('success', 'Entri logbook berhasil dihapus.');
    }

    /**
     * Hapus massal entri logbook — hanya pemilik & hanya entri yang masih
     * dapat diedit (isEditable: draf / revisi sedang dikerjakan yang belum
     * dikirim). Entri yang tidak memenuhi syarat dilewati (tidak 403) agar
     * satu pilihan yang kedaluwarsa tidak menggagalkan seluruh aksi.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:logbook_entries,id'],
        ]);

        $entries = LogbookEntry::whereIn('id', $validated['ids'])
            ->with('mahasiswaTa')
            ->withExists('revisionChildren')
            ->get();

        $user = $request->user();
        $deleted = 0;
        $skipped = 0;

        DB::transaction(function () use ($entries, $user, &$deleted, &$skipped) {
            $parentIds = [];
            foreach ($entries as $entry) {
                if (! $user->can('delete', $entry) || ! $entry->isEditable()) {
                    $skipped++;
                    continue;
                }

                // Lepas relasi anak revisi agar FK tidak menghalangi penghapusan.
                $entry->revisionChildren()->update(['parent_entry_id' => null]);

                // Hapus file dari penyimpanan lokal.
                foreach (['lampiran_path', 'catatan_perbaikan_path'] as $field) {
                    if ($entry->{$field}) {
                        Storage::disk('local')->delete($entry->{$field});
                    }
                }

                if ($entry->parent_entry_id) {
                    $parentIds[] = $entry->parent_entry_id;
                }
                $entry->delete();
                $deleted++;
            }

            // Kembalikan induk yatim ke "revisi" (lihat destroy()).
            foreach (array_unique($parentIds) as $parentId) {
                $this->restoreStrandedParent(LogbookEntry::find($parentId));
            }
        });

        if ($deleted === 0) {
            return back()->with('error', 'Tidak ada entri yang dihapus. Hanya draf / revisi sedang dikerjakan yang bisa dihapus.');
        }

        $message = $deleted.' entri berhasil dihapus.';
        if ($skipped > 0) {
            $message .= ' '.$skipped.' entri dilewati (sudah tidak bisa dihapus).';
        }

        return back()->with('success', $message);
    }

    /**
     * Anak revisi aktif terbaru milik induk (draf / menunggu review / diminta
     * revisi / sedang dikerjakan). Dipakai agar penolakan "revisi aktif" bisa
     * menunjuk draf mana yang dimaksud, bukan sekadar pesan umum.
     */
    private function activeRevisionChild(LogbookEntry $parent): ?LogbookEntry
    {
        return $parent->revisionChildren()
            ->whereIn('status', [LogbookEntry::STATUS_DRAFT, LogbookEntry::STATUS_SUBMITTED, LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
            ->latest('id')
            ->first();
    }

    /**
     * Payload draf aktif untuk kotak aksi di halaman create-revisi
     * (link "Lanjutkan draf" + info status/tanggal).
     */
    private function activeRevisionPayload(LogbookEntry $child): array
    {
        return [
            'id' => $child->id,
            'parent_id' => $child->parent_entry_id,
            'status' => $child->status,
            'status_label' => LogbookEntry::STATUS_LABELS[$child->status] ?? $child->status,
            'tanggal' => $child->tanggal_tampil?->format('d M Y') ?? '—',
            'edit_url' => route('logbook.edit', $child),
            'editable' => $child->isEditable(),
        ];
    }

    /**
     * Pesan penolakan yang menyebut draf aktifnya (id + status) agar
     * mahasiswa tahu harus melanjutkan draf mana.
     */
    private function activeRevisionMessage(LogbookEntry $parent, ?LogbookEntry $activeChild = null): string
    {
        $activeChild ??= $this->activeRevisionChild($parent);

        if (! $activeChild) {
            return 'Entri induk sudah memiliki revisi aktif. Pilih entri induk lain.';
        }

        $label = LogbookEntry::STATUS_LABELS[$activeChild->status] ?? $activeChild->status;

        return 'Entri induk sudah memiliki revisi aktif (draf #'.$activeChild->id.', '.$label.'). Lanjutkan draf tersebut, bukan membuat baru.';
    }

    /**
     * Kembalikan induk yatim ke "Revisi Diminta" bila revisi aktif terakhirnya
     * dihapus: induk revision_in_progress tanpa anak aktif dan tidak editable
     * tidak bisa diapa-apakan (mati permanen). Tanpa suara (tanpa notifikasi).
     */
    private function restoreStrandedParent(?LogbookEntry $parent): void
    {
        $parent = $parent?->fresh();
        if (! $parent || $parent->status !== LogbookEntry::STATUS_REVISION_IN_PROGRESS) {
            return;
        }
        if ($parent->isEditable()) {
            return;
        }
        $hasActive = $parent->revisionChildren()
            ->whereIn('status', [LogbookEntry::STATUS_DRAFT, LogbookEntry::STATUS_SUBMITTED, LogbookEntry::STATUS_REVISI, LogbookEntry::STATUS_REVISION_IN_PROGRESS])
            ->exists();
        if (! $hasActive) {
            $parent->update(['status' => LogbookEntry::STATUS_REVISI]);
        }
    }

    /**
     * Simpan file ke path unik {dir}/{entry_id}/{uuid}.ext (anti tabrakan nama).
     */
    private function storeUniqueFile($file, string $dir, ?int $entryId): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'pdf';
        $id = $entryId ?: uniqid('e', false);
        $name = $id.'/'.(string) Str::uuid().'.'.$ext;

        return $file->storeAs($dir.'/'.$id, basename($name), 'local');
    }

    /**
     * Generate PDF catatan perbaikan otomatis dari tabel riwayat_perbaikan.
     * PDF disimpan ke catatan_perbaikan_path (agar tampil di tab "Catatan"
     * PDF viewer & bisa diunduh). File lama di-orphan (tidak dihapus).
     */
    private function generateCatatanPerbaikanPdf(LogbookEntry $logbook): void
    {
        if (empty($logbook->riwayat_perbaikan)) {
            return;
        }

        try {
            $pdf = Pdf::loadView('exports.catatan-perbaikan', [
                'logbook' => $logbook,
                'riwayat' => $logbook->riwayat_perbaikan,
                'pesan' => $logbook->progres_kendala,
            ]);

            $output = $pdf->output();
            $path = 'catatan/'.$logbook->id.'/'.(string) Str::uuid().'.pdf';
            Storage::disk('local')->put($path, $output);

            $logbook->update([
                'catatan_perbaikan_path' => $path,
                'catatan_original_name' => 'catatan-perbaikan-'.$logbook->id.'.pdf',
                'catatan_perbaikan_size' => strlen($output),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Auto-resolve semua komentar PDF untuk file_type tertentu.
     * Mengembalikan jumlah yang di-resolve.
     */
    private function resolveCommentsForType(LogbookEntry $logbook, string $fileType): int
    {
        $count = $logbook->comments()
            ->fileType($fileType)
            ->whereIn('resolution_status', [PdfComment::STATUS_OPEN, PdfComment::STATUS_ADDRESSED])
            ->update([
                'resolution_status' => PdfComment::STATUS_RESOLVED,
                'is_resolved' => true,
            ]);

        return (int) $count;
    }

    /**
     * Catat pergantian lampiran/catatan di audit channel.
     */
    private function logAttachmentChange(LogbookEntry $logbook, string $field, ?string $old, ?string $new, int $resolved): void
    {
        Log::channel('audit')->info('Attachment replaced', [
            'entry_id' => $logbook->id,
            'field' => $field,
            'old' => $old ? basename($old) : null,
            'new' => $new ? basename($new) : null,
            'by' => auth()->id(),
            'comments_auto_resolved' => $resolved,
            'waktu' => now()->toDateTimeString(),
        ]);
    }

    // ---------------------------------------------------------------- workflow

    public function submit(LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('submit', $logbook);

        // Alur anotasi-dulu: revisi yang drafnya kosong belum boleh dikirim;
        // tabel harus diisi dulu (manual atau tarik dari anotasi di halaman Edit).
        if ($logbook->jenis === LogbookEntry::JENIS_REVISI) {
            if ($logbook->completePerbaikanRows()->isEmpty()) {
                return back()->with('error', 'Lengkapi tabel perbaikan terlebih dahulu (Isi otomatis dari anotasi di halaman Edit, lalu lengkapi kartu bertanda Perlu dilengkapi) sebelum mengirim ke dosen.');
            }
        }

        // Hanya program aktif yang bisa submit.
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        $logbook->update([
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        // Jika ini revisi yang dikirim, parent kembali ke "Menunggu review".
        if ($logbook->jenis === LogbookEntry::JENIS_REVISI && $logbook->parentEntry) {
            $logbook->parentEntry->update(['status' => LogbookEntry::STATUS_SUBMITTED]);
        }

        $this->bestEffort(fn () => EntryStatusChanged::dispatch($logbook, 'Ada entri baru menunggu review.'));
        $logbook->notifyReviewers(
            $logbook->reviewSubmissionMessage(),
            route('logbook.show', $logbook),
            'Entri Baru Menunggu Review',
        );

        return back()->with('success', 'Entri dikirim ke dosen.');
    }

    public function approve(Request $request, LogbookEntry $logbook, LogbookReviewTransition $transition): RedirectResponse
    {
        $this->authorize('review', $logbook);

        // Hanya program aktif yang bisa di-review.
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        $validated = $request->validate(['feedback_dosen' => ['nullable', 'string', 'max:5000']]);

        $transition->apply($logbook, LogbookEntry::STATUS_APPROVED, [
            'feedback_dosen' => $validated['feedback_dosen'] ?? null,
            'reviewed_at' => now(),
        ]);
        $this->resolveCommentsOnApproval($logbook);

        // Reviewer bisa pembimbing atau dosen penguji (penerima revisi), jadi
        // pesan notifikasi tidak menyebut peran tertentu.
        $reviewerName = auth()->user()?->name;
        $this->bestEffort(fn () => EntryStatusChanged::dispatch($logbook, 'Entri Anda telah disetujui'.($reviewerName ? ' oleh '.$reviewerName : ' oleh dosen').'.'));
        $logbook->notifyParties(
            'Entri '.($logbook->jenis === 'revisi' ? 'revisi' : 'logbook sesi '.$logbook->sesi_ke).' telah disetujui.',
            route('logbook.show', $logbook),
            'Entri Disetujui',
        );

        // Evaluasi achievement mahasiswa.
        if ($owner = $logbook->mahasiswaTa?->mahasiswa) {
            app(AchievementService::class)->evaluateForUser($owner);
        }

        return back()->with('success', 'Entri disetujui.');
    }

    public function requestRevisi(Request $request, LogbookEntry $logbook, LogbookReviewTransition $transition): RedirectResponse
    {
        $this->authorize('review', $logbook);

        // Hanya program aktif yang bisa di-review.
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        $validated = $request->validate([
            'feedback_dosen' => ['required', 'string', 'min:20'],
        ]);

        $transition->apply($logbook, LogbookEntry::STATUS_REVISI, [
            'feedback_dosen' => $validated['feedback_dosen'],
            'reviewed_at' => now(),
        ]);

        $this->bestEffort(fn () => EntryStatusChanged::dispatch($logbook, 'Entri Anda diminta revisi: '.$validated['feedback_dosen']));
        $logbook->notifyParties(
            'Entri Anda diminta revisi: '.$validated['feedback_dosen'],
            route('logbook.show', $logbook),
            'Permintaan Revisi',
        );

        return back()->with('success', 'Entri dikembalikan untuk revisi.');
    }

    public function archive(Request $request, LogbookEntry $logbook, ArchiveLogbookReview $archive): RedirectResponse
    {
        $this->authorize('review', $logbook);
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        $validated = $request->validate(['archive_reason' => ['nullable', 'string', 'max:5000']]);
        $archive->archive($logbook, $request->user(), $validated['archive_reason'] ?? null);

        return back()->with('success', 'Entri diarsipkan.');
    }

    /**
     * Batalkan persetujuan: approved → submitted agar masuk antrean review lagi.
     */
    public function reopen(LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('reopen', $logbook);

        // Hanya program aktif yang bisa dibuka kembali.
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        abort_unless($logbook->status === LogbookEntry::STATUS_APPROVED, 422, 'Hanya entri yang sudah disetujui yang bisa dibuka kembali.');

        $logbook->update([
            'status' => LogbookEntry::STATUS_SUBMITTED,
            'reviewed_at' => null,
        ]);

        $this->bestEffort(fn () => EntryStatusChanged::dispatch($logbook, 'Persetujuan entri Anda dibatalkan dosen, kini kembali menunggu review.'));
        $logbook->notifyParties(
            'Persetujuan entri '.($logbook->jenis === 'revisi' ? 'revisi' : 'logbook sesi '.$logbook->sesi_ke).' dibatalkan dosen. Entri kembali menunggu review.',
            route('logbook.show', $logbook),
            'Persetujuan Dibatalkan',
        );

        return back()->with('success', 'Persetujuan dibatalkan. Entri kembali menunggu review.');
    }

    /**
     * Minta revisi lagi pada entri yang sudah disetujui: approved → revisi.
     */
    public function reopenRevisi(Request $request, LogbookEntry $logbook): RedirectResponse
    {
        $this->authorize('reopen', $logbook);

        // Hanya program aktif yang bisa dibuka kembali.
        abort_unless(in_array($logbook->mahasiswaTa?->status_ta, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL], true), 403, 'Program belum aktif atau ditolak.');

        abort_unless($logbook->status === LogbookEntry::STATUS_APPROVED, 422, 'Hanya entri yang sudah disetujui yang bisa diminta revisi kembali.');

        $validated = $request->validate([
            'feedback_dosen' => ['required', 'string', 'min:20'],
        ]);

        $logbook->update([
            'status' => LogbookEntry::STATUS_REVISI,
            'feedback_dosen' => $validated['feedback_dosen'],
            'reviewed_at' => now(),
        ]);

        $this->bestEffort(fn () => EntryStatusChanged::dispatch($logbook, 'Entri yang sudah disetujui diminta revisi kembali: '.$validated['feedback_dosen']));
        $logbook->notifyParties(
            'Entri yang sudah disetujui diminta revisi kembali: '.$validated['feedback_dosen'],
            route('logbook.show', $logbook),
            'Permintaan Revisi Kembali',
        );

        return back()->with('success', 'Entri dibuka kembali dan dikembalikan untuk revisi.');
    }

    // ---------------------------------------------------------------- pdf serve

    public function pdf(LogbookEntry $logbook)
    {
        $this->authorize('view', $logbook);
        abort_if(! $logbook->lampiran_path, 404, 'File perbaikan/draft tidak tersedia.');

        return $this->inlinePdf($logbook->lampiran_path);
    }

    public function catatanPdf(LogbookEntry $logbook)
    {
        $this->authorize('view', $logbook);
        abort_if(! $logbook->catatan_perbaikan_path, 404, 'Catatan perbaikan tidak tersedia.');

        return $this->inlinePdf($logbook->catatan_perbaikan_path);
    }

    /**
     * (Opsional) Unduh PDF dengan anotasi DIBAAKAR ke dalam file (bukan overlay DOM).
     * Menggunakan FPDI: mengimpor halaman asli lalu menggambar kotak + nomor
     * komentar berdasarkan geometri Web Annotation yang tersimpan.
     */
    public function burnPdf(Request $request, LogbookEntry $logbook)
    {
        $this->authorize('view', $logbook);

        $type = $request->query('type', PdfComment::FILE_TYPE_DRAFT);
        $field = $type === PdfComment::FILE_TYPE_CATATAN ? 'catatan_perbaikan_path' : 'lampiran_path';
        abort_if(! $logbook->{$field}, 404, 'File PDF tidak tersedia.');

        $source = Storage::disk('local')->path($logbook->{$field});
        abort_unless(is_file($source), 404, 'File PDF tidak ditemukan.');

        $comments = $logbook->comments()->fileType($type)->with('user')->orderBy('page_number')->get();

        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($source);

        // Halaman daftar / legend komentar ditempatkan DI AWAL dokumen,
        // sebelum halaman-halaman asli. appendCommentList mengembalikan map
        // [comment_id => link] yang nanti diarahkan ke anotasi tujuan.
        $links = $this->appendCommentList($pdf, $comments, $type);
        $target = [];

        foreach (range(1, $pageCount) as $pageNo) {
            $pdf->AddPage();
            $tplId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tplId);
            $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);
            $currentPage = $pdf->PageNo(); // nomor halaman output (termasuk blok daftar di depan)

            $pageComments = $comments->where('page_number', $pageNo);
            $i = 0;
            foreach ($pageComments as $c) {
                if (! $c->isArea()) {
                    continue;
                }
                $i++;
                $x1 = $c->pos_x * $size['width'];
                $yTop = $c->pos_y * $size['height'];   // normalized top-origin
                $x2 = $c->x2 * $size['width'];
                $yBottom = $c->y2 * $size['height'];
                $w = $x2 - $x1;
                $h = $yBottom - $yTop;

                // Target navigasi untuk link "klik baris daftar → lompat ke anotasi":
                // halaman output + koordinat y (FPDF top-origin) di tengah kotak.
                $target[$c->id] = ['page' => $currentPage, 'y' => $yTop + ($h * 0.5)];

                // Konversi ke koordinat PDF (origin bottom-left).
                $pdfY1 = $size['height'] - $yTop - $h;

                // Warna outline: merah untuk anotasi (tanpa isian agar tulisan tidak tertutup).
                // Tetap menampilkan label nomor + nama + isi komentar di atas area.
                $color = $c->isResolved() ? [16, 185, 129] : ($c->resolution_status === PdfComment::STATUS_ADDRESSED ? [217, 119, 6] : [220, 38, 38]);
                [$r, $g, $b] = $color;
                $pdf->SetDrawColor($r, $g, $b);
                $pdf->SetFillColor($r, $g, $b);
                $pdf->SetLineWidth(0.6);
                // 'D' = Draw outline only (tidak mengisi), agar tulisan PDF di dalamnya tetap terlihat.
                $pdf->Rect($x1, $pdfY1, $w, $h, 'D');

                // Label nomor komentar di pojok atas kotak (background solid).
                $pdf->SetFillColor($r, $g, $b);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Helvetica', 'B', 8);
                $labelY = $pdfY1 + $h;
                $pdf->Rect($x1, $labelY, 10, 6, 'F');
                $pdf->Text($x1 + 1, $labelY + 4.5, (string) $i);

                // Teks komentar ditaruh di LUAR kotak (di atasnya) dengan background putih
                // semi-transparan, sehingga tidak menutupi tulisan PDF di dalam area.
                $name = trim((string) ($c->user?->name ?? ''));
                $text = trim((string) $c->comment);
                $lineH = 3.2;

                // Tentukan posisi: tepat di atas kotak anotasi.
                // Lebar area teks = lebar area anotasi, tetapi maksimal 120 pt.
                $textW = min(120, max(40, $w));
                $tx = $x1;
                // Posisi Y (PDF, origin bottom-left): di atas label nomor.
                $ty = $labelY + 2;

                $pdf->SetFont('Helvetica', 'B', 7);
                $pdf->SetFontSize(7);
                $pdf->SetTextColor($r, $g, $b);
                $lines = [];
                if ($name !== '') {
                    $lines[] = mb_substr($name, 0, 40);
                }
                if ($text !== '') {
                    $wrapped = $this->wrapPdfText($text, $textW, function ($s) use ($pdf) {
                        return $pdf->GetStringWidth($s);
                    });
                    foreach ($wrapped as $ln) {
                        $lines[] = $ln;
                        if (count($lines) >= 3) {
                            break;
                        }
                    }
                }
                foreach ($lines as $idx => $ln) {
                    $pdf->Text($tx, $ty + ($idx * $lineH), $ln);
                }
            }
        }

        // Arahkan setiap link pada halaman daftar ke anotasi tujuan secara
        // internal (klik baris → lompat ke halaman & posisi anotasinya).
        foreach ($links as $cid => $link) {
            if (isset($target[$cid])) {
                $pdf->SetLink($link, $target[$cid]['y'], $target[$cid]['page']);
            }
        }

        $filename = 'anotasi-'.$logbook->mahasiswaTa?->mahasiswa?->nim.'-'.now()->format('Ymd').'.pdf';

        return response($pdf->Output('S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Tambah halaman daftar/legend seluruh komentar area (DI AWAL dokumen,
     * sebelum halaman-halaman asli). Setiap baris diberi link internal agar
     * bisa diklik untuk lompat ke anotasinya di halaman asli. Mengembalikan
     * map [comment_id => link] yang diarahkan burnPdf() via SetLink.
     */
    private function appendCommentList(Fpdi $pdf, $comments, string $type): array
    {
        $only = $comments->filter(fn ($c) => $c->isArea())->values();

        $pdf->AddPage();
        $pdf->SetY(20);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 10, 'Daftar Anotasi ('.($type === PdfComment::FILE_TYPE_CATATAN ? 'Catatan Perbaikan' : 'File Perbaikan/Draft').')', 0, 1, 'C');

        $pdf->Ln(4);
        // Legend warna
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 7, 'Legenda:', 0, 1);
        $this->legendRow($pdf, [220, 38, 38], 'Area anotasi (outline merah)');
        $this->legendRow($pdf, [16, 185, 129], 'Sudah selesai (resolve)');

        $pdf->Ln(6);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetFillColor(226, 232, 240);
        $pdf->Cell(12, 8, 'No', 1, 0, 'C', true);
        $pdf->Cell(18, 8, 'Hal', 1, 0, 'C', true);
        $pdf->Cell(55, 8, 'Pemberi', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(0, 8, 'Komentar', 1, 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetFillColor(255, 255, 255);
        $row = 0;
        $links = [];
        foreach ($only as $idx => $c) {
            $num = $idx + 1;
            $status = $c->isResolved() ? 'Selesai' : ($c->resolution_status === PdfComment::STATUS_ADDRESSED ? 'Dijawab' : 'Terbuka');
            $statusColor = $c->isResolved() ? [16, 185, 129] : ($c->resolution_status === PdfComment::STATUS_ADDRESSED ? [217, 119, 6] : [245, 158, 11]);
            $name = trim((string) ($c->user?->name ?? '-'));
            $text = trim((string) $c->comment);

            // Buat link internal per baris; destinasi (halaman + posisi) diatur
            // nanti di burnPdf() via SetLink setelah semua halaman dirender.
            $link = $pdf->AddLink();
            $links[$c->id] = $link;

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFillColor(255, 255, 255);
            if ($row % 2 === 1) {
                $pdf->SetFillColor(248, 250, 252);
            }
            $pdf->Cell(12, 8, (string) $num, 1, 0, 'C', true);
            $pdf->Cell(18, 8, (string) $c->page_number, 1, 0, 'C', true);
            $pdf->Cell(55, 8, mb_substr($name, 0, 30), 1, 0, 'L', true);

            // status cell dengan warna
            $pdf->SetTextColor($statusColor[0], $statusColor[1], $statusColor[2]);
            $pdf->Cell(20, 8, $status, 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);

            // komentar (multi-line, tinggi menyesuaikan)
            $lines = $this->wrapPdfText($text, 350, function ($s) use ($pdf) {
                return $pdf->GetStringWidth($s);
            });
            $lineH = 4.5;
            $rowH = max(8, count($lines) * $lineH);
            $x0 = $pdf->GetX();
            $y0 = $pdf->GetY();
            $pdf->Cell(0, $rowH, '', 1, 1, 'L', true);
            $pdf->SetXY($x0, $y0 + 1);
            $pdf->SetFont('Helvetica', '', 8);
            foreach ($lines as $ln) {
                $pdf->Cell(0, $lineH, $ln, 0, 1);
            }
            $pdf->SetFont('Helvetica', '', 9);

            // Jadikan seluruh baris klik-tabuh → lompat ke anotasi tujuan.
            $rowTopY = $y0;
            $rowW = $pdf->GetPageWidth() - $pdf->lMargin - $pdf->rMargin;
            $pdf->Link($pdf->lMargin, $rowTopY, $rowW, $rowH, $link);

            if ($pdf->GetY() > 270) {
                $pdf->AddPage();
            }
            $row++;
        }

        return $links;
    }

    private function legendRow(Fpdi $pdf, array $color, string $label): void
    {
        [$r, $g, $b] = $color;
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->SetFillColor($r, $g, $b);
        $pdf->SetDrawColor($r, $g, $b);
        $pdf->Rect($x, $y, 12, 6, 'DF');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x + 16, $y);
        $pdf->Cell(0, 6, $label, 0, 1);
    }

    /**
     * Bungkus teks ke beberapa baris sesuai lebar (unit PDF points).
     */
    private function wrapPdfText(string $text, float $maxWidth, callable $widthFn): array
    {
        $lines = [];
        $words = preg_split('/\s+/', trim($text));
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($widthFn($candidate) <= $maxWidth) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private function inlinePdf(string $path)
    {
        $fullPath = Storage::disk('local')->path($path);
        abort_unless(is_file($fullPath), 404, 'File PDF tidak ditemukan.');

        $size = filesize($fullPath);
        $name = basename($fullPath);

        // Streaming byte langsung + header eksplisit agar browser menampilkan
        // PDF di dalam halaman (inline), BUKAN mengunduhnya.
        return response()->streamDownload(function () use ($fullPath) {
            readfile($fullPath);
        }, $name, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => $size,
            'Cache-Control' => 'private, no-transform',
        ], 'inline');
    }

    // ---------------------------------------------------------------- viewer + comments

    public function viewer(Request $request, LogbookEntry $logbook): View
    {
        $this->authorize('view', $logbook);
        if ($request->user()->isDosen() && $request->user()->can('review', $logbook) && ! $logbook->review_opened_at) {
            $logbook->update(['review_opened_at' => now()]);
        }
        $logbook->load('comments.user');

        // Edge non-PDF: institusi bisa mengizinkan doc/docx — viewer hanya untuk PDF.
        // Beri tahu view agar menampilkan unduhan, bukan kanvas kosong.
        $draftName = (string) ($logbook->lampiran_original_name ?? '');
        $catatanName = (string) ($logbook->catatan_original_name ?? '');
        $isDraftPdf = ! $logbook->lampiran_path || str_ends_with(strtolower($draftName), '.pdf');
        $isCatatanPdf = ! $logbook->catatan_perbaikan_path || str_ends_with(strtolower($catatanName), '.pdf');

        // Konteks kembali ke wizard create-revisi (?from=create-revisi):
        // fallback tab-sama harus mendarat lagi di wizard langkah 3 dengan
        // autopull, bukan di halaman edit (konteks wizard hilang).
        $fromCreateRevisi = $request->query('from') === 'create-revisi' && $request->user()->can('update', $logbook);
        $wizardParentId = null;
        $wizardDraftId = null;
        $wizardReturnUrl = null;
        if ($fromCreateRevisi) {
            if ($logbook->jenis === LogbookEntry::JENIS_REVISI && $logbook->parent_entry_id) {
                $wizardParentId = $logbook->parent_entry_id;
                $wizardDraftId = $logbook->id;
            } else {
                $wizardParentId = $logbook->id;
            }
            $wizardReturnUrl = route('logbook.create-revisi', array_filter([
                'program' => $logbook->mahasiswaTa?->jenis,
                'parent_entry_id' => $wizardParentId,
                'draft_id' => $wizardDraftId,
                'step' => 3,
                'autopull' => 1,
            ]));
        }

        return view('logbook.pdf-viewer', compact('logbook', 'isDraftPdf', 'isCatatanPdf', 'wizardParentId', 'wizardDraftId', 'wizardReturnUrl'));
    }

    public function comments(Request $request, LogbookEntry $logbook): JsonResponse
    {
        $this->authorize('view', $logbook);

        $type = $request->query('type', PdfComment::FILE_TYPE_DRAFT);
        $comments = $logbook->comments()
            ->fileType($type)
            ->with('user', 'replies.user', 'entry.mahasiswaTa.mahasiswa')
            ->orderBy('created_at')
            ->get()
            ->map(function (PdfComment $c) {
                $status = $c->resolution_status ?: ($c->is_resolved ? PdfComment::STATUS_RESOLVED : PdfComment::STATUS_OPEN);
                // Pakai payload tersimpan bila ada (membawa `native`: tipe +
                // multi-rect presisi highlight teks), tapi segarkan isi dan
                // status dari kolom agar tidak basi. Fallback bangun dari kolom
                // untuk baris lama tanpa payload.
                $payload = $c->payload;
                if (is_array($payload) && isset($payload['body'][0]) && is_array($payload['body'][0])) {
                    $payload['body'][0]['value'] = $c->comment;
                    $payload['body'][0]['resolved'] = $status === PdfComment::STATUS_RESOLVED;
                    $payload['body'][0]['resolution_status'] = $status;
                } else {
                    $payload = $c->buildPayloadFromColumns();
                }

                return [
                    'id' => $c->id,
                    'user' => $c->user,
                    'file_type' => $c->file_type,
                    'payload' => $payload,
                    'resolution_status' => $status,
                    'reply' => $c->reply,
                    'replies' => $c->repliesForViewer(),
                    'is_dosen' => (bool) ($c->user ? $c->user->isDosen() : false),
                    'created_at' => $c->created_at,
                ];
            });

        return response()->json($comments);
    }

    /**
     * Simpan anotasi PDF. Mendukung dua bentuk input:
     *  a) W3C Web Annotation JSON pada kolom `payload` (arsitektur baru), atau
     *  b) kolom flat lama (file_type, page_number, pos_x, pos_y, x2, y2, comment).
     * Geometri kolom tetap disinkronkan dari payload untuk render cepat.
     */
    public function storeComment(Request $request, LogbookEntry $logbook): JsonResponse
    {
        $this->authorize('view', $logbook);

        $request->validate([
            'file_type' => ['required', 'in:'.implode(',', PdfComment::FILE_TYPES)],
            'payload' => ['sometimes', 'array'],
            'comment' => ['required_without:payload', 'string', 'max:2000'],
            'page_number' => ['nullable', 'integer', 'min:1'],
            'pos_x' => ['nullable', 'numeric', 'between:0,1'],
            'pos_y' => ['nullable', 'numeric', 'between:0,1'],
            'x2' => ['nullable', 'numeric', 'between:0,1'],
            'y2' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $fileType = $request->input('file_type');
        $payload = $request->input('payload');

        $comment = new PdfComment([
            'user_id' => $request->user()->id,
            'file_type' => $fileType,
        ]);

        if (is_array($payload)) {
            // Arsitektur baru: simpan payload Web Annotation, sinkronkan geometri.
            $comment->payload = $payload;
            $comment->syncFromPayload();
        } else {
            // Fallback kolom flat lama.
            $comment->page_number = $request->integer('page_number');
            $comment->pos_x = $request->input('pos_x');
            $comment->pos_y = $request->input('pos_y');
            $comment->x2 = $request->input('x2');
            $comment->y2 = $request->input('y2');
            $comment->comment = $request->input('comment');
            $comment->is_resolved = false;
            $comment->resolution_status = PdfComment::STATUS_OPEN;
        }

        $logbook->comments()->save($comment);

        $this->bestEffort(fn () => PdfCommentCreated::dispatch($comment));

        // Notifikasi ke pihak terkait (kecuali penulis komentar sendiri):
        // pemilik TA, reviewer entri, pembimbing (CC), serta penerima revisi
        // aktif dari entri ini maupun entri induknya (bisa dosen penguji).
        $recipients = [];
        if ($ownerId = $logbook->mahasiswaTa?->user_id) {
            $recipients[] = $ownerId;
        }
        if ($dosen = $logbook->reviewDosen()) {
            $recipients[] = $dosen->id;
        }
        $recipients = array_merge($recipients, array_filter([
            $logbook->mahasiswaTa?->pembimbing_1_id,
            $logbook->mahasiswaTa?->pembimbing_2_id,
        ]), $logbook->revisionChildren()->pluck('dosen_id')->all(), $logbook->parentEntry
            ? [$logbook->parentEntry->dosen_id]
            : []);

        foreach (array_unique(array_filter($recipients)) as $id) {
            if ($id !== $request->user()->id && ($u = User::find($id))) {
                $this->bestEffort(fn () => $u->notify(new ActivityNotification(
                    'Komentar baru pada PDF entri Anda: '.$comment->comment,
                    route('logbook.show', $logbook),
                    'Komentar PDF Baru',
                )));
            }
        }

        return response()->json([
            'id' => $comment->id,
            'user' => $comment->user,
            'file_type' => $comment->file_type,
            'payload' => $comment->payload,
            'resolution_status' => $comment->resolution_status,
            'created_at' => $comment->created_at,
        ], 201);
    }

    /**
     * Tarik anotasi mahasiswa pada PDF entri ini menjadi isian field perbaikan.
     * Alur anotasi-dulu: mahasiswa upload file -> buat anotasi perbaikan di PDF
     * -> tarik otomatis menjadi isian tanpa tulis manual.
     * - Entri revisi: anotasi menjadi baris tabel riwayat_perbaikan.
     * - Entri logbook: anotasi menjadi ringkasan progres_kendala.
     * Hanya pemilik yang masih bisa edit; isian lama dipertahankan dan
     * anotasi hanya menambah yang baru. Idempoten: anotasi yang sudah
     * tertarik (ada penanda di payload) tidak ditarik ulang.
     */
    public function pullAnnotations(Request $request, LogbookEntry $logbook): JsonResponse
    {
        $this->authorize('update', $logbook);

        // Anotasi seluruh anggota program (pemilik + anggota kelompok),
        // bukan hanya pemilik utama.
        $memberIds = $logbook->mahasiswaTa
            ? $logbook->mahasiswaTa->allMembers()->pluck('id')->all()
            : [$logbook->mahasiswaTa?->user_id];
        $comments = $logbook->comments()
            ->where('file_type', PdfComment::FILE_TYPE_DRAFT)
            ->whereIn('user_id', array_filter($memberIds))
            ->where('resolution_status', '!=', PdfComment::STATUS_RESOLVED)
            ->orderBy('page_number')->orderBy('id')
            ->get();

        $pulled = 0;
        $skippedResolved = 0;
        $skippedEmpty = 0;

        if ($logbook->jenis === LogbookEntry::JENIS_REVISI) {
            $rows = collect($logbook->riwayat_perbaikan ?? [])->map(fn ($row) => is_array($row) ? $row : [])->values()->all();

            foreach ($comments as $comment) {
                $payload = is_array($comment->payload) ? $comment->payload : [];
                if (! empty($payload['pulled_to_riwayat'])) {
                    continue;
                }
                if (! filled($comment->comment)) {
                    $payload['pulled_to_riwayat'] = true;
                    $payload['pulled_empty'] = true;
                    $comment->payload = $payload;
                    $comment->save();
                    $skippedEmpty++;
                    continue;
                }
                $existing = null;
                foreach ($rows as $i => $row) {
                    $empty = ! filled($row['halaman'] ?? null) && ! filled($row['komentar_dosen'] ?? null) && ! filled($row['perbaikan'] ?? null);
                    if ($empty) {
                        $existing = $i;
                        break;
                    }
                }
                $newRow = [
                    'halaman' => 'Hal. '.($comment->page_number ?: '—'),
                    'komentar_dosen' => '',
                    'perbaikan' => (string) $comment->comment,
                    'status' => LogbookEntry::PERBAIKAN_DRAF,
                ];
                if ($existing === null) {
                    $rows[] = $newRow;
                } else {
                    $rows[$existing] = array_merge($newRow, ['status' => $rows[$existing]['status'] ?? LogbookEntry::PERBAIKAN_DRAF]);
                }
                $payload['pulled_to_riwayat'] = true;
                $comment->payload = $payload;
                $comment->save();
                $pulled++;
            }

            if ($pulled > 0) {
                $logbook->update(['riwayat_perbaikan' => array_values($rows)]);
                $this->generateCatatanPerbaikanPdf($logbook);
            }

            return response()->json([
                'ok' => true,
                'pulled' => $pulled,
                'skipped_resolved' => $skippedResolved,
                'skipped_empty' => $skippedEmpty,
                'rows' => array_values($logbook->fresh()->riwayat_perbaikan ?? []),
            ]);
        }

        // Entri logbook: anotasi menjadi ringkasan perbaikan (satu poin per baris).
        $lines = collect(preg_split('/\R/u', (string) $logbook->progres_kendala))
            ->map(fn ($line) => trim((string) $line))->filter()->values()->all();
        foreach ($comments as $comment) {
            $payload = is_array($comment->payload) ? $comment->payload : [];
            if (! empty($payload['pulled_to_riwayat'])) {
                continue;
            }
            if (! filled($comment->comment)) {
                $payload['pulled_to_riwayat'] = true;
                $payload['pulled_empty'] = true;
                $comment->payload = $payload;
                $comment->save();
                $skippedEmpty++;
                continue;
            }
            $text = '- Hal. '.($comment->page_number ?: '—').': '.trim((string) $comment->comment);
            if (! in_array($text, $lines, true)) {
                $lines[] = $text;
            }
            $payload['pulled_to_riwayat'] = true;
            $comment->payload = $payload;
            $comment->save();
            $pulled++;
        }
        if ($pulled > 0) {
            $logbook->update(['progres_kendala' => implode("
", $lines)]);
        }

        return response()->json([
            'ok' => true,
            'pulled' => $pulled,
            'skipped_resolved' => $skippedResolved,
            'skipped_empty' => $skippedEmpty,
            'summary' => (string) $logbook->fresh()->progres_kendala,
        ]);
    }

    /** Resolve current and parent annotations when a review is approved. */
    private function resolveCommentsOnApproval(LogbookEntry $logbook): void
    {
        $entries = collect([$logbook, $logbook->parentEntry])->filter();

        foreach ($entries as $entry) {
            $entry->comments()
                ->where('resolution_status', '!=', PdfComment::STATUS_RESOLVED)
                ->get()
                ->each(function (PdfComment $comment) {
                    $comment->setResolutionStatus(PdfComment::STATUS_RESOLVED);
                    $comment->save();
                });
        }
    }
}
