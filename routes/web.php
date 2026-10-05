<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\SystemBackupController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\StudentApprovalController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DosenSidangController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FinalizationController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LogbookController;
use App\Http\Controllers\LogbookHarianController;
use App\Http\Controllers\MahasiswaTaController;
use App\Http\Controllers\MaterialsReviewController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ActionItemController;
use App\Http\Controllers\AffiliationApprovalController;
use App\Http\Controllers\PdfCommentController;
use App\Http\Controllers\ProfilPerusahaanController;
use App\Http\Controllers\ProfilAkademikController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickReviewController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\SeminarSubmissionController;
use App\Http\Controllers\UtilityController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\InstitutionWorkspaceController;
use App\Http\Controllers\LandingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', LandingController::class)->name('landing');

// SEO: robots.txt & sitemap.xml (juga dilayani web server; route ini menjamin
// ketersediaan saat static file tidak di-serve oleh server).
Route::get('/robots.txt', fn () => response(file_get_contents(public_path('robots.txt')), 200, ['Content-Type' => 'text/plain; charset=utf-8']));
Route::get('/sitemap.xml', fn () => response(file_get_contents(public_path('sitemap.xml')), 200, ['Content-Type' => 'application/xml; charset=utf-8']));

// ------------------------------------------------------------------ auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:6,1')->name('login.attempt');

    // Forgot / reset password.
    Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');

    // Registrasi mandiri mahasiswa.
    Route::get('/register', [RegisterController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:5,1')->name('register');
});

