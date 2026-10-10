<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use App\Models\ActionItem;
use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\SeminarSubmission;
use App\Models\SeminarSubmissionRead;
use App\Models\Sidang;
use App\Models\User;
use App\Services\MahasiswaDashboardService;
use App\Services\MaterialsReviewQueue;
use App\Services\ProgramNamingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private MahasiswaDashboardService $dashboardService) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return $this->adminDashboard($user);
        }

        if ($user->isDosen()) {
            return $this->dosenDashboard($user);
        }

        return $this->mahasiswaDashboard($user);
    }

    private function adminDashboard(User $user): View
    {
        $stats = [
            'mahasiswa' => User::role('mahasiswa')->count(),
            'dosen' => User::role('dosen')->count(),
            'ta' => MahasiswaTa::count(),
            'menunggu_review' => LogbookEntry::where('status', LogbookEntry::STATUS_SUBMITTED)->count(),
        ];

        $tas = MahasiswaTa::with(['mahasiswa', 'pembimbing1', 'pembimbing2'])
            ->withCount('entries')
            ->latest()
            ->take(10)
            ->get();

        return view('dashboard.admin', compact('stats', 'tas'));
    }

    private function dosenDashboard(User $user): View
    {
        $pendingMaterialsCount = app(MaterialsReviewQueue::class)->countFor($user);

        // TA where the dosen is pembimbing 1/2 atau penguji 1/2 (sudah disetujui).
        $tas = MahasiswaTa::where(fn ($q) => $q->where('pembimbing_1_id', $user->id)
            ->orWhere('pembimbing_2_id', $user->id)
            ->orWhere('penguji_1_id', $user->id)
            ->orWhere('penguji_2_id', $user->id))
            ->whereNotIn('status_ta', [MahasiswaTa::STATUS_PENDING_APPROVAL, MahasiswaTa::STATUS_DITOLAK])
            ->with('mahasiswa')
            ->latest()
            ->get();

        // Antrean review hanya untuk TA yang benar-benar dibimbing (bukan diuji).
        $taIds = $tas->pluck('id');
        $reviewTaIds = MahasiswaTa::where('pembimbing_1_id', $user->id)
            ->orWhere('pembimbing_2_id', $user->id)
            ->pluck('id');
        $queueQuery = LogbookEntry::where(function ($query) use ($reviewTaIds, $user) {
            $query->whereIn('mahasiswa_ta_id', $reviewTaIds)
                ->orWhere('dosen_id', $user->id);
        })
            ->where('status', LogbookEntry::STATUS_SUBMITTED)
            ->whereHas('mahasiswaTa', fn ($q) => $q->whereNotIn('status_ta', [MahasiswaTa::STATUS_PENDING_APPROVAL, MahasiswaTa::STATUS_DITOLAK]));
        $queueCount = (clone $queueQuery)->count();
        $queue = $queueQuery
            ->with(['mahasiswaTa.mahasiswa'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        // Statistik & progres per mahasiswa bimbingan.
        $entryCounts = LogbookEntry::whereIn('mahasiswa_ta_id', $taIds)
            ->selectRaw('mahasiswa_ta_id, jenis, status, COUNT(*) as total')
            ->groupBy('mahasiswa_ta_id', 'jenis', 'status')
            ->get()
            ->groupBy('mahasiswa_ta_id');
        $perTa = $tas->map(function ($ta) use ($entryCounts) {
            $counts = $entryCounts->get($ta->id, collect());
            $approved = $counts->where('jenis', LogbookEntry::JENIS_LOGBOOK)->where('status', LogbookEntry::STATUS_APPROVED)->sum('total');
            $target = $ta->target_sesi ?? 7;
            $percent = $target > 0 ? (int) round($approved / $target * 100) : 0;

            return [
                'ta' => $ta,
                'approved' => $approved,
                'target' => $target,
                'percent' => $percent,
                'menunggu' => $counts->where('status', LogbookEntry::STATUS_SUBMITTED)->sum('total'),
                'regularity' => $ta->regularity_status,
                'tooltip' => $ta->regularity_tooltip,
            ];
        })
            ->sortBy(fn ($r) => ['red' => 0, 'yellow' => 1, 'green' => 2][$r['regularity']] ?? 3)
            ->values();

        // Summary counter health indicator.
        $healthCount = [
            'green' => $perTa->where('regularity', 'green')->count(),
            'yellow' => $perTa->where('regularity', 'yellow')->count(),
            'red' => $perTa->where('regularity', 'red')->count(),
        ];

        // Kartu statistik bimbingan & pengujian.
        $stats = [
            'sedang_progres' => MahasiswaTa::bimbinganOleh($user)->aktif()->count(),
        ];

        // Ringkasan aksi untuk dosen: permintaan attachment pending + mahasiswa perlu perhatian.
        $pendingRegistrations = MahasiswaTa::where('status_ta', MahasiswaTa::STATUS_PENDING_APPROVAL)
            ->where(fn ($q) => $q->where('pembimbing_1_id', $user->id)
                ->orWhere('pembimbing_2_id', $user->id)
                ->orWhere('penguji_1_id', $user->id)
                ->orWhere('penguji_2_id', $user->id))
            ->count();
        $needsAttention = $perTa->whereIn('regularity', ['yellow', 'red'])->count();
        $priorityStudents = $perTa->whereIn('regularity', ['red', 'yellow'])->take(6);
        $phaseDistribution = $tas->groupBy(fn ($ta) => $ta->jenis.'|'.$ta->fase.'|'.$ta->faseLabel())
            ->map(function ($students) {
                $ta = $students->first();

                return ['label' => $ta->faseLabel(), 'program' => $ta->jenisLabel(), 'count' => $students->count()];
            })
            ->sortByDesc('count')->values();

        // ---- Agenda terdekat: jadwal seminar/sidang mahasiswa bimbingan/pengujian ----
        $agendaTerdekat = SeminarSubmission::where('status', SeminarSubmission::STATUS_SUBMITTED)
            ->where('tanggal', '>=', now()->toDateString())
            ->whereIn('mahasiswa_ta_id', $taIds)
            ->with(['mahasiswaTa.mahasiswa'])
            ->orderBy('tanggal')
            ->orderBy('waktu')
            ->limit(4)
            ->get();

        return view('dashboard.dosen', compact(
            'queue', 'queueCount', 'priorityStudents', 'phaseDistribution', 'healthCount', 'stats',
            'pendingRegistrations', 'needsAttention', 'pendingMaterialsCount',
            'agendaTerdekat'
        ));
    }

    /**
     * List mahasiswa bimbingan dosen (filter status).
     */
    public function dosenMahasiswaList(Request $request): View
    {
        $user = $request->user();
        $status = $request->query('status', 'all');

        $query = MahasiswaTa::bimbinganOleh($user)
            ->with(['mahasiswa', 'pembimbing1', 'pembimbing2'])
            ->when(in_array($status, [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_TAMAT]), function ($q) use ($status) {
                $q->where('status_ta', $status);
            });

        // Urutkan berdasarkan urgensi: merah (butuh perhatian) di atas, lalu kuning, lalu hijau.
        $priority = ['red' => 0, 'yellow' => 1, 'green' => 2];

        $list = $query->latest()->get()
            ->map(fn ($ta) => [
                'ta' => $ta,
                'regularity' => $ta->regularity_status,
                'tooltip' => $ta->regularity_tooltip,
            ])
            ->sortBy(fn ($item) => $priority[$item['regularity']] ?? 3)
            ->values();

        return view('dashboard.dosen-mahasiswa-list', compact('list', 'status', 'user'));
    }

    /** Workspace mahasiswa dosen: satu baris per program TA/KP, dengan semua peran terkait. */
    public function mahasiswaSaya(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isDosen(), 403, 'Halaman ini khusus dosen.');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'program' => ['nullable', 'in:'.implode(',', MahasiswaTa::JENISES)],
            'fase' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', MahasiswaTa::STATUS_TA)],
            'peran' => ['nullable', 'in:pembimbing,penguji'],
            'tab' => ['nullable', 'in:semua,pembimbing,penguji'],
            'view' => ['nullable', 'in:daftar,fase'],
        ]);
        $filters['search'] = trim($filters['search'] ?? '');
        $filters['tab'] = $filters['tab'] ?? 'semua';
        $filters['view'] = $filters['view'] ?? 'daftar';

        $base = MahasiswaTa::query()
            ->where(fn ($q) => $q->where('pembimbing_1_id', $user->id)
                ->orWhere('pembimbing_2_id', $user->id)
                ->orWhere('penguji_1_id', $user->id)
                ->orWhere('penguji_2_id', $user->id));
        $supervised = fn ($q) => $q->where('pembimbing_1_id', $user->id)->orWhere('pembimbing_2_id', $user->id);
        $examined = fn ($q) => $q->where('penguji_1_id', $user->id)->orWhere('penguji_2_id', $user->id);

        $metrics = [
            'total' => (clone $base)->count(),
            'pembimbing' => (clone $base)->where($supervised)->count(),
            'penguji' => (clone $base)->where($examined)->count(),
            'aktif' => (clone $base)->where('status_ta', MahasiswaTa::STATUS_AKTIF)->count(),
            'nonaktif' => (clone $base)->where('status_ta', MahasiswaTa::STATUS_NONAKTIF)->count(),
        ];

        $programs = (clone $base)->distinct()->pluck('jenis')->all();
        $statuses = (clone $base)->distinct()->pluck('status_ta')->all();
        $phases = (clone $base)->select('jenis', 'fase')->distinct()->get();
        $phaseOptions = [];
        foreach (MahasiswaTa::JENISES as $jenis) {
            $definitions = $jenis === MahasiswaTa::JENIS_KP ? MahasiswaTa::FASES_KP : MahasiswaTa::FASES;
            foreach ($definitions as $key => $label) {
                if ($phases->contains(fn ($phase) => $phase->jenis === $jenis && $phase->fase === $key)) {
                    $phaseOptions[$jenis.':'.$key] = strtoupper($jenis).' · '.$label;
                }
            }
        }

        if (! empty($filters['fase']) && ! isset($phaseOptions[$filters['fase']])) {
            abort(422, 'Fase tidak tersedia untuk mahasiswa Anda.');
        }

        $query = clone $base;
        if ($filters['search'] !== '') {
            $query->whereHas('mahasiswa', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
                ->orWhere('nim', 'like', '%'.$filters['search'].'%'));
        }
        if (! empty($filters['program'])) {
            $query->where('jenis', $filters['program']);
        }
        if (! empty($filters['fase']) && isset($phaseOptions[$filters['fase']])) {
            [$jenis, $fase] = explode(':', $filters['fase'], 2);
            $query->where('jenis', $jenis)->where('fase', $fase);
        }
        if (! empty($filters['status'])) {
            $query->where('status_ta', $filters['status']);
        }
        if ($filters['tab'] === 'pembimbing' || ($filters['peran'] ?? null) === 'pembimbing') {
            $query->where($supervised);
        }
        if ($filters['tab'] === 'penguji' || ($filters['peran'] ?? null) === 'penguji') {
            $query->where($examined);
        }

        // Distribution is over all matching programs, not only the current page.
        $distribution = (clone $query)->select('jenis', 'fase')
            ->selectRaw('COUNT(*) as total')->groupBy('jenis', 'fase')->get()
            ->keyBy(fn ($row) => $row->jenis.':'.$row->fase);
        // NamingService resolves labels via the student's university affiliation.
        $students = $query->with('mahasiswa.universities')->orderByDesc('id')->paginate(20)->withQueryString();
        $students->getCollection()->each(function ($ta) use ($user) {
            $ta->my_roles = collect([
                'Pembimbing 1' => $ta->pembimbing_1_id === $user->id,
                'Pembimbing 2' => $ta->pembimbing_2_id === $user->id,
                'Penguji 1' => $ta->penguji_1_id === $user->id,
                'Penguji 2' => $ta->penguji_2_id === $user->id,
            ])->filter()->keys()->all();
        });

        return view('dashboard.dosen-mahasiswa-saya', compact(
            'students', 'filters', 'metrics', 'programs', 'statuses', 'phaseOptions', 'distribution', 'user'
        ));
    }

    /**
     * Tutup (dismiss) card "Lanjut ke TA" di dashboard mahasiswa untuk sesi ini.
     * Link permanen tetap tersedia di halaman Profil.
     */
    public function dismissLanjutTa(Request $request): RedirectResponse
    {
        $request->session()->put('lanjut_ta_dismissed', true);

        return back();
    }

    /**
     * Riwayat menguji dosen.
     */
    public function dosenSidangList(Request $request): View
    {
        $user = $request->user();

        $sidangs = Sidang::where('penguji_id', $user->id)
            ->with(['mahasiswaTa.mahasiswa'])
            ->orderByDesc('tanggal')
            ->get();

        return view('dashboard.dosen-sidang-list', compact('sidangs', 'user'));
    }

    private function mahasiswaDashboard(User $user): View
    {
        // Program yang ditampilkan: default program aktif; bisa dipilih via ?program=kp|ta.
        $programs = $user->allPrograms()->with(['pembimbing1', 'pembimbing2', 'penguji1', 'penguji2', 'members'])->get();
        $activeProgram = $user->programAktif();

        $requested = request()->query('program');
        $program = $requested === 'kp' || $requested === 'ta'
            ? $programs->firstWhere('jenis', $requested)
            : null;

        $ta = $program ?: ($activeProgram ?: $programs->first());

        $entries = $ta
            ? $ta->entries()->latest()->get()
            : collect();
        // Hanya delapan entri terbaru yang dipakai oleh ringkasan aktivitas.
        if ($ta) {
            $entries->take(8)->load('comments');
        }

        $approved = $entries->where('jenis', LogbookEntry::JENIS_LOGBOOK)->where('status', LogbookEntry::STATUS_APPROVED)->count();
        $target = $ta?->target_sesi ?? 7;
        $progressPercent = $target > 0 ? (int) round($approved / $target * 100) : 0;

        // ---- Milestone fase ----
        $faseKeys = $ta && $ta->isKp() ? array_keys(MahasiswaTa::FASES_KP) : array_keys(MahasiswaTa::FASES);
        $faseIndex = $ta ? array_search($ta->fase, $faseKeys, true) : 0;
        if ($faseIndex === false) {
            $faseIndex = 0;
        }
        $faseLabels = $ta ? app(ProgramNamingService::class)->faseLabels($ta) : [];

        // ---- Achievement (unlocked + total) - hanya untuk TA ----
        $unlockedAchievements = $ta && $ta->isTa() ? $user->achievements()->get() : collect();
        $unlockedCodes = $unlockedAchievements->pluck('code')->map(fn ($c) => (string) $c);
        $totalAchievements = $ta && $ta->isTa() ? Achievement::count() : 0;

        // ---- Logbook harian (hanya KP) ----
        $logbookHarian = $ta && $ta->isKp()
            ? $ta->logbookHarian()->orderByDesc('tanggal')->limit(3)->get()
            : collect();

        // ---- Statistik & streak ----
        $stats = $this->dashboardService->buildStats($ta, $entries);

        // ---- Timeline aktivitas ----
        $timeline = $this->dashboardService->buildTimeline($ta, $entries);

        // ---- Heatmap data (aktivitas per hari, 12 bulan) ----
        $heatmap = $this->dashboardService->buildHeatmap($ta, $entries);

        // ---- Health indicator (self-awareness) ----
        $regularity = $ta ? $ta->regularity_status : 'red';
        $regularityTooltip = $ta ? $ta->regularity_tooltip : 'Belum ada data';

        // ---- Pengumuman belum dibaca (banner) ----
        $unreadAnnouncements = $user->announcements()
            ->with('sender')
            ->wherePivotNull('read_at')
            ->orderByDesc('created_at')
            ->get();

        // ---- Ringkasan "Aksi Saya" untuk mahasiswa ----
        $draftCount = $entries->where('status', LogbookEntry::STATUS_DRAFT)->count();
        $revisiCount = $entries->where('status', LogbookEntry::STATUS_REVISI)->count();
        // Gerbang lunak: satu aksi revisi paling relevan agar jalur benar
        // (Lanjutkan/Buat Revisi) selalu paling mudah dijangkau mahasiswa.
        $pendingRevisionAction = LogbookEntry::pendingRevisionActionFor($ta);
        // Badge/banner per-thread: satu utas = satu item yang bisa diklik.
        $pendingRevisionThreads = LogbookEntry::pendingRevisionThreadsFor($ta);
        $unresolvedActionItems = $ta
            ? ActionItem::whereHas('entry', fn ($q) => $q->where('mahasiswa_ta_id', $ta->id))
                ->where('is_done', false)
                ->count()
            : 0;

        // Informasi universitas untuk kartu dashboard mahasiswa.
        $university = $user->isMahasiswa()
            ? ($user->primaryUniversity() ?? $ta?->pembimbing1?->primaryUniversity())
            : $user->primaryUniversity();

        $nilai = $ta?->finalization?->nilai;

        // ---- Ringkasan hasil sidang/seminar (untuk dilihat mahasiswa sendiri) ----
        $sidangs = $ta
            ? $ta->sidangs()->orderByDesc('tanggal')->get()
            : collect();

        // ---- Agenda terdekat (jadwal seminar/sidang yang akan datang) ----
        $agendaTerdekat = $ta
            ? SeminarSubmission::where('mahasiswa_ta_id', $ta->id)
                ->where('status', SeminarSubmission::STATUS_SUBMITTED)
                ->where('tanggal', '>=', now()->toDateString())
                ->orderBy('tanggal')
                ->orderBy('waktu')
                ->limit(10)
                ->get()
            : collect();

        // ---- Submission untuk fase aktif (agar tombol "Kirim Bahan" muncul
        // lagi saat mahasiswa pindah ke milestone seminar berikutnya) ----
        $seminarSubmission = $ta
            ? $ta->seminarSubmissions()
                ->where('jenis', SeminarSubmission::jenisFromFase($ta))
                ->latest()
                ->first()
            : null;

        // ---- Status mahasiswa (aktif/verified) untuk banner ----
        $mahasiswaStatus = $user->registration_status;
        $pendingApproval = $ta && $ta->status_ta === MahasiswaTa::STATUS_PENDING_APPROVAL;
        // $ta bisa jatuh ke program yang sudah ditolak (allPrograms() tidak difilter status
        // saat programAktif() kosong) — tandai agar mahasiswa tetap diarahkan pilih dosen lagi.
        $rejectedProgram = $ta && $ta->status_ta === MahasiswaTa::STATUS_DITOLAK;

        // Profil dianggap belum lengkap jika NIM (identifier), WhatsApp, atau afiliasi
        // perguruan tinggi (sampai prodi) belum diisi mahasiswa.
        $profileIncomplete = blank($user->nim) || blank($user->whatsapp) || ! $user->primaryUniversity()?->pivot?->study_program_id;

        return view('dashboard.mahasiswa', compact(
            'programs', 'activeProgram', 'ta', 'entries', 'approved', 'target', 'progressPercent',
            'faseKeys', 'faseIndex', 'faseLabels',
            'unlockedAchievements', 'unlockedCodes', 'totalAchievements',
            'logbookHarian',
            'stats', 'timeline', 'heatmap', 'regularity', 'regularityTooltip',
            'unreadAnnouncements',
            'draftCount', 'revisiCount', 'unresolvedActionItems', 'pendingRevisionAction', 'pendingRevisionThreads',
            'university', 'nilai', 'sidangs', 'agendaTerdekat', 'seminarSubmission',
            'mahasiswaStatus', 'pendingApproval', 'rejectedProgram', 'profileIncomplete'
        ));
    }

    /**
     * Agenda Seminar/Sidang (dosen): daftar submission bahan + jadwal untuk
     * semua mahasiswa yang dosen ini bimbing/puji, diurutkan dari jadwal
     * terdekat. Menyertakan semua jenis (termasuk Seminar KP).
     */
    public function dosenSeminarJadwal(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isDosen(), 403);

        $taIds = MahasiswaTa::where(fn ($q) => $q->where('pembimbing_1_id', $user->id)
            ->orWhere('pembimbing_2_id', $user->id)
            ->orWhere('penguji_1_id', $user->id)
            ->orWhere('penguji_2_id', $user->id))
            ->pluck('id');

        $tab = $request->query('tab', 'upcoming');
        $jenis = $request->query('jenis');

        $query = SeminarSubmission::whereIn('mahasiswa_ta_id', $taIds)
            ->with(['mahasiswaTa.mahasiswa']);

        if ($jenis && in_array($jenis, SeminarSubmission::JENISES, true)) {
            $query->where('jenis', $jenis);
        }

        $today = now()->toDateString();
        $nowTime = now()->format('H:i');
        if ($tab === 'past') {
            $query->where(fn ($q) => $q->where('tanggal', '<', $today)
                ->orWhere(fn ($q2) => $q2->where('tanggal', $today)->where('waktu', '<', $nowTime)));
            // Riwayat: terbaru dulu.
            $query->orderByDesc('tanggal')->orderByDesc('waktu');
        } else { // upcoming
            $query->where(fn ($q) => $q->where('tanggal', '>', $today)
                ->orWhere(fn ($q2) => $q2->where('tanggal', $today)->where('waktu', '>=', $nowTime)));
            // Akan datang: terdekat dulu.
            $query->orderBy('tanggal')->orderBy('waktu');
        }

        $submissions = $query->paginate(15)->withQueryString();

        // Status "dibaca" untuk dosen ini.
        $readIds = SeminarSubmissionRead::where('user_id', $user->id)
            ->whereIn('seminar_submission_id', $submissions->pluck('id'))
            ->pluck('seminar_submission_id')
            ->all();

        $unreadCount = SeminarSubmission::whereIn('mahasiswa_ta_id', $taIds)
            ->where('status', SeminarSubmission::STATUS_SUBMITTED)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        return view('dashboard.dosen-seminar-jadwal', compact('submissions', 'readIds', 'unreadCount', 'tab', 'jenis'));
    }
}
