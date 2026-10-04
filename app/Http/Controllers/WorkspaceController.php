<?php

namespace App\Http\Controllers;

use App\Events\WorkspaceFileUploaded;
use App\Http\Requests\StoreWorkspaceFileRequest;
use App\Models\MahasiswaTa;
use App\Models\User;
use App\Models\WorkspaceFile;
use App\Services\StorageUsageService;
use App\Services\WorkspaceUploadNotifier;
use App\Support\Feature;
use App\Support\ProgramContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    /**
     * Daftar file workspace.
     */
    public function index(Request $request, MahasiswaTa $mahasiswaTa): View|RedirectResponse
    {
        // Dosen belum menyetujui program → arahkan ke halaman persetujuan (bukan 403).
        if ($request->user()->isDosen() && ! $mahasiswaTa->dosenHasGrantedAccess()) {
            return redirect()->route('approval.index')
                ->with('warning', 'Mahasiswa ini belum disetujui. Setujui programnya untuk mengakses materinya.');
        }

        $this->authorize('viewWorkspace', $mahasiswaTa);

        $query = $mahasiswaTa->workspaceFiles()->with('uploader');

        $query->when($request->filled('bab'), fn ($q) => $q->where('bab', $request->query('bab')))
            ->when($request->filled('type'), function ($q) use ($request) {
                $type = $request->query('type');
                $q->where(function ($qq) use ($type) {
                    $qq->where('mime_type', 'like', $type === 'pdf' ? '%pdf%'
                        : ($type === 'doc' ? '%word%'
                        : ($type === 'xls' ? '%excel%' : '%'.$type.'%')));
                });
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->query('search');
                $q->where(fn ($qq) => $qq->where('original_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%"));
            });

        $files = $query->orderByDesc('created_at')->get();

        // Group by bab (null -> "Lainnya").
        $grouped = $files->groupBy(fn ($f) => $f->bab ?: 'Lainnya');

        // Statistik penyimpanan (kuota dibebankan sesuai status program:
        // dosen pembimbing jika sudah disetujui, mahasiswa 100 MB saat pending).
        $usageService = app(StorageUsageService::class);
        $chargeTarget = $mahasiswaTa->storageChargeTarget();
        $totalBytes = $files->sum('size');
        $quotaBytes = $chargeTarget
            ? ($chargeTarget->isMahasiswa() ? Feature::pendingStudentStorageLimitMb() : Feature::storageLimitMb($chargeTarget)) * 1048576
            : 0;
        $usedBytes = $chargeTarget ? $usageService->totalBytes($chargeTarget) : $totalBytes;

        return view('workspace.index', compact('mahasiswaTa', 'grouped', 'totalBytes', 'quotaBytes', 'usedBytes'));
    }

    /**
     * Halaman workspace utama — menyesuaikan role user.
     * - Mahasiswa: redirect ke workspace TA/KP aktif miliknya.
     * - Dosen: workspace pribadi + daftar TA bimbingan.
     * - Admin: daftar semua TA/KP.
     */
    public function roleIndex(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        // Mahasiswa: redirect ke workspace TA/KP aktif (bisa dipilih via ?program=ta|kp).
        if ($user->isMahasiswa()) {
            $ta = ProgramContext::resolve($user, $request);
            if ($ta) {
                return redirect()->route('workspace.index', $ta);
            }

            return view('workspace.role', [
                'user' => $user,
                'personalFiles' => collect(),
                'personalGrouped' => collect(),
                'personalTotalBytes' => 0,
                'personalQuotaBytes' => 0,
                'personalPct' => 0,
                'tas' => collect(),
            ]);
        }

        // Dosen: workspace pribadi + daftar TA bimbingan.
        if ($user->isDosen()) {
            $directoryFilters = $request->validate([
                'student_search' => ['nullable', 'string', 'max:100'],
                'student_program' => ['nullable', 'in:ta,kp'],
                'student_role' => ['nullable', 'in:pembimbing,penguji'],
                'student_view' => ['nullable', 'in:daftar,grid'],
            ]);
            $directoryFilters['student_search'] = trim($directoryFilters['student_search'] ?? '');
            $directoryFilters['student_program'] = $directoryFilters['student_program'] ?? '';
            $directoryFilters['student_role'] = $directoryFilters['student_role'] ?? '';
            $directoryFilters['student_view'] = $directoryFilters['student_view'] ?? 'daftar';

            $personalFiles = WorkspaceFile::where('user_id', $user->id)->with('uploader')
                ->orderByDesc('created_at')->get();
            $personalGrouped = $personalFiles->groupBy(fn ($f) => $f->bab ?: 'Lainnya');
            $personalTotalBytes = $personalFiles->sum('size');
            $personalQuotaBytes = Feature::storageLimitMb($user) * 1048576;
            $personalPct = $personalQuotaBytes > 0 ? min(100, round($personalTotalBytes / $personalQuotaBytes * 100)) : 0;

            // Use the same direct relationship as the directory, but never offer
            // workspaces that the lecturer cannot open before approval.
            $directory = MahasiswaTa::bimbinganOleh($user)
                ->whereNotIn('status_ta', [MahasiswaTa::STATUS_PENDING_APPROVAL, MahasiswaTa::STATUS_DITOLAK]);
            $directoryTotal = (clone $directory)->distinct()->count('user_id');

            $tas = $directory
                ->when($directoryFilters['student_search'] !== '', function ($query) use ($directoryFilters) {
                    $term = '%'.$directoryFilters['student_search'].'%';
                    $query->where(fn ($q) => $q->whereHas('mahasiswa', fn ($student) => $student
                        ->where('name', 'like', $term)->orWhere('nim', 'like', $term))
                        ->orWhere('judul_ta', 'like', $term)
                        ->orWhere('tempat_kp', 'like', $term));
                })
                ->when($directoryFilters['student_program'] !== '', fn ($q) => $q->where('jenis', $directoryFilters['student_program']))
                ->when($directoryFilters['student_role'] === 'pembimbing', fn ($q) => $q->where(fn ($roles) => $roles
                    ->where('pembimbing_1_id', $user->id)->orWhere('pembimbing_2_id', $user->id)))
                ->when($directoryFilters['student_role'] === 'penguji', fn ($q) => $q->where(fn ($roles) => $roles
                    ->where('penguji_1_id', $user->id)->orWhere('penguji_2_id', $user->id)))
                ->with('mahasiswa.universities')
                ->withCount('workspaceFiles')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'student_page')
                ->withQueryString();

            return view('workspace.role', compact('user', 'personalFiles', 'personalGrouped', 'personalTotalBytes', 'personalQuotaBytes', 'personalPct', 'tas', 'directoryTotal', 'directoryFilters'));
        }

        // Admin: daftar TA/KP (dibatasi institusi untuk admin institusional).
        $tasQuery = MahasiswaTa::with(['mahasiswa', 'pembimbing1', 'pembimbing2']);
        if (!$user->isSystemAdmin() && $user->institution_id) {
            $tasQuery->where('institution_id', $user->institution_id);
        }
        $tas = $tasQuery->latest()->get();

        return view('workspace.role', [
            'user' => $user,
            'personalFiles' => collect(),
            'personalGrouped' => collect(),
            'personalTotalBytes' => 0,
            'personalQuotaBytes' => 0,
            'personalPct' => 0,
            'tas' => $tas,
        ]);
    }

    /**
     * Upload multi-file — hanya mahasiswa pemilik TA.
     */
    public function store(StoreWorkspaceFileRequest $request, MahasiswaTa $mahasiswaTa): RedirectResponse
    {
        $this->authorize('viewWorkspace', $mahasiswaTa);
        abort_unless($mahasiswaTa->isMember($request->user()), 403, 'Hanya anggota kelompok yang dapat menambah file.');

        $bab = $request->input('bab');

        $fileNames = collect($request->file('files'))
            ->map(fn ($f) => $f->getClientOriginalName())
            ->values()->all();

        // Cek kuota sesuai target pembebanan: dosen pembimbing (P1, fallback P2)
        // setelah program disetujui, atau mahasiswa sendiri dengan kuota 100 MB
        // selama program masih menunggu persetujuan dosen.
        $chargeTo = $mahasiswaTa->storageChargeTarget();
        $storeFiles = function () use ($request, $mahasiswaTa, $bab) {
            foreach ($request->file('files') as $file) {
                $stored = $file->store('workspace/'.$mahasiswaTa->id, 'local');

                WorkspaceFile::create([
                    'mahasiswa_ta_id' => $mahasiswaTa->id,
                    'uploaded_by' => $request->user()->id,
                    'bab' => $bab,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $stored,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }
        };

        if ($chargeTo) {
            $incoming = collect($request->file('files'))->sum(fn ($f) => $f->getSize());
            app(StorageUsageService::class)->withUploadLock($chargeTo, $incoming, $storeFiles);
        } else {
            $storeFiles();
        }

        // Notifikasi: in-app instan + email rekap (throttle 24 jam).
        $this->bestEffort(fn () => app(WorkspaceUploadNotifier::class)
            ->notify($mahasiswaTa, $request->user(), $fileNames));

        return back()->with('success', 'File berhasil diunggah ke workspace.');
    }

    /**
     * Workspace pribadi dosen (user_id) — file milik dosen itu sendiri.
     */
    public function personalIndex(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isDosen() || $user->isAdmin(), 403, 'Halaman ini khusus dosen.');

        $query = WorkspaceFile::where('user_id', $user->id)->with('uploader');

        $query->when($request->filled('bab'), fn ($q) => $q->where('bab', $request->query('bab')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->query('search');
                $q->where(fn ($qq) => $qq->where('original_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%"));
            });

        $files = $query->orderByDesc('created_at')->get();

        // Group by bab (null -> "Lainnya").
        $grouped = $files->groupBy(fn ($f) => $f->bab ?: 'Lainnya');

        $totalBytes = $files->sum('size');
        $quotaBytes = Feature::storageLimitMb($user) * 1048576;
        $usedBytes = app(StorageUsageService::class)->totalBytes($user);

        return view('workspace.personal', compact('grouped', 'totalBytes', 'quotaBytes', 'usedBytes', 'user'));
    }

    /**
     * Upload file ke workspace pribadi dosen.
     */
    public function personalStore(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isDosen() || $user->isAdmin(), 403, 'Halaman ini khusus dosen.');

        $validated = $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'max:51200'], // 50 MB
            'bab' => ['nullable', 'string', 'max:50'],
        ]);

        $bab = $request->input('bab');

        // Cek kuota dosen itu sendiri.
        $incoming = collect($request->file('files'))->sum(fn ($f) => $f->getSize());
        app(StorageUsageService::class)->withUploadLock($user, $incoming, function () use ($request, $user, $bab) {
            foreach ($request->file('files') as $file) {
                $stored = $file->store('workspace/dosen/'.$user->id, 'local');

                WorkspaceFile::create([
                    'user_id' => $user->id,
                    'uploaded_by' => $user->id,
                    'bab' => $bab,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $stored,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }
        });

        return back()->with('success', 'File berhasil diunggah ke workspace pribadi.');
    }

    /**
     * Download file (attachment dengan original_name).
     */
    public function download(Request $request, WorkspaceFile $file)
    {
        $this->authorizeFileAccess($request->user(), $file);

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    /**
     * Preview PDF inline (atau redirect ke download utk non-PDF).
     */
    public function preview(Request $request, WorkspaceFile $file)
    {
        $this->authorizeFileAccess($request->user(), $file);

        if (!$file->isPdf()) {
            return Storage::disk('local')->download($file->path, $file->original_name);
        }

        $fullPath = Storage::disk('local')->path($file->path);
        $size = filesize($fullPath);

        return response()->streamDownload(function () use ($fullPath) {
            readfile($fullPath);
        }, $file->original_name, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => $size,
            'Cache-Control' => 'private, no-transform',
        ], 'inline');
    }

    /**
     * Edit metadata (bab & description) — hanya pemilik file.
     */
    public function update(Request $request, WorkspaceFile $file): RedirectResponse
    {
        $this->authorizeFileAccess($request->user(), $file);
        abort_unless($this->canModify($request->user(), $file), 403, 'Anda tidak berhak mengedit file ini.');

        $validated = $request->validate([
            'bab' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $file->update($validated);

        return back()->with('success', 'Metadata file diperbarui.');
    }

    /**
     * Hapus file — hanya pemilik file.
     */
    public function destroy(Request $request, WorkspaceFile $file): RedirectResponse
    {
        $this->authorizeFileAccess($request->user(), $file);
        abort_unless($this->canModify($request->user(), $file), 403, 'Anda tidak berhak menghapus file ini.');

        Storage::disk('local')->delete($file->path);
        $file->delete();

        return back()->with('success', 'File dihapus.');
    }

    /**
     * Otorisasi akses file: file dosen (user_id) hanya pemiliknya;
     * file mahasiswa (mahasiswa_ta_id) mengikuti policy workspace TA.
     */
    private function authorizeFileAccess(User $user, WorkspaceFile $file): void
    {
        if ($file->user_id) {
            abort_unless($file->user_id === $user->id, 403, 'Anda tidak berhak mengakses file ini.');
            return;
        }

        $this->authorize('viewWorkspace', $file->mahasiswaTa);
    }

    /**
     * Apakah user boleh memodifikasi (edit/hapus) file ini.
     */
    private function canModify(User $user, WorkspaceFile $file): bool
    {
        if ($file->user_id) {
            return $file->user_id === $user->id;
        }

        return $file->mahasiswaTa?->isMember($user) ?? false;
    }
}
