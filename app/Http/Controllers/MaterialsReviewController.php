<?php

namespace App\Http\Controllers;

use App\Models\ReviewDraft;
use App\Services\MaterialsReviewQueue;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman antrean review bahan mahasiswa yang belum ditinjau.
 * Dosen dapat membukanya dari pengingat di dashboard atau menu sidebar.
 */
class MaterialsReviewController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isDosen(), 403);

        [$logbook, $seminar] = array_values(app(MaterialsReviewQueue::class)->pendingFor($user));
        $logbook->loadMissing('reviewOpener');

        // Peta entri yang punya draf keputusan milik dosen (chip indikator).
        $draftEntryIds = $logbook->isNotEmpty()
            ? ReviewDraft::where('user_id', $user->id)
                ->whereIn('logbook_entry_id', $logbook->pluck('id')->all())
                ->pluck('logbook_entry_id')
            : collect();

        // Entri dengan notifikasi belum dibaca yang menunjuk ke sana (chip
        // "Baru"): dosen dipanggil, bukan mengecek.
        $unreadEntryIds = collect();
        if ($logbook->isNotEmpty()) {
            $ids = $logbook->pluck('id')->all();
            foreach ($user->unreadNotifications as $notif) {
                $url = (string) ($notif->data['url'] ?? '');
                foreach ($ids as $id) {
                    if ($url !== '' && (str_contains($url, '/logbook/'.$id) || str_contains($url, 'quick-review/'.$id))) {
                        $unreadEntryIds->push($id);
                        break;
                    }
                }
            }
            $unreadEntryIds = $unreadEntryIds->unique()->values();
        }

        return view('materials-review.index', compact('logbook', 'seminar', 'draftEntryIds', 'unreadEntryIds'));
    }
}
