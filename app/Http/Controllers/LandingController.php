<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\MahasiswaTa;
use App\Support\ReleaseVersion;
use Illuminate\Contracts\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        // Beranda tetap dapat dibuka saat database belum siap / sedang dipulihkan.
        try {
            $institution = Institution::active();
        } catch (\Throwable $e) {
            $institution = null;
        }

        return view('landing.index', [
            'appName' => $institution?->app_name ?: config('app.name'),
            'institutionName' => $institution?->institution_name,
            'adminContactEmail' => $institution?->admin_contact_email,
            'version' => ReleaseVersion::get(),
            'taPhases' => MahasiswaTa::FASES,
            'dashboardImages' => collect(['mahasiswa', 'dosen'])->mapWithKeys(function (string $role) {
                $path = 'images/dashboard-'.$role.'.jpg';

                // Replacing a screenshot must invalidate browser/CDN image caches.
                return [$role => asset($path).'?v='.hash_file('sha256', public_path($path))];
            })->all(),
        ]);
    }
}