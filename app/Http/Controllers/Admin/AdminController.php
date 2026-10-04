<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MahasiswaTa;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserPlanOverride;
use App\Services\LogbookReviewTransition;
use App\Support\Feature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    // ---------------------------------------------------------------- users

    public function users(Request $request): View
    {
        $user = $request->user();
        $isSystemAdmin = $user->isSystemAdmin();

        // Admin tanpa admin_scopes DIKUNCI: tampilkan banner "locked",
        // bukan tabel kosong (UX lebih jelas).
        $isLockedNonSystemAdmin = ! $isSystemAdmin
            && $user->institution_id !== null
            && $user->adminScopes->isEmpty();

        $query = $this->usersQuery($request);

        // Tabs (konteks): tab "Pengguna" default. System admin bisa lihat
        // tab "Semua" (termasuk user lintas institusi + admin/system_admin).
        $tab = $request->query('tab', 'mine');
        if (! $isSystemAdmin) {
            $tab = 'mine';
        } elseif (! in_array($tab, ['mine', 'all'], true)) {
            $tab = 'mine';
        }

        // Tab "all" → longgarkan filter institusi (system admin boleh lintas).
        // Tab "mine" → hanya user di institusi admin (kalau bukan system admin,
        // sudah di-filter oleh usersQuery). Untuk system admin di tab "mine"
        // kita tetap tampilkan semua (system admin tak punya "institusi sendiri"),
        // jadi tab "all" dan "mine" sama untuknya. Tetap dukung untuk kelengkapan.
        $sort = $request->query('sort', 'latest');
        if ($sort === 'name') {
            $query->orderBy('name');
        } else {
            $query->latest();
        }

        $users = $query->with(['roles', 'planOverride'])->paginate(20)->withQueryString();

        // Stat cards (counts) — clone query tanpa pagination/limit.
        $baseQuery = $this->usersQuery($request);
        $counts = [
            'total' => (clone $baseQuery)->count(),
            'dosen' => (clone $baseQuery)->whereHas('roles', fn ($q) => $q->where('name', 'dosen'))->count(),
            'mahasiswa' => (clone $baseQuery)->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))->count(),
            'ditolak' => (clone $baseQuery)->where('registration_status', 'rejected')->count(),
        ];

        $roles = $isSystemAdmin ? Role::all() : Role::where('name', '!=', 'system_admin')->get();
        // Hanya system admin butuh daftar institusi (untuk filter & dropdown set-institusi).
        $institutions = $isSystemAdmin
            ? \App\Models\Institution::orderBy('institution_name')->get()
            : collect();

        // Map kuota efektif per user (halaman ini) — hanya untuk system admin
        // agar tabel bisa menampilkan kolom "Kuota" tanpa N+1.
        // Untuk mahasiswa, angka relevan ditentukan via status program (lihat
        // Feature::storageDisplayMetadata), bukan fallback free-plan yang menyesatkan.
        $quotaMap = collect();
        if ($isSystemAdmin) {
            $quotaMap = $users->getCollection()->mapWithKeys(function ($u) {
                $display = \App\Support\Feature::storageDisplayMetadata($u);

                return [$u->id => [
                    'effective_mb' => $display['mb'],
                    'note' => $display['note'],
                    'legend' => $display['legend'],
                    'has_override' => (bool) $u->planOverride?->storage_limit_mb,
                    'override_mb' => $u->planOverride?->storage_limit_mb,
                ]];
            });
        }

        return view('admin.users', compact('users', 'roles', 'institutions', 'counts', 'isLockedNonSystemAdmin', 'tab', 'quotaMap'));
    }

    /**
     * Bangun query User sesuai filter & scope admin. Dipakai oleh users(),
     * bulkUsers(), dan exportUsers() agar konsisten.
     */
    private function usersQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $user = $request->user();
        $isSystemAdmin = $user->isSystemAdmin();

        $query = User::query();

        // Admin biasa tidak dapat melihat user dengan role system_admin.
        if (! $isSystemAdmin) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', 'system_admin'));

            // Admin biasa hanya melihat user di institusinya sendiri.
            if ($user->institution_id) {
                $query->where('institution_id', $user->institution_id);
            }

            // Admin dengan admin_scopes dibatasi ke scope-nya (hierarkis);
            // admin TANPA admin_scopes DIKUNCI (tidak melihat data).
            $this->applyAdminScopeFilter($query, $user);
        }

        if ($role = $request->query('role')) {
            if ($role === 'system_admin' && ! $isSystemAdmin) {
                $role = null;
            }
            if ($role) {
                $query->role($role);
            }
        }

        if ($keyword = $request->query('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('nim', 'like', "%{$keyword}%");
            });
        }

        // Filter tambahan.
        if ($status = $request->query('status')) {
            $query->where('registration_status', $status);
        }

        if ($institutionId = $request->query('institution_id')) {
            // Hanya system admin boleh filter lintas institusi.
            if ($isSystemAdmin) {
                if ($institutionId === 'none') {
                    $query->whereNull('institution_id');
                } else {
                    $query->where('institution_id', (int) $institutionId);
                }
            }
        }

        if (($verified = $request->query('verified')) !== null && $verified !== '') {
            if ($verified === '1') {
                $query->whereNotNull('email_verified_at');
            } elseif ($verified === '0') {
                $query->whereNull('email_verified_at');
            }
        }

        return $query;
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'nim' => ['nullable', 'string', 'max:30', function ($attr, $value, $fail) {
                if ($value && \App\Models\User::identifierIsTaken($value)) {
                    $fail('NIM/NIDN ini sudah dipakai akun lain.');
                }
            }],
            'nidn' => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                if ($value && \App\Models\User::identifierIsTaken($value)) {
                    $fail('NIM/NIDN ini sudah dipakai akun lain.');
                }
            }],
            'password' => ['required', 'string', 'min:6'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['in:admin,dosen,mahasiswa'],
            'institution_id' => ['nullable', 'exists:institutions,id'],
        ]);

        // Hanya system admin yang dapat membuat user dengan role admin.
        if (in_array('admin', $validated['roles'], true) && !$request->user()->isSystemAdmin()) {
            return back()->with('error', 'Hanya System Admin yang dapat membuat akun admin.');
        }

        // Anti dual-role: admin dan dosen tidak boleh dalam satu akun.
        if (in_array('admin', $validated['roles'], true) && in_array('dosen', $validated['roles'], true)) {
            return back()->with('error', 'Akun admin dan dosen harus dipisah. Pilih salah satu role.');
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'nim' => $validated['nim'] ?? null,
            'nidn' => $validated['nidn'] ?? null,
            'password' => $validated['password'],
            // system_admin pilih institusi tujuan secara eksplisit; admin biasa otomatis ikut institusinya.
            'institution_id' => $request->user()->isSystemAdmin()
                ? $request->input('institution_id')
                : $request->user()->institution_id,
        ]);
        $user->syncRoles($validated['roles']);

        \App\Support\Audit::log('Admin membuat pengguna', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'roles' => $validated['roles'],
        ]);

        return back()->with('success', 'Pengguna berhasil dibuat.');
    }

    /**
     * Ubah institusi user (hanya system admin).
     * Saat user diadopsi ke institusi, data TA user ikut diadopsi (institution_id
     * pada MahasiswaTa ikut terisi). Saat dikeluarkan (institution_id null),
     * data TA kembali personal.
     */
    public function updateUserInstitution(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403, 'Hanya System Admin yang dapat mengubah institusi user.');

        $validated = $request->validate([
            'institution_id' => ['nullable', 'exists:institutions,id'],
        ]);

        $newInstitutionId = $validated['institution_id'] ? (int) $validated['institution_id'] : null;

        // Jika mengadopsi ke institusi, pastikan institusi punya langganan aktif.
        if ($newInstitutionId && !Feature::institutionHasActiveDirectorySubscription($newInstitutionId)) {
            return back()->with('error', 'Institusi tujuan belum punya langganan aktif. Aktifkan langganan dulu.');
        }

        $oldInstitutionId = $user->institution_id;

        // Update user.
        $user->update(['institution_id' => $newInstitutionId]);

        // Adopsi data TA: semua MahasiswaTa milik user ikut pindah institusi.
        if ($newInstitutionId !== $oldInstitutionId) {
            MahasiswaTa::where('user_id', $user->id)
                ->update(['institution_id' => $newInstitutionId]);

            // Jika dosen, adopsi juga TA yang dibimbingnya (pembimbing 1/2).
            if ($user->isDosen()) {
                MahasiswaTa::where(function ($q) use ($user) {
                    $q->where('pembimbing_1_id', $user->id)
                        ->orWhere('pembimbing_2_id', $user->id);
                })->update(['institution_id' => $newInstitutionId]);
            }
        }

        \App\Support\Audit::log('SysAdmin mengubah institusi user', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'institusi_asal' => $oldInstitutionId,
            'institusi_baru' => $newInstitutionId,
        ]);

        $action = $newInstitutionId ? 'diadopsi ke institusi' : 'dikeluarkan dari institusi';
        return back()->with('success', "User '{$user->name}' {$action}.");
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak dapat menghapus akun sendiri.');
        }

        // Admin biasa tidak dapat menghapus user dengan role admin/system_admin.
        if (!$request->user()->isSystemAdmin() && ($user->isAdmin() || $user->isSystemAdmin())) {
            return back()->with('error', 'Hanya System Admin yang dapat menghapus akun admin.');
        }

        // Admin biasa hanya dapat menghapus user di institusinya sendiri.
        if (!$this->canManageUser($request, $user)) {
            return back()->with('error', 'Tidak dapat mengelola user dari institusi lain.');
        }

        $target = ['target_user_id' => $user->id, 'target_email' => $user->email, 'target_name' => $user->name];
        $user->delete();

        \App\Support\Audit::log('Admin menghapus user', $target);

        return back()->with('success', 'Pengguna dihapus.');
    }

    // ------------------------------------------------------- reset password

    /**
     * Reset password user oleh admin.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);

        // Admin biasa tidak dapat reset password user dengan role admin/system_admin.
        if (!$request->user()->isSystemAdmin() && ($user->isAdmin() || $user->isSystemAdmin())) {
            return back()->with('error', 'Hanya System Admin yang dapat reset password akun admin.');
        }

        // Admin biasa hanya dapat reset password user di institusinya sendiri.
        if (!$this->canManageUser($request, $user)) {
            return back()->with('error', 'Tidak dapat mengelola user dari institusi lain.');
        }

        $user->update(['password' => $validated['password']]);

        \App\Support\Audit::log('Admin mereset password user', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ]);

        return back()->with('success', "Password '{$user->name}' berhasil direset.");
    }

    // ------------------------------------------------------- ubah NIDN

    /**
     * Ubah NIDN user oleh admin. System admin boleh lintas institusi; admin
     * institusi hanya untuk user dalam institusi + cakupan admin_scopes-nya.
     * NIDN wajib 10 digit angka, divalidasi keunikan global, dan diaudit.
     */
    public function updateUserNidn(Request $request, User $user): RedirectResponse
    {
        // Scoping: system admin selalu boleh; admin institusi hanya untuk user
        // dalam institusi + admin_scopes (menolak tenant-hopping lintas institusi).
        abort_unless($this->canManageUser($request, $user), 403);

        // Admin biasa tidak boleh mengubah NIDN akun admin/system_admin.
        if (!$request->user()->isSystemAdmin() && ($user->isAdmin() || $user->isSystemAdmin())) {
            return back()->with('error', 'Hanya System Admin yang dapat mengubah NIDN akun admin.');
        }

        $oldNidn = $user->nidn;

        $validated = $request->validate([
            // NIDN persis 10 digit angka; nullable (boleh dikosongkan admin).
            'nidn' => ['nullable', 'string', 'max:20', 'regex:/^\d{10}$/', function ($attr, $value, $fail) use ($user) {
                if ($value && \App\Models\User::identifierIsTaken($value, $user->id)) {
                    $fail('NIDN ini sudah dipakai akun lain (NIM/NIDN).');
                }
            }],
        ]);

        $user->update(['nidn' => $validated['nidn'] ?: null]);

        \App\Support\Audit::log(
            $request->user()->isSystemAdmin() ? 'SysAdmin mengubah NIDN' : 'Admin mengubah NIDN',
            [
                'target_user_id' => $user->id,
                'target_email' => $user->email,
                'nidn_lama' => $oldNidn,
                'nidn_baru' => $validated['nidn'] ?: null,
            ]
        );

        return back()->with('success', "NIDN '{$user->name}' berhasil diperbarui.");
    }

    // ------------------------------------------------------- aksi massal & export

    /**
     * Aksi massal atas user: delete / approve / reject.
     * Otorisasi per-user di-handle via canManageUser(). Aksi yang menyentuh
     * akun admin/system_admin DITOLAK kecuali aktor adalah system admin.
     */
    public function bulkUsers(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
            'action' => ['required', 'in:delete,approve,reject'],
        ]);

        $actor = $request->user();
        $isSystemAdmin = $actor->isSystemAdmin();
        $ids = array_map('intval', $validated['ids']);
        $action = $validated['action'];

        // Kumpulkan user yang boleh dikelola.
        $manageable = [];
        $skipped = [];
        foreach (User::whereIn('id', $ids)->get() as $user) {
            // Lindungi akun admin/system_admin dari admin institusi.
            if (! $isSystemAdmin && ($user->isAdmin() || $user->isSystemAdmin())) {
                $skipped[] = $user->name.' (akun admin)';
                continue;
            }
            // Tidak boleh memproses akun sendiri.
            if ($user->id === $actor->id) {
                $skipped[] = $user->name.' (diri sendiri)';
                continue;
            }
            if (! $this->canManageUser($request, $user)) {
                $skipped[] = $user->name.' (di luar wewenang)';
                continue;
            }
            $manageable[] = $user;
        }

        $count = count($manageable);
        if ($count === 0) {
            return back()->with('error', 'Tidak ada user yang dapat diproses. Lewati: '.implode(', ', $skipped));
        }

        foreach ($manageable as $user) {
            switch ($action) {
                case 'delete':
                    $user->delete();
                    \App\Support\Audit::log('Admin menghapus user (massal)', [
                        'target_user_id' => $user->id,
                        'target_email' => $user->email,
                        'target_name' => $user->name,
                    ]);
                    break;

                case 'approve':
                    // Hanya relevan untuk dosen/mahasiswa; setujui agar jadi verified.
                    if ($user->isMahasiswa()) {
                        $user->update(['registration_status' => 'verified']);
                    } else {
                        $user->update(['registration_status' => 'active']);
                    }
                    \App\Support\Audit::log('Admin menyetujui user (massal)', [
                        'target_user_id' => $user->id,
                        'target_email' => $user->email,
                    ]);
                    break;

                case 'reject':
                    $user->update(['registration_status' => 'rejected']);
                    \App\Support\Audit::log('Admin menolak user (massal)', [
                        'target_user_id' => $user->id,
                        'target_email' => $user->email,
                    ]);
                    break;
            }
        }

        $actionLabel = match ($action) {
            'delete' => 'dihapus',
            'approve' => 'disetujui',
            'reject' => 'ditolak',
        };
        $msg = "{$count} user berhasil {$actionLabel}.";
        if ($skipped) {
            $msg .= ' Lewati: '.implode(', ', $skipped);
        }

        return back()->with('success', $msg);
    }

    /**
     * Export CSV daftar user (mengikuti filter & scope yang sama dengan users()).
     * Untuk Excel-compatible: BOM + comma-separated.
     */
    public function exportUsers(Request $request)
    {
        $filename = 'users-'.now()->format('Y-m-d-His').'.csv';
        $query = $this->usersQuery($request)->with('roles')->orderBy('name');

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM agar Excel detect UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Nama', 'Email', 'Identifier', 'Roles', 'Status', 'Institusi', 'Email Verified', 'Terdaftar'], ';');

            // Streaming chunked untuk dataset besar.
            $query->chunk(200, function ($users) use ($out) {
                foreach ($users as $u) {
                    fputcsv($out, [
                        $u->id,
                        $u->name,
                        $u->email,
                        $u->nim ?? '',
                        $u->roles->pluck('name')->implode(','),
                        $u->registration_status ?? '',
                        optional(\App\Models\Institution::find($u->institution_id))->institution_name ?? 'Personal',
                        $u->email_verified_at ? 'Ya' : 'Tidak',
                        $u->created_at?->format('Y-m-d H:i') ?? '',
                    ], ';');
                }
            });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ------------------------------------------------------- system admin

    /**
     * Daftar semua user dengan role admin (dikelola oleh system admin).
     */
    public function systemAdmins(): View
    {
        $admins = User::role('admin')
            ->with('roles', 'institution', 'adminScopes')
            ->orderBy('name')
            ->get();

        $institutions = \App\Models\Institution::orderBy('institution_name')->get();
        $universities = \App\Models\University::with('faculties.departments.studyPrograms')->orderBy('name')->get();

        return view('admin.system-admins', compact('admins', 'institutions', 'universities'));
    }

    /**
     * Buat akun admin baru (hanya system admin).
     * Di mode institusi: wajib pilih institusi tujuan, dan institusi tsb harus
     * punya minimal 1 directory_subscriptions aktif. Bisa juga langsung assign
     * admin_scopes (prodi/departemen/fakultas) — setiap scope harus ter-cover
     * langganan aktif.
     */
    public function storeSystemAdmin(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'nim' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'scopes' => ['nullable', 'array'],
            'scopes.*.scope_type' => ['required_with:scopes', 'in:university,faculty,department,study_program'],
            'scopes.*.scope_id' => ['required_with:scopes', 'integer'],
        ]);

        // Gate langganan: jika admin dibuat untuk institusi, institusi harus punya langganan aktif.
        if (!empty($validated['institution_id'])) {
            $institutionId = (int) $validated['institution_id'];

            if (!Feature::institutionHasActiveDirectorySubscription($institutionId)) {
                return back()->with('error', 'Aktifkan langganan institusi dulu sebelum membuat akun admin.');
            }

            // Validasi setiap admin_scope: node (atau leluhurnya) harus ter-cover langganan aktif.
            foreach ($validated['scopes'] ?? [] as $scope) {
                if (!Feature::directorySubscriptionActive($scope['scope_type'], (int) $scope['scope_id'])) {
                    return back()->with('error', 'Scope admin tidak ter-cover langganan aktif. Aktifkan langganan node terkait dulu.');
                }
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'nim' => $validated['nim'] ?? null,
            'password' => $validated['password'],
            'institution_id' => $validated['institution_id'] ?? null,
        ]);
        $user->syncRoles(['admin']);

        // Assign admin_scopes (jika ada).
        if (!empty($validated['scopes'])) {
            foreach ($validated['scopes'] as $scope) {
                \App\Models\AdminScope::create([
                    'user_id' => $user->id,
                    'institution_id' => $user->institution_id,
                    'scope_type' => $scope['scope_type'],
                    'scope_id' => (int) $scope['scope_id'],
                    'granted_by' => $request->user()->id,
                    'status' => \App\Models\AdminScope::STATUS_ACTIVE,
                ]);
            }
        }

        \App\Support\Audit::log('SysAdmin membuat akun admin', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'institution_id' => $user->institution_id,
            'scopes' => array_map(fn ($s) => $s['scope_type'].':'.$s['scope_id'], $validated['scopes'] ?? []),
        ]);

        return back()->with('success', 'Akun admin berhasil dibuat.');
    }

    /**
     * Buat akun admin di bawah hierarki (hanya admin dengan admin_scopes).
     *
     * Aturan:
     * - Hanya aktif di mode institusi.
     * - Admin pembuat harus punya minimal 1 admin_scope aktif.
     * - Scope admin baru harus TURUNAN dari scope admin pembuat (tidak boleh
     *   lebih luas, tidak boleh di luar cakupan).
     * - Node tujuan tetap wajib ter-cover directory_subscriptions aktif.
     */
    public function storeSubAdmin(Request $request): RedirectResponse
    {
        // Hanya admin (bukan system_admin) yang bisa membuat sub-admin.
        abort_unless($request->user()->hasRole('admin') && !$request->user()->isSystemAdmin(), 403);

        // Wajib punya permission admin.create-admin.
        abort_unless($request->user()->hasPermissionTo('admin.create-admin'), 403, 'Anda tidak memiliki izin untuk membuat admin.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'nim' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*.scope_type' => ['required', 'in:university,faculty,department,study_program'],
            'scopes.*.scope_id' => ['required', 'integer'],
        ]);

        $creator = $request->user();
        $creatorScopes = \App\Models\AdminScope::activeFor($creator);

        // Admin pembuat harus punya scope (tidak bisa buat sub-admin tanpa scope).
        if ($creatorScopes->isEmpty()) {
            return back()->with('error', 'Anda tidak memiliki scope admin. Hanya admin dengan scope yang dapat membuat admin di bawahnya.');
        }

        // Validasi setiap scope baru: harus turunan dari minimal 1 scope pembuat.
        foreach ($validated['scopes'] as $scope) {
            $isDescendant = false;

            foreach ($creatorScopes as $creatorScope) {
                if ($this->isScopeDescendantOf(
                    $scope['scope_type'],
                    (int) $scope['scope_id'],
                    $creatorScope->scope_type,
                    $creatorScope->scope_id
                )) {
                    $isDescendant = true;
                    break;
                }
            }

            if (!$isDescendant) {
                return back()->with('error', 'Scope admin baru harus berada di bawah cakupan scope Anda.');
            }

            // Node tujuan tetap wajib ter-cover langganan aktif.
            if (!Feature::directorySubscriptionActive($scope['scope_type'], (int) $scope['scope_id'])) {
                return back()->with('error', 'Scope admin tidak ter-cover langganan aktif. Aktifkan langganan node terkait dulu.');
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'nim' => $validated['nim'] ?? null,
            'password' => $validated['password'],
            'institution_id' => $creator->institution_id,
        ]);
        $user->syncRoles(['admin']);

        // Assign admin_scopes.
        foreach ($validated['scopes'] as $scope) {
            \App\Models\AdminScope::create([
                'user_id' => $user->id,
                'institution_id' => $user->institution_id,
                'scope_type' => $scope['scope_type'],
                'scope_id' => (int) $scope['scope_id'],
                'granted_by' => $creator->id,
                'status' => \App\Models\AdminScope::STATUS_ACTIVE,
            ]);
        }

        \App\Support\Audit::log('Admin membuat sub-admin', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'scopes' => array_map(fn ($s) => $s['scope_type'].':'.$s['scope_id'], $validated['scopes']),
        ]);

        return back()->with('success', 'Akun admin berhasil dibuat.');
    }

    /**
     * Cek apakah node (childType, childId) adalah turunan dari (parentType, parentId).
     * Menggunakan Feature::directoryChain() untuk walk up dari child ke leluhurnya.
     */
    private function isScopeDescendantOf(string $childType, int $childId, string $parentType, int $parentId): bool
    {
        if ($childType === $parentType && $childId === $parentId) {
            return false; // bukan turunan, node yang sama
        }

        $chain = Feature::directoryChain($childType, $childId);

        foreach ($chain as $node) {
            if ($node['type'] === $parentType && $node['id'] === $parentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hapus akun admin (hanya system admin).
     */
    public function destroySystemAdmin(Request $request, User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak dapat menghapus akun sendiri.');
        }

        // Tidak dapat menghapus system admin lain.
        if ($user->isSystemAdmin()) {
            return back()->with('error', 'Tidak dapat menghapus akun System Admin.');
        }

        // Hanya user dengan role admin (bukan system_admin) yang bisa dihapus di sini.
        if (!$user->hasRole('admin')) {
            return back()->with('error', 'User ini bukan akun admin.');
        }

        $target = ['target_user_id' => $user->id, 'target_email' => $user->email, 'target_name' => $user->name];
        $user->delete();

        \App\Support\Audit::log('SysAdmin menghapus akun admin', $target);

        return back()->with('success', 'Akun admin dihapus.');
    }

    /**
     * Reset password akun admin (hanya system admin).
     */
    public function resetSystemAdminPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);

        if (!$user->hasRole('admin')) {
            return back()->with('error', 'User ini bukan akun admin.');
        }

        $user->update(['password' => $validated['password']]);

        \App\Support\Audit::log('SysAdmin mereset password admin', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ]);

        return back()->with('success', "Password admin '{$user->name}' berhasil direset.");
    }

    // ---------------------------------------------------------------- TA & assignment

    public function tas(Request $request): View
    {
        $jenis = $request->query('jenis', 'ta');
        $query = MahasiswaTa::with(['mahasiswa', 'pembimbing1', 'pembimbing2', 'members'])->withCount('entries');

        if ($jenis === 'kp' || $jenis === 'ta') {
            $query->jenis($jenis);
        }

        if ($request->query('keyword')) {
            $kw = $request->query('keyword');
            $query->where(function ($q) use ($kw) {
                $q->where('judul_ta', 'like', "%{$kw}%")
                    ->orWhere('tempat_kp', 'like', "%{$kw}%");
            });
        }

        if ($pembimbing = $request->query('pembimbing')) {
            $query->where(function ($q) use ($pembimbing) {
                $q->where('pembimbing_1_id', $pembimbing)->orWhere('pembimbing_2_id', $pembimbing);
            });
        }

        // Fase D: admin dengan admin_scopes aktif dibatasi ke scope-nya (hierarkis);
        // admin TANPA admin_scopes dikunci (tidak melihat data di luar institusi/scope).
        if (!$request->user()->isSystemAdmin()) {
            $this->applyAdminScopeFilterToTa($query, $request->user());
        }

        $tas = $query->paginate(20)->withQueryString();

        // Dosen & mahasiswa yang bisa dipilih dibatasi ke institusi yang sama.
        $institutionFilter = fn ($q) => !$request->user()->isSystemAdmin() && $request->user()->institution_id
            ? $q->where('institution_id', $request->user()->institution_id)
            : $q;

        $dosenQuery = $institutionFilter(User::role('dosen'))->orderBy('name');
        $mahasiswaQuery = $institutionFilter(
            User::role('mahasiswa')->whereDoesntHave('mahasiswaPrograms', fn ($q) => $q->where('jenis', $jenis))
        )->orderBy('name');

        if (!$request->user()->isSystemAdmin()) {
            $this->applyAdminScopeFilter($dosenQuery, $request->user());
            $this->applyAdminScopeFilter($mahasiswaQuery, $request->user());
        }

        $dosenList = $dosenQuery->get();
        $mahasiswaList = $mahasiswaQuery->get();

        // Dropdown "Anggota Kelompok Lainnya" (KP): sertakan juga mahasiswa
        // yang sudah punya KP sendiri (kasus penggabungan), bukan hanya yang
        // belum punya KP. Per-program: anggota existing + kandidat eligible.
        $kpMemberCandidates = [];
        foreach ($tas->where('jenis', MahasiswaTa::JENIS_KP) as $ta) {
            $kpMemberCandidates[$ta->id] = $ta->allMembers()
                ->merge($ta->eligibleMemberCandidates())
                ->unique('id')
                ->values();
        }
        // Untuk form "Buat Data KP": kandidat anggota juga dari pool yang lebih luas.
        $kpNewMemberCandidates = MahasiswaTa::kpNewMemberCandidates();
        if (! $request->user()->isSystemAdmin() && $request->user()->institution_id) {
            $kpNewMemberCandidates = $kpNewMemberCandidates
                ->where('institution_id', $request->user()->institution_id)
                ->values();
        }

        return view('admin.tas', compact('tas', 'dosenList', 'mahasiswaList', 'jenis', 'kpMemberCandidates', 'kpNewMemberCandidates'));
    }

    public function storeTa(Request $request): RedirectResponse
    {
        $jenis = $request->input('jenis', MahasiswaTa::JENIS_TA);
        $isKp = $jenis === MahasiswaTa::JENIS_KP;

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id',
                \Illuminate\Validation\Rule::unique('mahasiswa_ta', 'user_id')
                    ->where(fn ($q) => $q->where('jenis', $jenis)),
            ],
            'jenis' => ['required', 'in:'.implode(',', MahasiswaTa::JENISES)],
            'judul_ta' => ['nullable', 'string', 'max:255'],
            'tempat_kp' => ['nullable', 'string', 'max:255'],
            'pembimbing_1_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'pembimbing_2_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'pembimbing_lapangan' => [$isKp ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'penguji_1_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'penguji_2_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'target_sesi' => ['required', 'integer', 'min:1'],
            'periode_mulai' => ['nullable', 'date'],
            'periode_selesai' => ['nullable', 'date', 'after_or_equal:periode_mulai'],
            'member_ids' => [$isKp ? 'nullable' : 'prohibited', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id', $this->roleRule('mahasiswa')],
        ]);

        // Admin biasa: program otomatis masuk institusinya; hanya boleh untuk
        // mahasiswa di institusi & scope-nya.
        if (!$request->user()->isSystemAdmin() && $request->user()->institution_id) {
            $targetUser = User::find($validated['user_id']);
            if (! $targetUser || ! $this->canManageUser($request, $targetUser)) {
                return back()->with('error', 'Tidak dapat membuat program untuk mahasiswa di luar cakupan Anda.');
            }
            $validated['institution_id'] = $request->user()->institution_id;
        }

        $program = MahasiswaTa::create($validated);

        // Anggota kelompok tambahan (khusus KP) — validasi satu-program-KP &
        // nonaktifkan program lama milik anggota yang digabung.
        if ($isKp && !empty($validated['member_ids'])) {
            $memberIds = array_values(array_unique(
                array_diff($validated['member_ids'], [$program->user_id])
            ));

            foreach ($memberIds as $mid) {
                $candidate = User::find($mid);
                if (! $candidate || ! MahasiswaTa::kpCandidateEligible($candidate, $program->id)) {
                    return back()->with('error', 'Salah satu anggota telah menjadi anggota kelompok KP lain.');
                }
            }

            $program->members()->sync($memberIds);

            foreach ($memberIds as $mid) {
                MahasiswaTa::deactivateKpExcept(User::find($mid), $program->id);
            }
        }

        return back()->with('success', 'Data '.($isKp ? 'KP' : 'TA').' dibuat.');
    }

    public function updateTa(Request $request, MahasiswaTa $mahasiswaTa): RedirectResponse
    {
        // Admin biasa hanya dapat mengubah program di institusinya sendiri.
        if (!$this->canManageTa($request, $mahasiswaTa)) {
            return back()->with('error', 'Tidak dapat mengelola data dari institusi lain.');
        }

        $isKp = $mahasiswaTa->isKp();

        $validated = $request->validate([
            'judul_ta' => ['nullable', 'string', 'max:255'],
            'tempat_kp' => ['nullable', 'string', 'max:255'],
            'pembimbing_1_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'pembimbing_2_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'pembimbing_lapangan' => [$isKp ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'penguji_1_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'penguji_2_id' => ['nullable', 'exists:users,id', $this->roleRule('dosen')],
            'target_sesi' => ['required', 'integer', 'min:1'],
            'periode_mulai' => ['nullable', 'date'],
            'periode_selesai' => ['nullable', 'date', 'after_or_equal:periode_mulai'],
            'status_ta' => ['nullable', 'in:'.implode(',', \App\Models\MahasiswaTa::STATUS_TA)],
            'member_ids' => [$isKp ? 'nullable' : 'prohibited', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id', $this->roleRule('mahasiswa')],
        ]);

        $mahasiswaTa->update($validated);

        // Sinkronkan anggota kelompok tambahan (khusus KP).
        // Validasi satu-program-KP + nonaktifkan program lama milik anggota
        // yang digabung (tanpa menghapus data).
        if ($isKp) {
            $memberIds = array_values(array_unique(
                array_diff($validated['member_ids'] ?? [], [$mahasiswaTa->user_id])
            ));

            foreach ($memberIds as $mid) {
                $candidate = \App\Models\User::find($mid);
                if (! $candidate || ! \App\Models\MahasiswaTa::kpCandidateEligible($candidate, $mahasiswaTa->id)) {
                    return back()->with('error', 'Salah satu anggota telah menjadi anggota kelompok KP lain dan tidak dapat digabung.');
                }
            }

            $mahasiswaTa->members()->sync($memberIds);

            foreach ($memberIds as $mid) {
                \App\Models\MahasiswaTa::deactivateKpExcept(\App\Models\User::find($mid), $mahasiswaTa->id);
            }
        }

        return back()->with('success', 'Data '.($isKp ? 'KP' : 'TA').' diperbarui.');
    }

    // ------------------------------------------------------- sidang (admin)

    public function sidangs(Request $request): View
    {
        $query = \App\Models\Sidang::with(['mahasiswaTa.mahasiswa', 'penguji']);

        // Sidang tidak punya InstitutionScope — filter manual ke institusi admin.
        if (!$request->user()->isSystemAdmin()) {
            if ($request->user()->institution_id) {
                $query->where('institution_id', $request->user()->institution_id);
            }

            // Admin dengan admin_scopes dibatasi ke scope-nya (hierarkis);
            // admin TANPA admin_scopes dikunci.
            $this->applyAdminScopeFilterToTa($query, $request->user(), 'mahasiswaTa');
        }

        $sidangs = $query->orderByDesc('tanggal')->paginate(20)->withQueryString();

        // Dosen & mahasiswa yang bisa dipilih dibatasi ke institusi yang sama,
        // lalu ke admin_scopes (hierarkis) untuk admin non-system.
        $institutionFilter = fn ($q) => !$request->user()->isSystemAdmin() && $request->user()->institution_id
            ? $q->where('institution_id', $request->user()->institution_id)
            : $q;

        $mahasiswaQuery = $institutionFilter(MahasiswaTa::with('mahasiswa'));
        $dosenQuery = $institutionFilter(User::role('dosen'))->orderBy('name');

        if (!$request->user()->isSystemAdmin()) {
            $this->applyAdminScopeFilterToTa($mahasiswaQuery, $request->user());
            $this->applyAdminScopeFilter($dosenQuery, $request->user());
        }

        $mahasiswaList = $mahasiswaQuery->get();
        $dosenList = $dosenQuery->get();

        return view('admin.sidangs', compact('sidangs', 'mahasiswaList', 'dosenList'));
    }

    public function storeSidang(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mahasiswa_ta_id' => ['required', 'exists:mahasiswa_ta,id'],
            'penguji_id' => ['required', 'exists:users,id', $this->roleRule('dosen')],
            'jenis' => ['required', 'in:'.implode(',', \App\Models\Sidang::JENISES)],
            'tanggal' => ['required', 'date'],
            'hasil' => ['nullable', 'in:'.implode(',', \App\Models\Sidang::HASILS)],
        ]);

        // Admin biasa: sidang otomatis masuk institusinya; hanya untuk TA di scope-nya.
        if (!$request->user()->isSystemAdmin() && $request->user()->institution_id) {
            $ta = MahasiswaTa::find($validated['mahasiswa_ta_id']);
            if (! $ta || ! $this->canManageTa($request, $ta)) {
                return back()->with('error', 'Tidak dapat membuat sidang untuk program di luar cakupan Anda.');
            }
            $validated['institution_id'] = $request->user()->institution_id;
        }

        \App\Models\Sidang::create($validated);

        // Integrasi: jika sidang akhir & hasil lulus/lulus_revisi -> set status_ta tamat.
        if ($validated['jenis'] === \App\Models\Sidang::JENIS_SIDANG
            && in_array($validated['hasil'] ?? null, [\App\Models\Sidang::HASIL_LULUS, \App\Models\Sidang::HASIL_LULUS_REVISI], true)) {
            MahasiswaTa::where('id', $validated['mahasiswa_ta_id'])
                ->update(['status_ta' => MahasiswaTa::STATUS_TAMAT]);
        }

        return back()->with('success', 'Data sidang ditambahkan.');
    }

    public function destroySidang(Request $request, \App\Models\Sidang $sidang): RedirectResponse
    {
        // Admin biasa hanya dapat menghapus sidang di institusinya & scope-nya.
        if (!$request->user()->isSystemAdmin() && $request->user()->institution_id) {
            $ta = $sidang->mahasiswaTa;
            if ($sidang->institution_id !== $request->user()->institution_id
                || ! $ta
                || ! $this->canManageTa($request, $ta)) {
                return back()->with('error', 'Tidak dapat mengelola data dari institusi lain.');
            }
        }

        $sidang->delete();

        return back()->with('success', 'Data sidang dihapus.');
    }

    /**
     * Set status_ta (aktif/tamat/nonaktif) oleh admin.
     */
    public function updateStatusTa(Request $request, MahasiswaTa $mahasiswaTa): RedirectResponse
    {
        // Admin biasa hanya dapat mengubah status program di institusinya sendiri.
        if (!$this->canManageTa($request, $mahasiswaTa)) {
            return back()->with('error', 'Tidak dapat mengelola data dari institusi lain.');
        }

        $validated = $request->validate([
            'status_ta' => ['required', 'in:'.implode(',', MahasiswaTa::STATUS_TA)],
        ]);

        $mahasiswaTa->update(['status_ta' => $validated['status_ta']]);

        return back()->with('success', 'Status program diperbarui.');
    }

    // ------------------------------------------------------- paket & override

    /**
     * Form pengaturan paket (free/donasi) + override admin per user.
     */
    public function planSettings(User $user): View
    {
        // Defense-in-depth: route sudah di-gate `role:system_admin`, tapi
        // pastikan juga di controller untuk berjaga-jaga.
        abort_unless(auth()->user()->isSystemAdmin(), 403, 'Hanya System Admin yang dapat mengatur paket user.');

        $plans = Plan::where('is_active', true)->orderBy('price')->get();
        $activePlan = $user->activePlan();
        $override = $user->planOverride;
        $institutionHasSubscription = $user->institution_id
            ? Feature::institutionHasActiveDirectorySubscription($user->institution_id)
            : false;

        return view('admin.plan-settings', compact('user', 'plans', 'activePlan', 'override', 'institutionHasSubscription'));
    }

    /**
     * Simpan paket & override admin untuk user.
     */
    public function updatePlanSettings(Request $request, User $user): RedirectResponse
    {
        abort_unless(auth()->user()->isSystemAdmin(), 403, 'Hanya System Admin yang dapat mengubah paket user.');

        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'allow_export' => ['nullable', 'boolean'],
            'allow_import' => ['nullable', 'boolean'],
            'storage_limit_mb' => ['nullable', 'integer', 'min:0', 'max:1048576'],
            'institution_storage_limit_mb' => ['nullable', 'integer', 'min:0', 'max:1048576'],
        ]);

        // Set subscription aktif (nonaktifkan yang lain).
        Subscription::where('user_id', $user->id)->update(['status' => 'cancelled']);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $validated['plan_id'],
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
        ]);

        // Override admin (nullable = ikut plan).
        UserPlanOverride::updateOrCreate(
            ['user_id' => $user->id],
            [
                'allow_export' => $request->boolean('allow_export') ? true : null,
                'allow_import' => $request->boolean('allow_import') ? true : null,
                'storage_limit_mb' => $validated['storage_limit_mb'] ?: null,
            ]
        );

        // Batas per-user dalam pool institusi (hanya untuk user institusi).
        if ($user->institution_id) {
            $user->update([
                'institution_storage_limit_mb' => $validated['institution_storage_limit_mb'] ?: null,
            ]);
        }

        \App\Support\Audit::log('Admin mengubah paket langganan user', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'plan_id' => $validated['plan_id'],
        ]);

        return back()->with('success', "Paket '{$user->name}' diperbarui.");
    }

    /**
     * Set kuota individu (override storage) untuk user — system admin.
     * Jalur cepat dari halaman Kelola Pengguna (tanpa membuka halaman paket penuh).
     */
    public function updateUserQuota(Request $request, User $user): RedirectResponse
    {
        abort_unless(auth()->user()->isSystemAdmin(), 403, 'Hanya System Admin yang dapat mengatur kuota user.');

        $validated = $request->validate([
            'storage_limit_mb' => ['nullable', 'integer', 'min:0', 'max:1048576'],
        ]);

        // Kosongkan/0 = ikut paket/pool (hapus override).
        UserPlanOverride::updateOrCreate(
            ['user_id' => $user->id],
            [
                'allow_export' => $user->planOverride?->allow_export ?? null,
                'allow_import' => $user->planOverride?->allow_import ?? null,
                'storage_limit_mb' => (! empty($validated['storage_limit_mb']) && $validated['storage_limit_mb'] > 0)
                    ? (int) $validated['storage_limit_mb']
                    : null,
            ]
        );

        \App\Support\Audit::log('SysAdmin mengatur kuota individu user', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'storage_limit_mb' => $validated['storage_limit_mb'] ?? null,
        ]);

        $mb = $validated['storage_limit_mb'] ?? null;
        return back()->with('success', $mb && $mb > 0
            ? "Kuota individu '{$user->name}' diatur ke {$mb} MB (override aktif)."
            : "Override kuota '{$user->name}' dihapus — kini mengikuti paket/pool.");
    }

    /**
     * Halaman kuota dosen per institusi (admin institusi yang berlangganan).
     * Hanya untuk dosen di institusi milik admin (bukan system admin).
     */
    public function dosenQuota(User $user): View
    {
        $admin = auth()->user();
        abort_unless($admin->isSystemAdmin() || $admin->isAdmin(), 403);

        // Non-system admin: hanya untuk dosen di institusi yang sama.
        if (! $admin->isSystemAdmin()) {
            abort_unless($user->isDosen() && $user->institution_id === $admin->institution_id, 403, 'Anda hanya dapat mengatur kuota dosen di institusi Anda.');
        }

        $institution = Institution::current();
        $poolMb = Feature::institutionStorageLimitMb($user->institution_id ?: $admin->institution_id);

        return view('admin.dosen-quota', compact('user', 'institution', 'poolMb'));
    }

    /**
     * Simpan kuota per-user (dalam pool institusi) untuk dosen — oleh admin institusi.
     * Hanya mengubah institution_storage_limit_mb (tidak menyentuh plan/override).
     */
    public function updateDosenQuota(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin->isSystemAdmin() || $admin->isAdmin(), 403);

        // Non-system admin: hanya untuk dosen di institusi yang sama.
        if (! $admin->isSystemAdmin()) {
            abort_unless($user->isDosen() && $user->institution_id === $admin->institution_id, 403, 'Anda hanya dapat mengatur kuota dosen di institusi Anda.');
        }

        $validated = $request->validate([
            'institution_storage_limit_mb' => ['nullable', 'integer', 'min:0', 'max:1048576'],
        ]);

        $user->update([
            'institution_storage_limit_mb' => $validated['institution_storage_limit_mb'] ?: null,
        ]);

        \App\Support\Audit::log('Admin institusi mengatur kuota dosen', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'quota_mb' => $validated['institution_storage_limit_mb'] ?: null,
        ]);

        return back()->with('success', "Kuota dosen '{$user->name}' diperbarui.");
    }

    // ------------------------------------------------------- langganan direktori (system admin)

    /**
     * Halaman kelola langganan direktori (institusi).
     * Menampilkan semua directory_subscriptions + form assign baru.
     */
    public function directorySubscriptions(): View
    {
        $subscriptions = \App\Models\DirectorySubscription::with('assignedBy')
            ->orderByDesc('created_at')
            ->get();

        $universities = \App\Models\University::with('faculties.departments.studyPrograms')->orderBy('name')->get();

        return view('admin.system.directory-subscriptions', compact('subscriptions', 'universities'));
    }

    /**
     * Assign langganan baru ke node direktori.
     * Validasi no-overlap: leluhur/turunan tidak boleh sudah berlangganan aktif.
     */
    public function storeDirectorySubscription(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:study_program,department,faculty,university'],
            'scope_id' => ['required', 'integer'],
            'storage_limit_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'ends_at' => ['nullable', 'date', 'after:today'],
        ]);

        // Validasi no-overlap.
        $error = Feature::validateDirectorySubscriptionNoOverlap(
            $validated['scope_type'],
            (int) $validated['scope_id']
        );

        if ($error) {
            return back()->with('error', $error);
        }

        \App\Models\DirectorySubscription::create([
            'scope_type' => $validated['scope_type'],
            'scope_id' => (int) $validated['scope_id'],
            'storage_limit_mb' => (int) $validated['storage_limit_mb'],
            'status' => \App\Models\DirectorySubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => $validated['ends_at'] ?? null,
            'assigned_by' => $request->user()->id,
        ]);

        \App\Support\Audit::log('SysAdmin assign langganan direktori', [
            'scope_type' => $validated['scope_type'],
            'scope_id' => (int) $validated['scope_id'],
            'storage_limit_mb' => (int) $validated['storage_limit_mb'],
        ]);

        return back()->with('success', 'Langganan direktori berhasil di-assign.');
    }

    /**
     * Batalkan langganan direktori (soft-cancel, bukan hapus).
     */
    public function cancelDirectorySubscription(Request $request, \App\Models\DirectorySubscription $subscription): RedirectResponse
    {
        $subscription->update(['status' => \App\Models\DirectorySubscription::STATUS_CANCELLED]);

        \App\Support\Audit::log('SysAdmin membatalkan langganan direktori', [
            'subscription_id' => $subscription->id,
            'scope_type' => $subscription->scope_type,
            'scope_id' => (int) $subscription->scope_id,
        ]);

        return back()->with('success', 'Langganan direktori dibatalkan.');
    }

    /**
     * Form edit langganan direktori (ganti plan, ends_at, status).
     */
    public function editDirectorySubscription(\App\Models\DirectorySubscription $subscription): View
    {
        $subscription->loadMissing('assignedBy');

        $universities = \App\Models\University::with('faculties.departments.studyPrograms')->orderBy('name')->get();

        return view('admin.system.directory-subscriptions-edit', compact('subscription', 'universities'));
    }

    /**
     * Simpan perubahan langganan direktori (pool storage, ends_at, status).
     * Scope tidak bisa diubah; tetap jalankan validasi no-overlap utk integritas.
     */
    public function updateDirectorySubscription(Request $request, \App\Models\DirectorySubscription $subscription): RedirectResponse
    {
        $validated = $request->validate([
            'storage_limit_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'ends_at' => ['nullable', 'date', 'after:today'],
            'status' => ['required', 'in:active,expired,cancelled'],
        ]);

        // No-overlap: scope tidak berubah, tapi pastikan tidak bertentangan
        // dengan langganan lain (leluhur/turunan) saat status diaktifkan kembali.
        $error = Feature::validateDirectorySubscriptionNoOverlap(
            $subscription->scope_type,
            (int) $subscription->scope_id
        );

        if ($error) {
            return back()->with('error', $error);
        }

        $subscription->update([
            'storage_limit_mb' => (int) $validated['storage_limit_mb'],
            'ends_at' => $validated['ends_at'] ?? null,
            'status' => $validated['status'],
        ]);

        \App\Support\Audit::log('SysAdmin mengubah langganan direktori', [
            'subscription_id' => $subscription->id,
            'scope_type' => $subscription->scope_type,
            'scope_id' => (int) $subscription->scope_id,
            'storage_limit_mb' => (int) $validated['storage_limit_mb'],
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Langganan direktori berhasil diperbarui.');
    }

    // ------------------------------------------------------- struktur direktori (system admin)

    /**
     * Halaman kelola struktur direktori (universitas/fakultas/departemen/prodi).
     */
    public function directory(): View
    {
        $universities = \App\Models\University::with('faculties.departments.studyPrograms')->orderBy('name')->get();

        return view('admin.system.directory', compact('universities'));
    }

    /**
     * Tambah universitas (dedup berdasarkan nama).
     */
    public function storeDirectoryUniversity(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $university = app(\App\Services\OrganizationalDirectoryService::class)
            ->findOrCreateUniversity($validated['name']);

        \App\Support\Audit::log('SysAdmin menambah universitas', [
            'university_id' => $university->id,
            'name' => $university->name,
        ]);

        return back()->with('success', "Universitas '{$university->name}' ditambahkan/diduplikasi.");
    }

    /**
     * Form edit nama universitas (perbaiki nama yang salah).
     */
    public function editDirectoryUniversity(\App\Models\University $university): View
    {
        return view('admin.system.directory-university-edit', compact('university'));
    }

    /**
     * Simpan perubahan nama universitas.
     */
    public function updateDirectoryUniversity(Request $request, \App\Models\University $university): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // Cegah duplikat nama dengan universitas lain.
        $duplicate = \App\Models\University::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->where('id', '!=', $university->id)
            ->exists();
        if ($duplicate) {
            return back()->with('error', 'Nama universitas sudah dipakai universitas lain.');
        }

        $university->update(['name' => trim($validated['name'])]);

        \App\Support\Audit::log('SysAdmin mengubah nama universitas', [
            'university_id' => $university->id,
            'name' => $university->name,
        ]);

        return redirect()->route('admin.system.directory')->with('success', "Nama universitas diperbarui menjadi '{$university->name}'.");
    }

    /**
     * Form edit nama fakultas.
     */
    public function editDirectoryFaculty(\App\Models\Faculty $faculty): View
    {
        return view('admin.system.directory-node-edit', [
            'node' => $faculty,
            'title' => 'Edit Fakultas',
            'route' => 'admin.system.directory.faculties.update',
        ]);
    }

    /**
     * Simpan perubahan nama fakultas.
     */
    public function updateDirectoryFaculty(Request $request, \App\Models\Faculty $faculty): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $duplicate = $faculty->university->faculties()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->where('id', '!=', $faculty->id)
            ->exists();
        if ($duplicate) {
            return back()->with('error', 'Nama fakultas sudah dipakai di universitas ini.');
        }

        $faculty->update(['name' => trim($validated['name'])]);

        \App\Support\Audit::log('SysAdmin mengubah nama fakultas', [
            'faculty_id' => $faculty->id,
            'name' => $faculty->name,
        ]);

        return redirect()->route('admin.system.directory')->with('success', "Nama fakultas diperbarui menjadi '{$faculty->name}'.");
    }

    /**
     * Form edit nama departemen.
     */
    public function editDirectoryDepartment(\App\Models\Department $department): View
    {
        return view('admin.system.directory-node-edit', [
            'node' => $department,
            'title' => 'Edit Departemen',
            'route' => 'admin.system.directory.departments.update',
        ]);
    }

    /**
     * Simpan perubahan nama departemen.
     */
    public function updateDirectoryDepartment(Request $request, \App\Models\Department $department): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $duplicate = $department->faculty->departments()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->where('id', '!=', $department->id)
            ->exists();
        if ($duplicate) {
            return back()->with('error', 'Nama departemen sudah dipakai di fakultas ini.');
        }

        $department->update(['name' => trim($validated['name'])]);

        \App\Support\Audit::log('SysAdmin mengubah nama departemen', [
            'department_id' => $department->id,
            'name' => $department->name,
        ]);

        return redirect()->route('admin.system.directory')->with('success', "Nama departemen diperbarui menjadi '{$department->name}'.");
    }

    /**
     * Form edit nama prodi (+ kode).
     */
    public function editDirectoryStudyProgram(\App\Models\StudyProgram $studyProgram): View
    {
        return view('admin.system.directory-node-edit', [
            'node' => $studyProgram,
            'title' => 'Edit Program Studi',
            'route' => 'admin.system.directory.study-programs.update',
        ]);
    }

    /**
     * Simpan perubahan nama/kode prodi.
     */
    public function updateDirectoryStudyProgram(Request $request, \App\Models\StudyProgram $studyProgram): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $duplicate = $studyProgram->department->studyPrograms()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->where('id', '!=', $studyProgram->id)
            ->exists();
        if ($duplicate) {
            return back()->with('error', 'Nama prodi sudah dipakai di departemen ini.');
        }

        $studyProgram->update([
            'name' => trim($validated['name']),
            'code' => $validated['code'] ?? null,
        ]);

        \App\Support\Audit::log('SysAdmin mengubah nama prodi', [
            'study_program_id' => $studyProgram->id,
            'name' => $studyProgram->name,
        ]);

        return redirect()->route('admin.system.directory')->with('success', "Nama prodi diperbarui menjadi '{$studyProgram->name}'.");
    }

    /**
     * Tambah fakultas di dalam universitas (dedup berdasarkan nama).
     */
    public function storeDirectoryFaculty(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'university_id' => ['required', 'exists:universities,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $university = \App\Models\University::findOrFail((int) $validated['university_id']);
        $faculty = app(\App\Services\OrganizationalDirectoryService::class)
            ->findOrCreateFaculty($university, $validated['name']);

        \App\Support\Audit::log('SysAdmin menambah fakultas', [
            'faculty_id' => $faculty->id,
            'university_id' => $university->id,
            'name' => $faculty->name,
        ]);

        return back()->with('success', "Fakultas '{$faculty->name}' ditambahkan.");
    }

    /**
     * Tambah departemen di dalam fakultas (dedup berdasarkan nama).
     */
    public function storeDirectoryDepartment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'faculty_id' => ['required', 'exists:faculties,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $faculty = \App\Models\Faculty::findOrFail((int) $validated['faculty_id']);
        $department = app(\App\Services\OrganizationalDirectoryService::class)
            ->findOrCreateDepartment($faculty, $validated['name']);

        \App\Support\Audit::log('SysAdmin menambah departemen', [
            'department_id' => $department->id,
            'faculty_id' => $faculty->id,
            'name' => $department->name,
        ]);

        return back()->with('success', "Departemen '{$department->name}' ditambahkan.");
    }

    /**
     * Tambah prodi di dalam departemen (dedup berdasarkan nama).
     */
    public function storeDirectoryStudyProgram(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $department = \App\Models\Department::findOrFail((int) $validated['department_id']);
        $studyProgram = app(\App\Services\OrganizationalDirectoryService::class)
            ->findOrCreateStudyProgram($department, $validated['name'], $validated['code'] ?? null);

        \App\Support\Audit::log('SysAdmin menambah prodi', [
            'study_program_id' => $studyProgram->id,
            'department_id' => $department->id,
            'name' => $studyProgram->name,
        ]);

        return back()->with('success', "Prodi '{$studyProgram->name}' ditambahkan.");
    }

    // ------------------------------------------------------- permissions (system admin)

    /**
     * Halaman kelola hak akses: matrix permission per role + pengaturan paket.
     */
    public function permissions(): View
    {
        $roles = Role::orderBy('name')->get();
        $permissions = Permission::orderBy('name')->get();
        $plans = Plan::orderBy('price')->get();

        return view('admin.system.permissions', compact('roles', 'permissions', 'plans'));
    }

    /**
     * Simpan matrix permission per role (global).
     */
    public function updatePermissions(Request $request): RedirectResponse
    {
        $roles = Role::all();

        foreach ($roles as $role) {
            $granted = $request->input("permissions.{$role->id}", []);
            $role->syncPermissions($granted);
        }

        \App\Support\Audit::log('Admin mengubah hak akses (permissions) role', [
            'permissions_per_role' => $request->input('permissions', []),
        ]);

        return back()->with('success', 'Hak akses role berhasil diperbarui.');
    }

    /**
     * Simpan pengaturan fitur paket (storage, export, import).
     */
    public function updatePlanFeatures(Request $request): RedirectResponse
    {
        $plans = Plan::all();

        foreach ($plans as $plan) {
            $validated = $request->validate([
                "plans.{$plan->id}.label" => ['required', 'string', 'max:255'],
                "plans.{$plan->id}.price" => ['required', 'numeric', 'min:0'],
                "plans.{$plan->id}.storage_mb" => ['required', 'integer', 'min:0'],
                "plans.{$plan->id}.export" => ['nullable', 'boolean'],
                "plans.{$plan->id}.import" => ['nullable', 'boolean'],
            ]);

            $data = $validated["plans.{$plan->id}"];
            $plan->update([
                'label' => $data['label'],
                'price' => $data['price'],
                'features' => [
                    'storage_mb' => (int) $data['storage_mb'],
                    'export' => (bool) ($data['export'] ?? false),
                    'import' => (bool) ($data['import'] ?? false),
                ],
            ]);
        }

        \App\Support\Audit::log('Admin mengubah pengaturan paket (plan features)', [
            'plan_ids' => array_keys($validated['plans'] ?? []),
        ]);

        return back()->with('success', 'Pengaturan paket berhasil diperbarui.');
    }

    /**
     * Tambah paket (plan) baru — system admin. Tidak lagi terbatas gratis/donasi.
     */
    public function storePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:plans,name'],
            'label' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'period' => ['required', 'in:daily,weekly,monthly,yearly,once'],
            'storage_mb' => ['required', 'integer', 'min:0'],
            'export' => ['nullable', 'boolean'],
            'import' => ['nullable', 'boolean'],
        ]);

        Plan::create([
            'name' => $validated['name'],
            'label' => $validated['label'],
            'price' => $validated['price'],
            'period' => $validated['period'],
            'features' => [
                'storage_mb' => (int) $validated['storage_mb'],
                'export' => (bool) ($validated['export'] ?? false),
                'import' => (bool) ($validated['import'] ?? false),
            ],
            'is_active' => true,
        ]);

        \App\Support\Audit::log('SysAdmin menambah paket baru', [
            'name' => $validated['name'],
            'label' => $validated['label'],
            'storage_mb' => (int) $validated['storage_mb'],
        ]);

        return back()->with('success', "Paket '{$validated['label']}' ditambahkan.");
    }

    /**
     * Hapus paket — hanya jika tidak dipakai subscription apa pun.
     */
    public function destroyPlan(Plan $plan): RedirectResponse
    {
        // Cek pemakaian nyata: (1) user menyandang plan ini sebagai langganan
        // individu, (2) directory_subscriptions lawas yang mereferensikan plan_id.
        // Subscription direktori baru memakai storage_limit_mb langsung (plan_id
        // null) dan TIDAK bergantung pada plan, jadi tidak menghalangi penghapusan.
        if ($plan->subscriptions()->exists() || $plan->directorySubscriptions()->exists()) {
            return back()->with('error', 'Paket tidak dapat dihapus karena masih dipakai.');
        }

        $name = $plan->label;
        $plan->delete();

        \App\Support\Audit::log('SysAdmin menghapus paket', ['name' => $name]);

        return back()->with('success', "Paket '{$name}' dihapus.");
    }

    // ------------------------------------------------------- penamaan program (TA/KP)

    /**
     * Halaman kustomisasi penamaan program (TA/KP) & label fase per prodi/departemen.
     * Admin dengan admin_scopes hanya bisa mengelola node dalam scope-nya.
     */
    public function programNaming(Request $request): View
    {
        $user = $request->user();
        $institution = Institution::current();

        // Universitas + hierarki (fakultas -> departemen -> prodi), di-scope per role.
        $universityQuery = \App\Models\University::with('faculties.departments.studyPrograms')
            ->orderBy('name');

        // Scoping: system admin lihat semua; admin non-system dibatasi ke
        // universitas institusi miliknya (afiliasi pengguna di institusi sama),
        // fallback ke universitas milik user itu sendiri bila tanpa institusi.
        if (! $user->isSystemAdmin()) {
            $universityQuery->where(function ($q) use ($user) {
                if ($user->institution_id) {
                    $q->orWhereHas('users', fn ($uq) => $uq->where('institution_id', $user->institution_id));
                }
                $q->orWhereHas('users', fn ($uq) => $uq->whereKey($user->id));
            });
        }

        $universities = $universityQuery->get();

        // Admin dengan admin_scopes: batasi node yang bisa dikelola.
        $scopes = \App\Models\AdminScope::activeFor($user);
        $allowedStudyProgramIds = [];
        $allowedDepartmentIds = [];

        if ($scopes->isNotEmpty()) {
            foreach ($scopes as $scope) {
                switch ($scope->scope_type) {
                    case \App\Models\AdminScope::SCOPE_STUDY_PROGRAM:
                        $allowedStudyProgramIds[] = (int) $scope->scope_id;
                        break;
                    case \App\Models\AdminScope::SCOPE_DEPARTMENT:
                        $allowedDepartmentIds[] = (int) $scope->scope_id;
                        // Semua prodi di departemen ini juga boleh dikelola.
                        $allowedStudyProgramIds = array_merge(
                            $allowedStudyProgramIds,
                            \App\Models\StudyProgram::where('department_id', $scope->scope_id)->pluck('id')->all()
                        );
                        break;
                    case \App\Models\AdminScope::SCOPE_FACULTY:
                        $deptIds = \App\Models\Department::where('faculty_id', $scope->scope_id)->pluck('id')->all();
                        $allowedDepartmentIds = array_merge($allowedDepartmentIds, $deptIds);
                        $allowedStudyProgramIds = array_merge(
                            $allowedStudyProgramIds,
                            \App\Models\StudyProgram::whereIn('department_id', $deptIds)->pluck('id')->all()
                        );
                        break;
                }
            }
            $allowedStudyProgramIds = array_values(array_unique($allowedStudyProgramIds));
            $allowedDepartmentIds = array_values(array_unique($allowedDepartmentIds));
        }

        // Konfigurasi yang sudah ada.
        $configs = \App\Models\ProgramNamingConfig::where('institution_id', $institution->id)->get()
            ->keyBy(fn ($c) => $c->scope_type.':'.$c->scope_id.':'.$c->jenis);

        return view('admin.program-naming', compact(
            'universities', 'configs', 'scopes',
            'allowedStudyProgramIds', 'allowedDepartmentIds'
        ));
    }

    /**
     * Simpan konfigurasi penamaan program untuk prodi/departemen.
     */
    public function updateProgramNaming(Request $request): RedirectResponse
    {
        $user = $request->user();
        $institution = Institution::current();

        $validated = $request->validate([
            'scope_type' => ['required', 'in:study_program,department'],
            'scope_id' => ['required', 'integer'],
            'jenis' => ['required', 'in:ta,kp'],
            'program_label' => ['nullable', 'string', 'max:100'],
            'fase_labels' => ['nullable', 'array'],
            'fase_labels.*' => ['nullable', 'string', 'max:100'],
        ]);

        // Validasi scope: admin dengan admin_scopes hanya boleh kelola node dalam scope-nya.
        $scopes = \App\Models\AdminScope::activeFor($user);
        if ($scopes->isNotEmpty()) {
            $allowed = false;
            foreach ($scopes as $scope) {
                if ($scope->scope_type === \App\Models\AdminScope::SCOPE_STUDY_PROGRAM
                    && $validated['scope_type'] === 'study_program'
                    && (int) $scope->scope_id === (int) $validated['scope_id']) {
                    $allowed = true;
                    break;
                }
                if ($scope->scope_type === \App\Models\AdminScope::SCOPE_DEPARTMENT
                    && $validated['scope_type'] === 'department'
                    && (int) $scope->scope_id === (int) $validated['scope_id']) {
                    $allowed = true;
                    break;
                }
                if ($scope->scope_type === \App\Models\AdminScope::SCOPE_DEPARTMENT
                    && $validated['scope_type'] === 'study_program') {
                    $prodi = \App\Models\StudyProgram::find((int) $validated['scope_id']);
                    if ($prodi && $prodi->department_id === (int) $scope->scope_id) {
                        $allowed = true;
                        break;
                    }
                }
                if ($scope->scope_type === \App\Models\AdminScope::SCOPE_FACULTY) {
                    if ($validated['scope_type'] === 'department') {
                        $dept = \App\Models\Department::find((int) $validated['scope_id']);
                        if ($dept && $dept->faculty_id === (int) $scope->scope_id) {
                            $allowed = true;
                            break;
                        }
                    }
                    if ($validated['scope_type'] === 'study_program') {
                        $prodi = \App\Models\StudyProgram::with('department')->find((int) $validated['scope_id']);
                        if ($prodi && $prodi->department?->faculty_id === (int) $scope->scope_id) {
                            $allowed = true;
                            break;
                        }
                    }
                }
            }
            abort_unless($allowed, 403, 'Anda tidak memiliki akses ke node ini.');
        }

        // Filter fase_labels: hanya key yang valid untuk jenis program.
        $defaults = $validated['jenis'] === \App\Models\MahasiswaTa::JENIS_KP
            ? \App\Models\MahasiswaTa::FASES_KP
            : \App\Models\MahasiswaTa::FASES;
        $faseLabels = [];
        foreach ($defaults as $key => $defaultLabel) {
            $faseLabels[$key] = $validated['fase_labels'][$key] ?? null;
        }

        \App\Models\ProgramNamingConfig::updateOrCreate(
            [
                'institution_id' => $institution->id,
                'scope_type' => $validated['scope_type'],
                'scope_id' => (int) $validated['scope_id'],
                'jenis' => $validated['jenis'],
            ],
            [
                'program_label' => $validated['program_label'] ?: null,
                'fase_labels' => array_filter($faseLabels, fn ($v) => $v !== null && $v !== '') ?: null,
            ]
        );

        // Flush cache.
        app(\App\Services\ProgramNamingService::class)->flush(
            $institution->id,
            $validated['scope_type'],
            (int) $validated['scope_id'],
            $validated['jenis']
        );

        \App\Support\Audit::log('Admin mengubah konfigurasi penamaan program', [
            'scope_type' => $validated['scope_type'],
            'scope_id' => (int) $validated['scope_id'],
            'jenis' => $validated['jenis'],
        ]);

        return back()->with('success', 'Konfigurasi penamaan program berhasil disimpan.');
    }

    // ------------------------------------------------------- institusi

    public function institution(): View
    {
        $institution = Institution::current();

        return view('admin.institution', compact('institution'));
    }

    public function updateInstitution(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:255'],
            'institution_name' => ['required', 'string', 'max:255'],
            'faculty' => ['nullable', 'string', 'max:255'],
            'study_program' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'admin_contact_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'footer_note' => ['nullable', 'string', 'max:500'],
            // Pengaturan upload (bisa diisi admin).
            'max_upload_size_mb' => ['required', 'integer', 'min:1', 'max:100'],
            'allowed_file_types' => ['required', 'string', 'max:255'],
            'seminar_hardcopy_note' => ['nullable', 'string'],
            // Pengaturan SMTP TIDAK lagi di sini — dipindahkan ke panel system admin.
        ]);

        $institution = Institution::current();

        // Logo (opsional). Hapus logo lama agar tidak menumpuk di disk.
        if ($request->hasFile('logo')) {
            if ($institution->logo_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($institution->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store('institution', 'local');
        }

        $institution->update($validated);
        Institution::flush($institution->id);
        $institution->applyToConfig();

        \App\Support\Audit::log('Admin mengubah profil institusi / pengaturan', [
            'institution_id' => $institution->id,
            'institution_name' => $institution->institution_name,
            'field_berubah' => array_values(array_diff(array_keys($validated), [])),
        ]);

        return back()->with('success', 'Profil institusi diperbarui.');
    }

    /**
     * Halaman pengaturan autentikasi + SMTP (system admin).
     * Toggle "Wajib Verifikasi Email" + form SMTP (hanya tampil saat ON).
     */
    public function systemSettings(): View
    {
        $institution = Institution::current();
        $plans = Plan::orderBy('price')->get();

        return view('admin.system.settings', compact('institution', 'plans'));
    }

    /**
     * Simpan pengaturan autentikasi + SMTP.
     * Status verifikasi: Auto (null, ikuti SMTP) / Wajib (true) / Tidak (false).
     * - mail_*: hanya divalidasi/disimpan saat toggle ON (form tersembunyi
     *   saat OFF di view, tapi user bisa POST manual — guard di sini).
     */
    public function updateSystemSettings(Request $request): RedirectResponse
    {
        // Form notifikasi berkala memakai field *_keep agar nilai autentikasi/
        // SMTP tidak tertimpa null oleh form parsial. Normalisasi dulu ke
        // nama kolom asli bila field asli tidak dikirim.
        foreach ([
            'email_verification_override', 'admin_contact_email', 'mail_mailer',
            'mail_host', 'mail_port', 'mail_username', 'mail_from_address',
            'mail_from_name',
        ] as $field) {
            if (!$request->exists($field) && $request->exists($field.'_keep')) {
                $request->merge([$field => $request->input($field.'_keep') ?: null]);
            }
        }
        // Toggle notifikasi hanya diubah bila form notifikasi yang submit
        // (ditandai hidden _notification_form=1) — form autentikasi/SMTP
        // tidak menyentuh toggle.
        $notificationForm = $request->boolean('_notification_form');

        $rules = [
            'email_verification_override' => ['nullable', 'string', 'in:auto,wajib,tidak'],
            'mail_mailer' => ['nullable', 'string', 'max:20', 'in:smtp,log,array,sendmail,mailgun,ses,postmark,resend'],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['nullable', 'string', 'max:20', 'in:ssl,tls,null'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            // Email kontak admin DEFAULT (global). Bisa di-override per institusi
            // di form Profil Institusi. Dipakai sebagai info bantuan di
            // register/login/profil. Bukan email system admin — field terpisah.
            'admin_contact_email' => ['nullable', 'email', 'max:255'],
            // Toggle notifikasi berkala (checkbox): hanya '1'/absent.
            // Dicatat di audit tetapi tidak ikut $validated->fill agar form
            // parsial (section notifikasi) tidak menimpa kolom lain dengan null.
            'weekly_digest_enabled' => ['nullable', 'in:1'],
            'daily_reminder_enabled' => ['nullable', 'in:1'],
            'chat_digest_enabled' => ['nullable', 'in:1'],
            // Hidden field dari form parsial notifikasi — diabaikan saat fill
            // (nilai asli dipertahankan dari DB).
            'email_verification_override_keep' => ['nullable'],
            'admin_contact_email_keep' => ['nullable'],
            'mail_mailer_keep' => ['nullable'],
            'mail_host_keep' => ['nullable'],
            'mail_port_keep' => ['nullable'],
            'mail_username_keep' => ['nullable'],
            'mail_from_address_keep' => ['nullable'],
            'mail_from_name_keep' => ['nullable'],
            '_notification_form' => ['nullable', 'in:1'],
        ];

        $validated = $request->validate($rules);

        $institution = Institution::current();

        // Status verifikasi: Auto (null) / Wajib (true) / Tidak Wajib (false).
        switch ($request->input('email_verification_override')) {
            case 'wajib':
                $institution->email_verification_override = true;
                break;
            case 'tidak':
                $institution->email_verification_override = false;
                break;
            default:
                $institution->email_verification_override = null;
        }

        // SMTP: jangan timpa password bila tidak diisi (form tidak menampilkan nilai lama).
        unset(
            $validated['mail_password'], $validated['email_verification_override'],
            $validated['email_verification_override_keep'], $validated['admin_contact_email_keep'],
            $validated['mail_mailer_keep'], $validated['mail_host_keep'], $validated['mail_port_keep'],
            $validated['mail_username_keep'], $validated['mail_from_address_keep'],
            $validated['mail_from_name_keep'], $validated['_notification_form']
        );
        $institution->fill($validated);
        // Checkbox toggle: hanya diubah dari form notifikasi; absent (unchecked) = OFF.
        if ($notificationForm) {
            $institution->weekly_digest_enabled = $request->boolean('weekly_digest_enabled');
            $institution->daily_reminder_enabled = $request->boolean('daily_reminder_enabled');
            $institution->chat_digest_enabled = $request->boolean('chat_digest_enabled');
        }
        if ($request->filled('mail_password')) {
            // Disimpan terenkripsi via mutator di model Institution.
            $institution->mail_password = $request->input('mail_password');
        }

        $institution->save();

        Institution::flush($institution->id);
        $institution->applyToConfig();

        \App\Support\Audit::log('SysAdmin mengubah pengaturan autentikasi & SMTP', [
            'institution_id' => $institution->id,
            'email_verification_override' => $institution->email_verification_override,
            'weekly_digest_enabled' => $institution->weekly_digest_enabled,
            'daily_reminder_enabled' => $institution->daily_reminder_enabled,
            'chat_digest_enabled' => $institution->chat_digest_enabled,
            'field_berubah' => array_values(array_diff(array_keys($validated), ['mail_password'])),
        ]);

        return back()->with('success', 'Pengaturan autentikasi & SMTP berhasil disimpan.');
    }

    /**
     * Kirim email uji untuk memverifikasi konfigurasi SMTP (system admin).
     */
    public function systemTestMail(Request $request): RedirectResponse
    {
        $institution = Institution::current();
        $institution->applyToConfig();

        $to = $request->input('to') ?: $request->user()->email;

        try {
            \Illuminate\Support\Facades\Mail::raw(
                'Ini adalah email uji dari '.config('app.name').'. Konfigurasi SMTP berhasil!',
                function ($message) use ($to) {
                    $message->to($to)
                        ->subject('Email Uji — '.config('app.name'));
                }
            );

            return back()->with('success', 'Email uji berhasil dikirim ke '.$to.'.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal mengirim email uji: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------- review massal

    public function entries(Request $request): View
    {
        $query = \App\Models\LogbookEntry::with(['mahasiswaTa.mahasiswa']);

        // LogbookEntry tidak punya InstitutionScope — batasi via relasi ke MahasiswaTa.
        if (!$request->user()->isSystemAdmin()) {
            $query->whereHas('mahasiswaTa', function ($q) use ($request) {
                if ($request->user()->institution_id) {
                    $q->where('institution_id', $request->user()->institution_id);
                }

                // Admin dengan admin_scopes dibatasi ke scope-nya (hierarkis);
                // admin TANPA admin_scopes dikunci.
                $this->applyAdminScopeFilterToTa($q, $request->user());
            });
        }

        $query->when($request->filled('status'), fn ($q) => $q->status($request->query('status')))
            ->when($request->filled('jenis'), fn ($q) => $q->jenis($request->query('jenis')))
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $kw = $request->query('keyword');
                $q->where(function ($qq) use ($kw) {
                    $qq->where('topik', 'like', "%{$kw}%")
                        ->orWhere('progres_kendala', 'like', "%{$kw}%")
                        ->orWhereHas('mahasiswaTa.mahasiswa', fn ($m) => $m->where('name', 'like', "%{$kw}%"));
                });
            });

        $entries = $query->latest()->paginate(20)->withQueryString();

        return view('admin.entries', compact('entries'));
    }

    // ---------------------------------------------------------------- bulk actions

    public function bulkAction(Request $request, LogbookReviewTransition $transition): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:approve,revisi,delete,assign_dosen'],
            'feedback_dosen' => ['required_if:action,revisi', 'string', 'min:20'],
        ]);

        // Admin biasa: batasi ke data institusinya sendiri.
        // NOTE: $validated['ids'] berisi ID LogbookEntry (bukan MahasiswaTa) untuk
        // action approve/revisi/delete, dan ID MahasiswaTa untuk action assign_dosen.
        // Filter harus lewat relasi mahasiswaTa untuk LogbookEntry.
        if (!$request->user()->isSystemAdmin() && $request->user()->institution_id) {
            // Batasi ke institusi & scope admin (prodi/departemen/fakultas).
            $allowedTaIds = MahasiswaTa::whereIn('id', $validated['ids'])
                ->where('institution_id', $request->user()->institution_id)
                ->tap(fn ($q) => $this->applyAdminScopeFilterToTa($q, $request->user()))
                ->pluck('id')
                ->all();

            if ($validated['action'] === 'assign_dosen') {
                $validated['ids'] = $allowedTaIds;
            } else {
                $validated['ids'] = \App\Models\LogbookEntry::whereIn('id', $validated['ids'])
                    ->whereIn('mahasiswa_ta_id', $allowedTaIds)
                    ->pluck('id')
                    ->all();
            }
        }

        // Assign pembimbing massal ke data TA.
        if ($validated['action'] === 'assign_dosen') {
            $request->validate(['dosen_id' => ['required', 'exists:users,id']]);

            $dosen = User::find($request->integer('dosen_id'));
            abort_unless($dosen && $dosen->hasRole('dosen'), 422, 'User yang dipilih bukan dosen.');

            $count = MahasiswaTa::whereIn('id', $validated['ids'])
                ->update(['pembimbing_1_id' => $dosen->id]);

            \App\Support\Audit::log('Admin bulk assign dosen', [
                'action' => 'assign_dosen',
                'dosen_id' => $dosen->id,
                'count' => $count,
                'ids' => $validated['ids'],
            ]);

            return back()->with('success', "{$count} data TA di-assign pembimbing 1.");
        }

        if ($validated['action'] === 'delete') {
            $count = \App\Models\LogbookEntry::whereIn('id', $validated['ids'])->delete();

            \App\Support\Audit::log('Admin bulk hapus entri', [
                'action' => 'delete',
                'count' => $count,
                'ids' => $validated['ids'],
            ]);

            return back()->with('success', "{$count} entri dihapus.");
        }

        // approve/revisi: hanya entri yang benar-benar menunggu review, dengan
        // efek samping yang sama seperti alur review satuan (resolve komentar,
        // notifikasi, evaluasi achievement) agar mahasiswa tetap diberi tahu.
        $entries = \App\Models\LogbookEntry::whereIn('id', $validated['ids'])
            ->where('status', \App\Models\LogbookEntry::STATUS_SUBMITTED)
            ->get();

        $processed = 0;
        foreach ($entries as $entry) {
            if ($validated['action'] === 'approve') {
                if (! $transition->tryApply($entry, \App\Models\LogbookEntry::STATUS_APPROVED, [
                    'reviewed_at' => now(),
                ])) {
                    continue;
                }
                $this->resolveCommentsOnApproval($entry);

                $this->bestEffort(fn () => \App\Events\EntryStatusChanged::dispatch($entry, 'Entri Anda telah disetujui oleh pembimbing.'));
                $entry->notifyParties(
                    'Entri '.($entry->jenis === 'revisi' ? 'revisi' : 'logbook sesi '.$entry->sesi_ke).' telah disetujui.',
                    route('logbook.show', $entry),
                    'Entri Disetujui',
                );

                if ($owner = $entry->mahasiswaTa?->mahasiswa) {
                    app(\App\Services\AchievementService::class)->evaluateForUser($owner);
                }
            } else {
                if (! $transition->tryApply($entry, \App\Models\LogbookEntry::STATUS_REVISI, [
                    'feedback_dosen' => $validated['feedback_dosen'],
                    'reviewed_at' => now(),
                ])) {
                    continue;
                }

                $this->bestEffort(fn () => \App\Events\EntryStatusChanged::dispatch($entry, 'Entri Anda diminta revisi: '.$validated['feedback_dosen']));
                $entry->notifyParties(
                    'Entri Anda diminta revisi: '.$validated['feedback_dosen'],
                    route('logbook.show', $entry),
                    'Permintaan Revisi',
                );
            }
            $processed++;
        }

        \App\Support\Audit::log('Admin bulk '.$validated['action'], [
            'action' => $validated['action'],
            'count' => $processed,
            'ids' => $validated['ids'],
        ]);

        return back()->with('success', $processed.' entri berhasil diproses.');
    }

    /**
     * Aturan validasi: pastikan user_id yang dipilih benar memiliki role tertentu
     * (mis. pembimbing/penguji harus dosen, member_ids harus mahasiswa).
     */
    private function roleRule(string $role): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($role) {
            if ($value && !User::find($value)?->hasRole($role)) {
                $fail("User yang dipilih untuk {$attribute} bukan {$role}.");
            }
        };
    }

    /**
     * Cek apakah admin biasa boleh mengelola user target.
     * system_admin selalu boleh (platform-level). Admin biasa di mode institusi
     * hanya boleh mengelola user di institusinya sendiri, dan (jika punya
     * admin_scopes) hanya user yang afiliasinya cocok dengan scope-nya.
     */
    private function canManageUser(Request $request, User $target): bool
    {
        if ($request->user()->isSystemAdmin()) {
            return true;
        }

        // Admin non-system selalu dibatasi: bila punya institusi, target harus
        // di institusi yang sama; bila tidak punya institusi, tetap harus
        // tercakup admin_scopes (di bawah).
        if ($request->user()->institution_id !== null
            && $target->institution_id !== $request->user()->institution_id) {
            return false;
        }

        // Admin non-system TANPA admin_scopes dikunci (tidak bisa kelola siapa pun).
        $scopes = \App\Models\AdminScope::activeFor($request->user());
        if ($scopes->isEmpty()) {
            return false;
        }

        foreach ($scopes as $scope) {
            $match = $target->universities()->where(function ($uq) use ($scope) {
                switch ($scope->scope_type) {
                    case \App\Models\AdminScope::SCOPE_STUDY_PROGRAM:
                        $uq->where('user_university.study_program_id', $scope->scope_id);
                        break;
                    case \App\Models\AdminScope::SCOPE_DEPARTMENT:
                        $uq->where('user_university.department_id', $scope->scope_id);
                        break;
                    case \App\Models\AdminScope::SCOPE_FACULTY:
                        $uq->where('user_university.faculty_id', $scope->scope_id);
                        break;
                    case \App\Models\AdminScope::SCOPE_UNIVERSITY:
                        $uq->where('user_university.university_id', $scope->scope_id);
                        break;
                }
            })->exists();

            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cek apakah admin biasa boleh mengelola MahasiswaTa target.
     * system_admin selalu boleh. Admin biasa di mode institusi hanya boleh
     * mengelola program di institusinya sendiri.
     */
    private function canManageTa(Request $request, MahasiswaTa $target): bool
    {
        if ($request->user()->isSystemAdmin()) {
            return true;
        }

        if ($request->user()->institution_id !== null
            && $target->institution_id !== $request->user()->institution_id) {
            return false;
        }

        // Fase D: admin dengan admin_scopes hanya boleh kelola TA yang mahasiswanya
        // berada dalam cakupan scope-nya.
        $mahasiswa = $target->mahasiswa;
        if (! $mahasiswa) {
            return false;
        }

        return $this->canManageUser($request, $mahasiswa);
    }

    /**
     * Filter query User berdasarkan admin_scopes aktif milik admin (hierarkis).
     * Admin tanpa admin_scopes DIKUNCI (query kosong — tidak melihat siapa pun).
     * Admin dengan admin_scopes = dibatasi ke user yang afiliasinya cocok
     * dengan salah satu scope (OR), termasuk scope universitas.
     */
    private function applyAdminScopeFilter($query, User $admin): void
    {
        $scopes = \App\Models\AdminScope::activeFor($admin);
        // Admin tanpa admin_scopes DISEMBUNYIKAN akses (locked di level terendah).
        if ($scopes->isEmpty()) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use ($scopes) {
            foreach ($scopes as $scope) {
                $q->orWhereHas('universities', function ($uq) use ($scope) {
                    switch ($scope->scope_type) {
                        case \App\Models\AdminScope::SCOPE_STUDY_PROGRAM:
                            $uq->where('user_university.study_program_id', $scope->scope_id);
                            break;
                        case \App\Models\AdminScope::SCOPE_DEPARTMENT:
                            // Department mencakup semua prodi di bawahnya (sama dept_id).
                            $uq->where('user_university.department_id', $scope->scope_id);
                            break;
                        case \App\Models\AdminScope::SCOPE_FACULTY:
                            // Faculty mencakup semua dept & prodi di bawahnya (sama faculty_id).
                            $uq->where('user_university.faculty_id', $scope->scope_id);
                            break;
                        case \App\Models\AdminScope::SCOPE_UNIVERSITY:
                            // University mencakup seluruh fakultas/dept/prodi di dalamnya.
                            $uq->where('user_university.university_id', $scope->scope_id);
                            break;
                    }
                });
            }
        });
    }

    /**
     * Fase D — Filter query MahasiswaTa (atau relasi ke MahasiswaTa) berdasarkan
     * admin_scopes aktif milik admin. Dipakai di tas(), sidangs(), entries().
     *
     * @param string|null $relation Nama relasi ke MahasiswaTa (untuk query yang
     *                              bukan langsung ke MahasiswaTa, mis. Sidang).
     */
    private function applyAdminScopeFilterToTa($query, User $admin, ?string $relation = null): void
    {
        $scopes = \App\Models\AdminScope::activeFor($admin);
        // Admin tanpa admin_scopes DISEMBUNYIKAN akses (locked di level terendah).
        if ($scopes->isEmpty()) {
            $query->whereRaw('1 = 0');
            return;
        }

        $apply = function ($q) use ($scopes) {
            $q->where(function ($inner) use ($scopes) {
                foreach ($scopes as $scope) {
                    $inner->orWhereHas('mahasiswa', function ($mq) use ($scope) {
                        $mq->whereHas('universities', function ($uq) use ($scope) {
                            switch ($scope->scope_type) {
                                case \App\Models\AdminScope::SCOPE_STUDY_PROGRAM:
                                    $uq->where('user_university.study_program_id', $scope->scope_id);
                                    break;
                                case \App\Models\AdminScope::SCOPE_DEPARTMENT:
                                    $uq->where('user_university.department_id', $scope->scope_id);
                                    break;
                                case \App\Models\AdminScope::SCOPE_FACULTY:
                                    $uq->where('user_university.faculty_id', $scope->scope_id);
                                    break;
                                case \App\Models\AdminScope::SCOPE_UNIVERSITY:
                                    $uq->where('user_university.university_id', $scope->scope_id);
                                    break;
                            }
                        });
                    });
                }
            });
        };

        if ($relation) {
            $query->whereHas($relation, $apply);
        } else {
            $apply($query);
        }
    }

    /** Resolve semua komentar PDF (entri ini & induknya) saat entri disetujui. */
    private function resolveCommentsOnApproval(\App\Models\LogbookEntry $logbook): void
    {
        $entries = collect([$logbook, $logbook->parentEntry])->filter();

        foreach ($entries as $entry) {
            $entry->comments()
                ->where('resolution_status', '!=', \App\Models\PdfComment::STATUS_RESOLVED)
                ->get()
                ->each(function ($comment) {
                    $comment->setResolutionStatus(\App\Models\PdfComment::STATUS_RESOLVED);
                    $comment->save();
                });
        }
    }
}
