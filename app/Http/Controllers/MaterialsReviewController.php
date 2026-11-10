<?php

namespace App\Http\Controllers;

use App\Models\LogbookEntry;
use App\Models\MahasiswaTa;
use App\Models\ReviewDraft;
use App\Services\BulkLogbookReview;
use App\Services\MaterialsReviewQueue;
use Illuminate\Http\RedirectResponse;
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

    /**
     * Aksi massal pada antrean: setujui atau arsipkan entri terpilih.
     *
     * Entri yang sudah diproses reviewer lain atau bukan kewenangan dosen ini
     * dilewati (dihitung), bukan menggagalkan seluruh batch.
     */
    public function bulk(Request $request, BulkLogbookReview $bulk): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isDosen(), 403);

        $data = $request->validate([
            'aksi' => ['required', 'in:approve,archive'],
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer'],
            'archive_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $entries = LogbookEntry::with('mahasiswaTa')
            ->whereIn('id', $data['ids'])
            ->get();

        $diproses = 0;
        $dilewati = 0;

        foreach ($entries as $entry) {
            // Kewenangan & status program sama seperti aksi satuan.
            $bolehReview = $user->can('review', $entry);
            $programAktif = in_array(
                $entry->mahasiswaTa?->status_ta,
                [MahasiswaTa::STATUS_AKTIF, MahasiswaTa::STATUS_PENDING_APPROVAL],
                true
            );

            if (! $bolehReview || ! $programAktif) {
                $dilewati++;

                continue;
            }

            $ok = $data['aksi'] === 'approve'
                ? $bulk->approve($entry, $user)
                : $bulk->archive($entry, $user, $data['archive_reason'] ?? null);

            $ok ? $diproses++ : $dilewati++;
        }

        // Entri yang tidak ditemukan / bukan milik dosen tetap dihitung lewat.
        $dilewati += max(0, count($data['ids']) - $entries->count());

        $pesan = $data['aksi'] === 'approve'
            ? $diproses.' entri disetujui'
            : $diproses.' entri diarsipkan';

        if ($dilewati > 0) {
            $pesan .= ', '.$dilewati.' dilewati (sudah diproses / bukan kewenangan Anda)';
        }

        return back()->with($diproses > 0 ? 'success' : 'warning', $pesan.'.');
    }
}
