<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Institution;
use App\Models\LogbookHarianKp;
use App\Models\MahasiswaTa;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrganizationalDirectoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $systemAdminRole = Role::findOrCreate('system_admin');
        $adminRole = Role::findOrCreate('admin');
        $dosenRole = Role::findOrCreate('dosen');
        $mahasiswaRole = Role::findOrCreate('mahasiswa');

        // Sync permissions ke role (pastikan konsisten setiap seed).
        $this->syncPermissions();

        // Profil institusi default (single-row).
        Institution::firstOrCreate(
            ['id' => 1],
            [
                'app_name' => 'Logbook',
                'institution_name' => 'Perguruan Tinggi',
                'faculty' => null,
                'study_program' => null,
                'address' => null,
                'city' => null,
                'phone' => null,
                'email' => 'no-reply@example.com',
                'website' => '',
                'footer_note' => 'Dokumen ini dihasilkan oleh Logbook.',
                'seminar_hardcopy_note' => 'Jika diadakan secara offline, Anda juga diminta menyerahkan hardcopy laporan paling lambat 48 jam sebelum jadwal Seminar/Sidang. Anda bisa menyerahkannya ke TU atau langsung ke ruangan saya.',
            ]
        );

        // Seed daftar achievement (badge) dari definisi model.
        foreach (Achievement::definitions() as $code => [$icon, $name, $desc]) {
            Achievement::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $desc, 'icon' => $icon]
            );
        }

        // Seed paket (Free vs Donasi). updateOrCreate agar plan yang sudah ada
        // ikut diperbarui (mis. storage_mb free = 3 GB) — mencegah plan free
        // tanpa storage_mb yang membuat kuota dosen jadi 0.
        Plan::updateOrCreate(
            ['name' => 'free'],
            [
                'label' => 'Gratis',
                'price' => 0,
                'period' => 'monthly',
                'features' => [
                    'export' => false,
                    'import' => false,
                    'storage_mb' => 3072, // 3 GB
                ],
                'is_active' => true,
            ]
        );
        Plan::updateOrCreate(
            ['name' => 'donasi'],
            [
                'label' => 'Donasi',
                'price' => 50000,
                'period' => 'monthly',
                'features' => [
                    'export' => true,
                    'import' => true,
                    'storage_mb' => 10240, // 10 GB
                ],
                'is_active' => true,
            ]
        );

        // Akun demo hanya boleh dibuat di local/testing. Seeder production
        // tetap menyiapkan role, institusi, dan achievement tanpa password demo.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        // Akun demo ringkas dibuat lebih dulu agar data kaya (TA, grup, dll.)
        // dibangun di atas user demo FINAL (tidak terhapus oleh DemoAccountSeeder).
        $this->call(DemoAccountSeeder::class);

        // Helper: buat user dengan status & email verified.
        // Jika NIDN/nim sudah dipakai user lain (email berbeda), update email user tsb.
        $makeUser = function (array $attrs, array $roles, string $status) use ($mahasiswaRole, $dosenRole, $adminRole, $systemAdminRole) {
            // Cari berdasarkan email dulu.
            $user = User::where('email', $attrs['email'])->first();

            // Jika tidak ketemu, cari berdasarkan NIDN (jika ada).
            if (!$user && !empty($attrs['nidn'])) {
                $user = User::where('nidn', $attrs['nidn'])->first();
                if ($user) {
                    $user->update(['email' => $attrs['email']]);
                }
            }

            // Jika masih tidak ketemu, cari berdasarkan nim (jika ada).
            if (!$user && !empty($attrs['nim'])) {
                $user = User::where('nim', $attrs['nim'])->first();
                if ($user) {
                    $user->update(['email' => $attrs['email']]);
                }
            }

            if (!$user) {
                $user = User::create(array_merge($attrs, [
                    'password' => Hash::make('password'),
                    'registration_status' => $status,
                    'email_verified_at' => now(),
                ]));
            } else {
                // Update hanya field yang tidak konflik unique.
                $update = array_merge($attrs, [
                    'registration_status' => $status,
                    'email_verified_at' => now(),
                ]);
                // Jangan overwrite identifier/nidn jika sudah dipakai user lain.
                if (isset($update['nim']) && User::where('nim', $update['nim'])->where('id', '!=', $user->id)->exists()) {
                    unset($update['nim']);
                }
                if (isset($update['nidn']) && User::where('nidn', $update['nidn'])->where('id', '!=', $user->id)->exists()) {
                    unset($update['nidn']);
                }
                $user->update($update);
            }

            // Konversi role (model/string) ke nama untuk syncRoles.
            $roleNames = array_map(fn ($r) => $r instanceof \Spatie\Permission\Models\Role ? $r->name : $r, $roles);
            $user->syncRoles($roleNames);
            return $user;
        };

        // System Admin (role tertinggi — mengelola admin lain & konfigurasi sistem).
        $systemAdmin = $makeUser(
            ['email' => 'systemadmin@example.com', 'name' => 'System Administrator', 'nim' => 'SYS001'],
            [$systemAdminRole],
            'approved'
        );

        // Admin utama (role admin SAJA — tidak dosen).
        $adminUser = $makeUser(
            ['email' => 'admin@example.com', 'name' => 'Ir. Admin Utama, M.T.', 'nim' => 'ADM001'],
            [$adminRole],
            'approved'
        );

        // Administrator khusus (role admin saja).
        $administrator = $makeUser(
            ['email' => 'administrator@example.com', 'name' => 'Administrator Sistem', 'nim' => 'ADM002'],
            [$adminRole],
            'approved'
        );

        // Dosen utama (role dosen SAJA — terpisah dari admin).
        $dosen1 = $makeUser(
            ['email' => 'dosen1@example.com', 'name' => 'Dr. Dosen Satu, S.T., M.T.', 'nidn' => '0001010101'],
            [$dosenRole],
            'approved'
        );

        // Dosen pembimbing kedua (opsional).
        $dosen2 = $makeUser(
            ['email' => 'dosen2@example.com', 'name' => 'Dr. Dosen Dua, S.T., M.T.', 'nidn' => '0002020202'],
            [$dosenRole],
            'approved'
        );

        // Dosen demo tambahan (untuk demo penguji & grup/cross-link).
        $dosen3 = $makeUser(
            ['email' => 'dosen3@example.com', 'name' => 'Dr. Dosen Tiga, S.Kom., M.Kom.', 'nidn' => '0003030303'],
            [$dosenRole],
            'approved'
        );

        $dosen4 = $makeUser(
            ['email' => 'dosen4@example.com', 'name' => 'Dr. Dosen Empat, S.T., M.T.', 'nidn' => '0004040404'],
            [$dosenRole],
            'approved'
        );

        // ===============================================================
        // Mahasiswa TA (verified) — sudah punya program + dosen.
        // ===============================================================
        $mahasiswa = $makeUser(
            ['email' => 'mahasiswa@example.com', 'name' => 'Mahasiswa Contoh', 'nim' => '200401001', 'whatsapp' => '6281234567890'],
            [$mahasiswaRole],
            'verified'
        );

        // Data pokok TA mahasiswa (fase proposal + penguji untuk demo seminar submission).
        MahasiswaTa::firstOrCreate(
            ['user_id' => $mahasiswa->id, 'jenis' => MahasiswaTa::JENIS_TA],
            [
                'judul_ta' => 'Perancangan Sistem Pengolahan Air Limbah Domestik Terpusat di Kawasan Permukiman',
                'pembimbing_1_id' => $dosen1->id,
                'pembimbing_2_id' => $dosen2->id,
                'penguji_1_id' => $dosen3->id,
                'penguji_2_id' => $dosen4->id,
                'target_sesi' => 7,
                'fase' => 'proposal',
                'status_ta' => MahasiswaTa::STATUS_AKTIF,
            ]
        );

        // ===============================================================
        // Akun demo mahasiswa KP (Kerja Praktek) kelompok.
        // ===============================================================
        $mahasiswaKp1 = $makeUser(
            ['email' => 'mahasiswa_kp@example.com', 'name' => 'Mahasiswa KP Satu', 'nim' => '200401002', 'whatsapp' => '6281234567891'],
            [$mahasiswaRole],
            'verified'
        );

        $mahasiswaKp2 = $makeUser(
            ['email' => 'mahasiswa_kp2@example.com', 'name' => 'Mahasiswa KP Dua', 'nim' => '200401003', 'whatsapp' => '6281234567892'],
            [$mahasiswaRole],
            'verified'
        );

        // Program KP kelompok (pemilik = mahasiswaKp1, anggota = mahasiswaKp2).
        $kp = MahasiswaTa::firstOrCreate(
            ['user_id' => $mahasiswaKp1->id, 'jenis' => MahasiswaTa::JENIS_KP],
            [
                'tempat_kp' => 'PT. Teknologi Nusantara',
                'pembimbing_1_id' => $dosen1->id,
                'pembimbing_2_id' => $dosen2->id,
                'pembimbing_lapangan' => 'Bapak Rudi, S.T.',
                'target_sesi' => 7,
                'periode_mulai' => now()->subMonth(),
                'periode_selesai' => now()->addMonth(),
                'fase' => 'pelaksanaan',
                'status_ta' => MahasiswaTa::STATUS_AKTIF,
            ]
        );

        // Anggota kelompok kedua (demo fitur KP kelompok).
        if (! $kp->members()->where('user_id', $mahasiswaKp2->id)->exists()) {
            $kp->members()->attach($mahasiswaKp2->id);
        }

        // Contoh logbook harian KP.
        LogbookHarianKp::firstOrCreate(
            ['mahasiswa_ta_id' => $kp->id, 'tanggal' => now()->subDay()->toDateString()],
            [
                'kegiatan' => 'Mengikuti briefing dan mempelajari alur kerja divisi IT di PT. Teknologi Nusantara.',
                'kendala' => 'Perlu adaptasi dengan tools internal perusahaan.',
                'created_by' => $mahasiswaKp1->id,
            ]
        );

        // ===============================================================
        // Mahasiswa active (belum pilih dosen) — demo alur baru.
        // ===============================================================
        $mahasiswaActive = $makeUser(
            ['email' => 'mahasiswa_active@example.com', 'name' => 'Mahasiswa Aktif', 'nim' => '200401004', 'whatsapp' => '6281234567893'],
            [$mahasiswaRole],
            'active'
        );

        // ===============================================================
        // Demo direktori organisasi & grup dosen.
        // ===============================================================
        $directory = app(OrganizationalDirectoryService::class);

        // 1. Buat universitas demo + struktur hierarkis.
        $univ = $directory->findOrCreateUniversity('Universitas Nusantara', '001001');
        $fakultas = $directory->findOrCreateFaculty($univ, 'Fakultas Teknik');
        $departemen = $directory->findOrCreateDepartment($fakultas, 'Departemen Teknik Informatika');
        $prodi = $directory->findOrCreateStudyProgram($departemen, 'S1 Teknik Informatika', '55201');

        // 2. Hubungkan dosen yang sudah ada ke universitas.
        $directory->attachUserToUniversity($dosen1, $univ, $fakultas, $departemen, $prodi, true);
        $directory->attachUserToUniversity($dosen2, $univ, $fakultas, $departemen, $prodi, true);

        // 3. Hubungkan dosen demo tambahan ke universitas.
        $directory->attachUserToUniversity($dosen3, $univ, $fakultas, $departemen, $prodi, true);
        $directory->attachUserToUniversity($dosen4, $univ, $fakultas, $departemen, $prodi, true);

        // 4. Buat grup demo + anggota (dosen1 owner, dosen2, dosen3 & dosen4 approved).
        $group = Group::firstOrCreate(
            ['name' => 'Dosen Teknik Informatika Universitas Nusantara'],
            [
                'level' => 'prodi',
                'university_id' => $univ->id,
                'faculty_id' => $fakultas->id,
                'department_id' => $departemen->id,
                'study_program_id' => $prodi->id,
                'created_by' => $dosen1->id,
            ]
        );

        foreach ([$dosen1, $dosen2, $dosen3, $dosen4] as $i => $member) {
            GroupMember::firstOrCreate(
                ['group_id' => $group->id, 'user_id' => $member->id],
                [
                    'status' => 'approved',
                    'role' => $i === 0 ? 'owner' : 'member',
                ]
            );
        }

        // ===============================================================
        // Demo langganan direktori (directory_subscriptions) — termasuk
        // kasus lintas-cabang agar penjumlahan kuota terlihat.
        // ===============================================================
        $donasiPlan = Plan::where('name', 'donasi')->first();

        // 5a. Langganan di prodi S1 Teknik Informatika (soft cover).
        if ($donasiPlan && ! \App\Models\DirectorySubscription::where('scope_type', 'study_program')
            ->where('scope_id', $prodi->id)->exists()) {
            \App\Models\DirectorySubscription::create([
                'scope_type' => 'study_program',
                'scope_id' => $prodi->id,
                'plan_id' => $donasiPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'assigned_by' => $systemAdmin->id,
            ]);
        }

        // 5b. Cabang kedua (lintas-cabang): universitas/fakultas/prodi lain.
        $univ2 = $directory->findOrCreateUniversity('Universitas Nusantara 2', '001002');
        $fakultas2 = $directory->findOrCreateFaculty($univ2, 'Fakultas Ekonomi');
        $departemen2 = $directory->findOrCreateDepartment($fakultas2, 'Departemen Manajemen');
        $prodi2 = $directory->findOrCreateStudyProgram($departemen2, 'S1 Manajemen', '61201');

        // Hubungkan dosen1 ke cabang kedua (multi-afiliasi) — agar kuota dijumlah.
        $directory->attachUserToUniversity($dosen1, $univ2, $fakultas2, $departemen2, $prodi2, false);

        // Langganan di prodi cabang kedua.
        if ($donasiPlan && ! \App\Models\DirectorySubscription::where('scope_type', 'study_program')
            ->where('scope_id', $prodi2->id)->exists()) {
            \App\Models\DirectorySubscription::create([
                'scope_type' => 'study_program',
                'scope_id' => $prodi2->id,
                'plan_id' => $donasiPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'assigned_by' => $systemAdmin->id,
            ]);
        }

        // 5c. Langganan di level fakultas (cover turunan) — Fakultas Teknik.
        if ($donasiPlan && ! \App\Models\DirectorySubscription::where('scope_type', 'faculty')
            ->where('scope_id', $fakultas->id)->exists()) {
            \App\Models\DirectorySubscription::create([
                'scope_type' => 'faculty',
                'scope_id' => $fakultas->id,
                'plan_id' => $donasiPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'assigned_by' => $systemAdmin->id,
            ]);
        }

    }

    /**
     * Sinkronkan permission ke role (konsisten dengan migration permissions).
     */
    private function syncPermissions(): void
    {
        $permissions = [
            'logbook.create', 'logbook.review',
            'workspace.upload', 'workspace.delete', 'workspace.manage-others',
            'export.pdf', 'export.excel', 'import.excel',
            'seminar.submit', 'seminar.review',
            'finalization.submit', 'finalization.approve',
            'sidang.record',
            'announcement.create', 'chat.send',
            'admin.users', 'admin.tas', 'admin.sidangs', 'admin.institution', 'admin.bulk-review', 'admin.create-admin',
            'storage.manage',
            'groups.create', 'groups.invite',
            'approval.manage',
            'system.admins', 'system.plans',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $systemAdmin = Role::findOrCreate('system_admin');
        $admin = Role::findOrCreate('admin');
        $dosen = Role::findOrCreate('dosen');
        $mahasiswa = Role::findOrCreate('mahasiswa');

        $systemAdmin->syncPermissions($permissions);

        $admin->syncPermissions(array_values(array_filter(
            $permissions,
            fn ($p) => !str_starts_with($p, 'system.')
        )));

        $dosen->syncPermissions([
            'logbook.review',
            'workspace.upload', 'workspace.delete', 'workspace.manage-others',
            'export.pdf', 'export.excel',
            'seminar.review',
            'finalization.approve',
            'sidang.record',
            'announcement.create', 'chat.send',
            'storage.manage',
            'groups.create', 'groups.invite',
            'approval.manage',
        ]);

        $mahasiswa->syncPermissions([
            'logbook.create',
            'workspace.upload', 'workspace.delete',
            'seminar.submit',
            'finalization.submit',
            'chat.send',
        ]);
    }
}