// Verifikasi email via signed link — TANPA wajib login dulu (agar bisa dipakai
// di perangkat/browser mana pun). Keabsahan URL ditangani middleware `signed`,
// lalu id/hash divalidasi di controller sebelum user di-login.
Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Ubah email (self-service) — bisa diakses user yang belum verified (mis. salah
// alamat) karena tidak di-wrapper middleware `ensure.email.verified`.
Route::middleware('auth')->put('/profile/email', [ProfileController::class, 'updateEmail'])->name('profile.email');

// Verifikasi email (notice/send) — auth-only, TERPISAH dari grup yang dilindungi
// `ensure.dosen.affiliation` & `ensure.email.verified`. Tanpa ini, user yang belum
// verifikasi (terutama dosen yang belum afiliasi) terjebak redirect-loop antara
// halaman verifikasi dan halaman afiliasi (ERR_TOO_MANY_REDIRECTS).
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [VerificationController::class, 'showNotice'])->name('verification.notice');
    Route::post('/email/send', [VerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

Route::middleware(['auth', 'ensure.dosen.decision', 'ensure.dosen.affiliation', 'ensure.email.verified'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Persetujuan attachment dosen (mahasiswa pilih dosen → dosen setujui/tolak).
    Route::middleware('role_or_permission:dosen|admin')->group(function () {
        Route::get('/approval', [StudentApprovalController::class, 'index'])->name('approval.index');
        Route::post('/approval/invite', [StudentApprovalController::class, 'invite'])->name('approval.invite');
        Route::post('/approval/{mahasiswaTa}/approve', [StudentApprovalController::class, 'approve'])->name('approval.approve');
        Route::post('/approval/{mahasiswaTa}/reject', [StudentApprovalController::class, 'reject'])->name('approval.reject');
        Route::post('/approval/penguji/{change}/approve', [StudentApprovalController::class, 'approvePengujiRequest'])->name('approval.penguji.approve');
        Route::post('/approval/penguji/{change}/reject', [StudentApprovalController::class, 'rejectPengujiRequest'])->name('approval.penguji.reject');
    });

    // Fase D: dosen mencatat sidang / riwayat menguji (termasuk mahasiswa orang lain).
    Route::middleware('role_or_permission:dosen|admin')->group(function () {
        Route::get('/dosen-sidang', [DosenSidangController::class, 'index'])->name('dosen-sidang.index');
        Route::post('/dosen-sidang', [DosenSidangController::class, 'store'])->name('dosen-sidang.store');
        Route::post('/dosen-sidang/{sidang}/grade', [DosenSidangController::class, 'grade'])->name('dosen-sidang.grade');
        Route::delete('/dosen-sidang/{sidang}', [DosenSidangController::class, 'destroy'])->name('dosen-sidang.destroy');
    });
    Route::get('/dashboard/dosen/mahasiswa-list', [DashboardController::class, 'dosenMahasiswaList'])->name('dashboard.dosen.mahasiswa-list');
    Route::get('/dashboard/dosen/mahasiswa-saya', [DashboardController::class, 'mahasiswaSaya'])->name('dosen.mahasiswa-saya');
    Route::post('/dashboard/lanjut-ta/dismiss', [DashboardController::class, 'dismissLanjutTa'])->name('dashboard.lanjut-ta.dismiss');
    Route::get('/dashboard/dosen/sidang-list', [DashboardController::class, 'dosenSidangList'])->name('dashboard.dosen.sidang-list');
    Route::get('/dashboard/dosen/sidang-list/export', [ExportController::class, 'exportSidangPdf'])->name('dashboard.dosen.sidang-list.export');
    Route::get('/dosen/jadwal-seminar', [DashboardController::class, 'dosenSeminarJadwal'])->name('dosen.seminar-jadwal');

    // ----------------------------------------- persetujuan afiliasi dosen (admin)
    Route::middleware('role_or_permission:admin|system_admin')->group(function () {
        Route::get('/afiliasi/persetujuan', [AffiliationApprovalController::class, 'index'])->name('affiliation-approval.index');
        Route::post('/afiliasi/{user}/{university}/approve', [AffiliationApprovalController::class, 'approve'])->name('affiliation-approval.approve');
        Route::post('/afiliasi/{user}/{university}/reject', [AffiliationApprovalController::class, 'reject'])->name('affiliation-approval.reject');
    });

    // ---------------------------------------------------------- profil
    Route::get('/profil', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profil', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::put('/profil/program/{mahasiswaTa}', [ProfileController::class, 'updateProgram'])->name('profile.program');
    Route::get('/profil/pilih-dosen', [ProfileController::class, 'selectDosen'])->name('profile.select-dosen');
    Route::post('/profil/pilih-dosen', [ProfileController::class, 'storeDosen'])->name('profile.store-dosen');
    Route::post('/profil/afiliasi-mahasiswa', [ProfileController::class, 'updateMahasiswaAffiliation'])->name('profile.affiliation-mahasiswa.update');

    // ------------------------------------------------------- profil akademik (mahasiswa)
    Route::get('/profil-akademik', [ProfilAkademikController::class, 'index'])->name('profile.profil-akademik');
    Route::post('/profil-akademik/penguji', [ProfilAkademikController::class, 'proposePenguji'])->name('profile.profil-akademik.penguji');
    Route::post('/kp/{mahasiswaTa}/anggota', [ProfileController::class, 'addMember'])->name('profile.kp.add-member');
    Route::delete('/kp/{mahasiswaTa}/anggota/{user}', [ProfileController::class, 'removeMember'])->name('profile.kp.remove-member');

    // ------------------------------------------------------- afiliasi (dosen)
    Route::get('/profil/afiliasi', [ProfileController::class, 'affiliation'])->name('profile.affiliation');
    Route::post('/profil/afiliasi', [ProfileController::class, 'updateAffiliation'])->name('profile.affiliation.update');
    Route::post('/profil/afiliasi/{university}/revoke', [ProfileController::class, 'revokeAffiliation'])->name('profile.affiliation.revoke');

    Route::get('/profil/{user}', [ProfileController::class, 'show'])->name('profile.show');

    // ------------------------------------------------------ detail & fase TA
    Route::get('/mahasiswa-ta/{mahasiswaTa}', [MahasiswaTaController::class, 'show'])->name('mahasiswa-ta.show');
    Route::post('/mahasiswa-ta/{mahasiswaTa}/fase', [MahasiswaTaController::class, 'updateFase'])
        ->name('mahasiswa-ta.fase');

    // Ganti dosen penguji langsung (oleh pembimbing / admin).
    Route::post('/mahasiswa-ta/{mahasiswaTa}/penguji', [MahasiswaTaController::class, 'updatePenguji'])
        ->name('mahasiswa-ta.penguji');

    // ------------------------------------------------------ detail & fase KP
    Route::get('/mahasiswa-kp/{mahasiswaTa}', [MahasiswaTaController::class, 'show'])->name('mahasiswa-kp.show');
    Route::post('/mahasiswa-kp/{mahasiswaTa}/fase', [MahasiswaTaController::class, 'updateFase'])
        ->name('mahasiswa-kp.fase');

    // Gabungkan mahasiswa (dari program KP terpisah) ke kelompok ini — oleh pembimbing.
    Route::post('/mahasiswa-kp/{mahasiswaTa}/gabung', [MahasiswaTaController::class, 'gabungkanAnggota'])
        ->name('mahasiswa-kp.gabung');

    // ------------------------------------------------------ logbook harian KP
    Route::get('/kp/{mahasiswaTa}/logbook-harian', [LogbookHarianController::class, 'index'])->name('logbook-harian.index');
    Route::get('/kp/{mahasiswaTa}/logbook-harian/create', [LogbookHarianController::class, 'create'])->name('logbook-harian.create');
    Route::post('/kp/{mahasiswaTa}/logbook-harian', [LogbookHarianController::class, 'store'])->name('logbook-harian.store');
    Route::get('/kp/{mahasiswaTa}/logbook-harian/{logbookHarian}/foto/{index}', [LogbookHarianController::class, 'foto'])->name('logbook-harian.foto');
    Route::get('/kp/{mahasiswaTa}/logbook-harian/{logbookHarian}/edit', [LogbookHarianController::class, 'edit'])->name('logbook-harian.edit');
    Route::put('/kp/{mahasiswaTa}/logbook-harian/{logbookHarian}', [LogbookHarianController::class, 'update'])->name('logbook-harian.update');
    Route::delete('/kp/{mahasiswaTa}/logbook-harian/{logbookHarian}', [LogbookHarianController::class, 'destroy'])->name('logbook-harian.destroy');

    // ------------------------------------------------------ profil perusahaan KP
    Route::get('/kp/{mahasiswaTa}/profil-perusahaan', [ProfilPerusahaanController::class, 'index'])->name('profil-perusahaan.index');
    Route::put('/kp/{mahasiswaTa}/profil-perusahaan', [ProfilPerusahaanController::class, 'update'])->name('profil-perusahaan.update');

    // -------------------------------------------------- jadwal bimbingan
    Route::get('/jadwal-bimbingan', [SchedulingController::class, 'index'])->name('scheduling.index');

    // -------------------------------------------------- quick review (dosen)
    Route::get('/quick-review', [QuickReviewController::class, 'index'])->name('quick-review.index');
    Route::post('/quick-review/{logbook}/approve-next', [QuickReviewController::class, 'approveNext'])->name('quick-review.approve-next');
    Route::post('/quick-review/{logbook}/revisi-next', [QuickReviewController::class, 'revisiNext'])->name('quick-review.revisi-next');
    Route::post('/quick-review/{logbook}/archive-next', [QuickReviewController::class, 'archiveNext'])->name('quick-review.archive-next');
    Route::post('/quick-review/{logbook}/build-feedback', [QuickReviewController::class, 'buildFeedbackFromComments'])->name('quick-review.build-feedback');

    // ------------------------------------------------- antrean review bahan (gate dosen)
    Route::get('/review-bahan', [MaterialsReviewController::class, 'index'])->name('materials-review.index');
    Route::post('/feedback-templates', [QuickReviewController::class, 'storeTemplate'])->name('feedback-templates.store');
    Route::delete('/feedback-templates/{template}', [QuickReviewController::class, 'destroyTemplate'])->name('feedback-templates.destroy');

    // ---------------------------------------------------- action items (mahasiswa)
    Route::post('/logbook/{logbook}/action-items', [ActionItemController::class, 'store'])->name('action-items.store');
    Route::post('/logbook/{logbook}/action-items/{item}/toggle', [ActionItemController::class, 'toggle'])->name('action-items.toggle');
    Route::delete('/logbook/{logbook}/action-items/{item}', [ActionItemController::class, 'destroy'])->name('action-items.destroy');

    // ---------------------------------------------------- global search + import
    Route::get('/search', [UtilityController::class, 'globalSearch'])->name('global-search');
    Route::post('/admin/import-mahasiswa', [UtilityController::class, 'importMahasiswa'])->name('admin.import-mahasiswa');

    // ------------------------------------------------------ workspace
    Route::get('/workspace', [WorkspaceController::class, 'roleIndex'])->name('workspace.role');
    Route::get('/workspace/{mahasiswaTa}', [WorkspaceController::class, 'index'])->name('workspace.index');
    Route::post('/workspace/{mahasiswaTa}/files', [WorkspaceController::class, 'store'])->name('workspace.store');
    Route::get('/workspace/files/{file}/download', [WorkspaceController::class, 'download'])->name('workspace.download');
    Route::get('/workspace/files/{file}/preview', [WorkspaceController::class, 'preview'])->name('workspace.preview');
    Route::patch('/workspace/files/{file}', [WorkspaceController::class, 'update'])->name('workspace.update');
    Route::delete('/workspace/files/{file}', [WorkspaceController::class, 'destroy'])->name('workspace.destroy');

    // -------------------------------------------------- workspace pribadi (dosen) — redirect ke /workspace
    Route::get('/workspace-saya', fn () => redirect()->route('workspace.role'))->name('workspace.personal');
    Route::post('/workspace-saya', [WorkspaceController::class, 'personalStore'])->name('workspace.personal-store');

    // -------------------------------------------------- workspace institusi (berbagi di level direktori)
    Route::prefix('workspace-institusi')->name('workspace-institusi.')->middleware('auth')->group(function () {
        Route::get('/', [InstitutionWorkspaceController::class, 'index'])->name('index');
        Route::get('/{workspace}', [InstitutionWorkspaceController::class, 'show'])->name('show');
        Route::post('/', [InstitutionWorkspaceController::class, 'store'])->name('store');
        Route::post('/{workspace}/files', [InstitutionWorkspaceController::class, 'upload'])->name('upload');
        Route::delete('/{workspace}/files/{file}', [InstitutionWorkspaceController::class, 'destroyFile'])->name('files.destroy');
        Route::get('/{workspace}/files/{file}/download', [InstitutionWorkspaceController::class, 'download'])->name('files.download');
        Route::get('/{workspace}/files/{file}/preview', [InstitutionWorkspaceController::class, 'preview'])->name('files.preview');
        Route::put('/{workspace}/access', [InstitutionWorkspaceController::class, 'updateAccess'])->name('access.update');
    });

    // -------------------------------------------------- penyimpanan saya (dosen)
    Route::middleware('role_or_permission:dosen|admin')->group(function () {
        Route::get('/penyimpanan-saya', [StorageController::class, 'index'])->name('storage.index');
        Route::delete('/penyimpanan-saya/workspace/{file}', [StorageController::class, 'destroyWorkspace'])->name('storage.destroy-workspace');
        Route::delete('/penyimpanan-saya/logbook-harian/{entry}/{foto}', [StorageController::class, 'destroyLogbookHarian'])->name('storage.destroy-logbook-harian');
    });

    // -------------------------------------------------- pemberian bahan seminar/sidang
    Route::get('/seminar-submission/{mahasiswaTa}/create', [SeminarSubmissionController::class, 'create'])->name('seminar-submission.create');
    Route::post('/seminar-submission/{mahasiswaTa}', [SeminarSubmissionController::class, 'store'])->name('seminar-submission.store');
    Route::get('/seminar-submission/{submission}', [SeminarSubmissionController::class, 'show'])->name('seminar-submission.show');
    Route::get('/seminar-submission/{submission}/edit', [SeminarSubmissionController::class, 'edit'])->name('seminar-submission.edit');
    Route::put('/seminar-submission/{submission}', [SeminarSubmissionController::class, 'update'])->name('seminar-submission.update');
    Route::put('/seminar-submission/{submission}/hardcopy-note', [SeminarSubmissionController::class, 'updateHardcopyNote'])->name('seminar-submission.hardcopy-note');
    Route::get('/seminar-submission/{submission}/undangan/download', [SeminarSubmissionController::class, 'downloadUndangan'])->name('seminar-submission.undangan-download');
    Route::get('/seminar-submission/{submission}/materi/download', [SeminarSubmissionController::class, 'downloadMateri'])->name('seminar-submission.materi-download');
    Route::get('/seminar-submission/{submission}/dokumen/{document}/download', [SeminarSubmissionController::class, 'downloadDokumen'])->name('seminar-submission.dokumen-download');
    Route::get('/seminar-submission/{submission}/undangan/preview', [SeminarSubmissionController::class, 'previewUndangan'])->name('seminar-submission.undangan-preview');
    Route::get('/seminar-submission/{submission}/materi/preview', [SeminarSubmissionController::class, 'previewMateri'])->name('seminar-submission.materi-preview');

    // -------------------------------------------------- finalisasi TA/KP
    // NOTE: /finalisasi/review HARUS didefinisikan sebelum /finalisasi/{mahasiswaTa}
    // agar tidak tertangkap oleh route dynamic (shadowing).
    Route::get('/finalisasi/review', [FinalizationController::class, 'review'])->name('finalization.review');
    Route::get('/finalisasi/{mahasiswaTa}', [FinalizationController::class, 'index'])->name('finalization.index');
    Route::post('/finalisasi/{mahasiswaTa}', [FinalizationController::class, 'store'])->name('finalization.store');
    Route::post('/finalisasi/{finalization}/approve/{item}', [FinalizationController::class, 'approveItem'])->name('finalization.approve');
    Route::post('/finalisasi/{finalization}/reject/{item}', [FinalizationController::class, 'rejectItem'])->name('finalization.reject');
    Route::post('/finalisasi/{finalization}/unlock/{item}', [FinalizationController::class, 'unlockItem'])->name('finalization.unlock');
    Route::post('/finalisasi/{finalization}/nilai', [FinalizationController::class, 'inputNilai'])->name('finalization.nilai');

    // -------------------------------------------------- grup & cross-link (dosen)
    Route::middleware('role_or_permission:dosen|admin')->group(function () {
        Route::get('/grup', [GroupController::class, 'index'])->name('groups.index');
        Route::post('/grup', [GroupController::class, 'store'])->name('groups.store');
        Route::post('/grup/{group}/invite', [GroupController::class, 'invite'])->name('groups.invite');
        Route::post('/grup/{group}/join', [GroupController::class, 'join'])->name('groups.join');
        Route::post('/grup/{group}/approve', [GroupController::class, 'approve'])->name('groups.approve');
        Route::post('/grup/{group}/reject', [GroupController::class, 'reject'])->name('groups.reject');
    });

    // ---------------------------------------------------- chat (Fase 9)
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/start', [ChatController::class, 'start'])->name('chat.start');
    Route::get('/chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{conversation}', [ChatController::class, 'store'])->name('chat.store');
    Route::post('/chat/{conversation}/attach-options', [ChatController::class, 'attachOptions'])->name('chat.attach-options');
    Route::put('/chat/{conversation}/{message}', [ChatController::class, 'update'])->name('chat.update');

    // -------------------------------------------------- announcements (Fase 9)
    Route::get('/pengumuman', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/pengumuman/create', [AnnouncementController::class, 'create'])->name('announcements.create');
    Route::post('/pengumuman', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::get('/pengumuman/{announcement}/report', [AnnouncementController::class, 'report'])->name('announcements.report');
    Route::post('/pengumuman/{announcement}/read', [AnnouncementController::class, 'markRead'])->name('announcements.read');
    Route::post('/pengumuman/{announcement}/remind', [AnnouncementController::class, 'remindUnread'])->name('announcements.remind');

    // ---------------------------------------------------- notifikasi
    Route::get('/notifikasi', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifikasi/dropdown', [NotificationController::class, 'dropdown'])->name('notifications.dropdown');
    Route::post('/notifikasi/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::get('/notifikasi/{notification}', [NotificationController::class, 'show'])->name('notifications.show');

    // ---------------------------------------------------------- logbook
    Route::get('/logbook', [LogbookController::class, 'index'])->name('logbook.index');
    Route::get('/logbook/create', [LogbookController::class, 'create'])->name('logbook.create');
    Route::post('/logbook', [LogbookController::class, 'store'])->name('logbook.store');
    Route::get('/logbook/create-revisi', [LogbookController::class, 'createRevisi'])->name('logbook.create-revisi');
    // Alias: alamat ringkas untuk halaman yang sama (nama route lama tetap dipakai).
    Route::get('/revisi/create', [LogbookController::class, 'createRevisi'])->name('revisi.create');
    Route::post('/logbook/revisi', [LogbookController::class, 'storeRevisi'])->name('logbook.store-revisi');
    Route::get('/logbook/feedback', [LogbookController::class, 'feedback'])->name('logbook.feedback');
    Route::put('/logbook/{logbook}/feedback-note', [LogbookController::class, 'updateFeedbackNote'])->name('logbook.feedback-note');

    Route::get('/logbook/{logbook}', [LogbookController::class, 'show'])->name('logbook.show');
    Route::get('/logbook/{logbook}/edit', [LogbookController::class, 'edit'])->name('logbook.edit');
    Route::put('/logbook/{logbook}', [LogbookController::class, 'update'])->name('logbook.update');
    Route::delete('/logbook/{logbook}/lampiran', [LogbookController::class, 'removeLampiran'])->name('logbook.remove-lampiran');
    Route::delete('/logbook/{logbook}/catatan', [LogbookController::class, 'removeCatatan'])->name('logbook.remove-catatan');
    Route::delete('/logbook/{logbook}', [LogbookController::class, 'destroy'])->name('logbook.destroy');
    Route::post('/logbook/{logbook}/submit', [LogbookController::class, 'submit'])->name('logbook.submit');
    Route::post('/logbook/{logbook}/approve', [LogbookController::class, 'approve'])->name('logbook.approve');
    Route::post('/logbook/{logbook}/revisi', [LogbookController::class, 'requestRevisi'])->name('logbook.request-revisi');
    Route::post('/logbook/{logbook}/archive', [LogbookController::class, 'archive'])->name('logbook.archive');
    Route::post('/logbook/{logbook}/reopen', [LogbookController::class, 'reopen'])->name('logbook.reopen');
    Route::post('/logbook/{logbook}/reopen-revisi', [LogbookController::class, 'reopenRevisi'])->name('logbook.reopen-revisi');

    Route::get('/logbook/{logbook}/pdf', [LogbookController::class, 'pdf'])->name('logbook.pdf');
    Route::get('/logbook/{logbook}/catatan-pdf', [LogbookController::class, 'catatanPdf'])->name('logbook.catatan-pdf');
    Route::get('/logbook/{logbook}/pdf/burn', [LogbookController::class, 'burnPdf'])->name('logbook.pdf.burn');
    Route::get('/logbook/{logbook}/pdf/viewer', [LogbookController::class, 'viewer'])->name('logbook.pdf-viewer');
    Route::get('/logbook/{logbook}/pdf/comments', [LogbookController::class, 'comments'])->name('logbook.pdf.comments');
    Route::post('/logbook/{logbook}/pdf/comments', [LogbookController::class, 'storeComment'])->name('logbook.pdf.store-comment');

    Route::post('/pdf-comments/{comment}/resolve', [PdfCommentController::class, 'resolve'])->name('pdf-comments.resolve');
    Route::post('/pdf-comments/{comment}/reply', [PdfCommentController::class, 'reply'])->name('pdf-comments.reply');
    Route::delete('/pdf-comments/{comment}', [PdfCommentController::class, 'destroy'])->name('pdf-comments.destroy');

    // ---------------------------------------------------------- export
    Route::get('/logbook/export/pdf/{mahasiswaTa}', [ExportController::class, 'exportPdf'])->name('logbook.export.pdf');
    Route::get('/logbook/export/excel/{mahasiswaTa}', [ExportController::class, 'exportExcel'])->name('logbook.export.excel');

    // ---------------------------------------------------------- admin
    // Role gerbang di level prefix (admin|system_admin); permission granular
    // per grup menentukan sub-fitur mana yang benar-benar bisa diakses —
    // diatur lewat "Kelola Hak Akses" (system_admin).
    Route::prefix('admin')->name('admin.')->middleware('role_or_permission:admin|system_admin')->group(function () {
        Route::middleware('permission:admin.users')->group(function () {
            Route::get('/users', [AdminController::class, 'users'])->name('users');
            Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
            Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->name('users.destroy');
            Route::post('/users/{user}/reset-password', [AdminController::class, 'resetPassword'])->name('users.reset-password');
            Route::put('/users/{user}/nidn', [AdminController::class, 'updateUserNidn'])->name('users.nidn');

            // Aksi massal: harus bisa diakses admin institusi (dalam scope) DAN system admin.
            // Otorisasi per-user di-handle di controller via canManageUser().
            Route::post('/users/bulk', [AdminController::class, 'bulkUsers'])->name('users.bulk');
            Route::get('/users/export', [AdminController::class, 'exportUsers'])->name('users.export');

            // Admin hierarki: admin (dengan admin_scopes) bisa buat admin di bawahnya.
            Route::post('/sub-admins', [AdminController::class, 'storeSubAdmin'])->name('sub-admins.store');

        });

        Route::middleware('permission:admin.tas')->group(function () {
            Route::get('/tas', [AdminController::class, 'tas'])->name('tas');
            Route::post('/tas', [AdminController::class, 'storeTa'])->name('tas.store');
            Route::put('/tas/{mahasiswaTa}', [AdminController::class, 'updateTa'])->name('tas.update');
        });

        Route::middleware('permission:admin.bulk-review')->group(function () {
            Route::get('/entries', [AdminController::class, 'entries'])->name('entries');
            Route::post('/bulk', [AdminController::class, 'bulkAction'])->name('bulk');
        });

        Route::middleware('permission:admin.sidangs')->group(function () {
            Route::get('/sidangs', [AdminController::class, 'sidangs'])->name('sidangs');
            Route::post('/sidangs', [AdminController::class, 'storeSidang'])->name('sidangs.store');
            Route::delete('/sidangs/{sidang}', [AdminController::class, 'destroySidang'])->name('sidangs.destroy');
            Route::post('/tas/{mahasiswaTa}/status', [AdminController::class, 'updateStatusTa'])->name('tas.status');
        });

        Route::middleware('permission:admin.institution')->group(function () {
            Route::get('/institusi', [AdminController::class, 'institution'])->name('institution');
            Route::post('/institusi', [AdminController::class, 'updateInstitution'])->name('institution.update');
            // (test-mail dipindahkan ke panel system admin: admin.system.settings.test-mail)

            // Penamaan program (TA/KP) & label fase per prodi/departemen.
            Route::get('/program-naming', [AdminController::class, 'programNaming'])->name('program-naming');
            Route::post('/program-naming', [AdminController::class, 'updateProgramNaming'])->name('program-naming.update');

            // Kuota dosen per institusi (admin institusi yang berlangganan).
            Route::get('/dosen/{user}/kuota', [AdminController::class, 'dosenQuota'])->name('dosen.kuota');
            Route::post('/dosen/{user}/kuota', [AdminController::class, 'updateDosenQuota'])->name('dosen.kuota.update');
        });
    });

    // -------------------------------------------------- system admin (khusus)
    Route::prefix('admin/system')->name('admin.system.')->middleware('role:system_admin')->group(function () {
        // Kelola admin lain.
        Route::middleware('permission:system.admins')->group(function () {
            Route::get('/admins', [AdminController::class, 'systemAdmins'])->name('admins');
            Route::post('/admins', [AdminController::class, 'storeSystemAdmin'])->name('admins.store');
            Route::delete('/admins/{user}', [AdminController::class, 'destroySystemAdmin'])->name('admins.destroy');
            Route::post('/admins/{user}/reset-password', [AdminController::class, 'resetSystemAdminPassword'])->name('admins.reset-password');
        });

        // Paket & override per user (hanya system admin).
        Route::middleware('permission:system.plans')->group(function () {
            Route::get('/users/{user}/plan', [AdminController::class, 'planSettings'])->name('users.plan');
            Route::post('/users/{user}/plan', [AdminController::class, 'updatePlanSettings'])->name('users.plan.update');
            Route::post('/users/{user}/quota', [AdminController::class, 'updateUserQuota'])->name('users.quota');
            Route::post('/plans', [AdminController::class, 'updatePlanFeatures'])->name('plans.update');
            Route::post('/plans/create', [AdminController::class, 'storePlan'])->name('plans.store');
            Route::delete('/plans/{plan}', [AdminController::class, 'destroyPlan'])->name('plans.destroy');

            // Ubah institusi user — aksi platform-level, hanya system admin.
            // (Tadi route ini ada di grup `permission:admin.users` sehingga admin
            //  institusi bisa memanggil endpoint-nya; controller sudah guard
            //  isSystemAdmin(), tapi kita pindahkan ke sini untuk defense-in-depth
            //  di lapisan route: 403 langsung dari middleware.)
            Route::post('/users/{user}/institution', [AdminController::class, 'updateUserInstitution'])->name('users.institution');
        });

        // Kelola hak akses: sengaja hanya digerbangi role:system_admin (bukan
        // permission tambahan) supaya system_admin tidak bisa mengunci diri
        // sendiri dari halaman ini dengan salah klik di matrix-nya sendiri.
        Route::get('/permissions', [AdminController::class, 'permissions'])->name('permissions');
        Route::post('/permissions', [AdminController::class, 'updatePermissions'])->name('permissions.update');

        // Pengaturan autentikasi (toggle verifikasi email + form SMTP).
        Route::get('/settings', [AdminController::class, 'systemSettings'])->name('settings');
        Route::post('/settings', [AdminController::class, 'updateSystemSettings'])->name('settings.update');
        Route::post('/settings/test-mail', [AdminController::class, 'systemTestMail'])->name('settings.test-mail');

        // Langganan direktori (institusi) — assign plan ke node direktori.
        Route::get('/directory-subscriptions', [AdminController::class, 'directorySubscriptions'])->name('directory-subscriptions');
        Route::post('/directory-subscriptions', [AdminController::class, 'storeDirectorySubscription'])->name('directory-subscriptions.store');
        Route::get('/directory-subscriptions/{subscription}/edit', [AdminController::class, 'editDirectorySubscription'])->name('directory-subscriptions.edit');
        Route::put('/directory-subscriptions/{subscription}', [AdminController::class, 'updateDirectorySubscription'])->name('directory-subscriptions.update');
        Route::post('/directory-subscriptions/{subscription}/cancel', [AdminController::class, 'cancelDirectorySubscription'])->name('directory-subscriptions.cancel');

        // Kelola struktur direktori (universitas/fakultas/departemen/prodi).
        Route::get('/directory', [AdminController::class, 'directory'])->name('directory');
        Route::post('/directory/universities', [AdminController::class, 'storeDirectoryUniversity'])->name('directory.universities.store');
        Route::get('/directory/universities/{university}/edit', [AdminController::class, 'editDirectoryUniversity'])->name('directory.universities.edit');
        Route::put('/directory/universities/{university}', [AdminController::class, 'updateDirectoryUniversity'])->name('directory.universities.update');
        Route::post('/directory/faculties', [AdminController::class, 'storeDirectoryFaculty'])->name('directory.faculties.store');
        Route::get('/directory/faculties/{faculty}/edit', [AdminController::class, 'editDirectoryFaculty'])->name('directory.faculties.edit');
        Route::put('/directory/faculties/{faculty}', [AdminController::class, 'updateDirectoryFaculty'])->name('directory.faculties.update');
        Route::post('/directory/departments', [AdminController::class, 'storeDirectoryDepartment'])->name('directory.departments.store');
        Route::get('/directory/departments/{department}/edit', [AdminController::class, 'editDirectoryDepartment'])->name('directory.departments.edit');
        Route::put('/directory/departments/{department}', [AdminController::class, 'updateDirectoryDepartment'])->name('directory.departments.update');
        Route::post('/directory/study-programs', [AdminController::class, 'storeDirectoryStudyProgram'])->name('directory.study-programs.store');
        Route::get('/directory/study-programs/{studyProgram}/edit', [AdminController::class, 'editDirectoryStudyProgram'])->name('directory.study-programs.edit');
        Route::put('/directory/study-programs/{studyProgram}', [AdminController::class, 'updateDirectoryStudyProgram'])->name('directory.study-programs.update');

        // Backup & restore seluruh sistem: sengaja hanya digerbangi
        // role:system_admin (bukan permission tambahan) — alasan sama dengan
        // "Kelola Hak Akses" di atas, bahkan lebih kuat di sini karena aksi
        // restore bisa mengganti seluruh data sistem.
        Route::get('/backup', [SystemBackupController::class, 'index'])->name('backup');
        Route::post('/backup', [SystemBackupController::class, 'store'])->name('backup.store');
        Route::post('/backup/restore', [SystemBackupController::class, 'restore'])->name('backup.restore');
    });
});

// ------------------------------------------- link berbagi dokumen bahan seminar/sidang
// Tautan langsung (tanpa login) yang dipakai di email notifikasi bahan
// seminar/sidang. Keabsahan signature & masa berlaku divalidasi middleware
// `signed` (URL bertanda tangan sementara, masa berlaku dari model).
Route::middleware('signed')->group(function () {
    Route::get('/shared/seminar-submission/{submission}/undangan', [SeminarSubmissionController::class, 'sharedDownloadUndangan'])
        ->name('seminar-submission.shared-undangan');
    Route::get('/shared/seminar-submission/{submission}/materi', [SeminarSubmissionController::class, 'sharedDownloadMateri'])
        ->name('seminar-submission.shared-materi');
});

