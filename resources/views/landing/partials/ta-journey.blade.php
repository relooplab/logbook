@php
    $phaseDescriptions = [
        'penyusunan_proposal' => ['Tentukan arah penelitian.', 'Susun latar belakang, rumusan masalah, dan metode bersama pembimbing.', 'description'],
        'proposal' => ['Presentasikan rencana Anda.', 'Siapkan bahan seminar proposal dan catat masukan untuk langkah berikutnya.', 'co_present'],
        'pengumpulan_data' => ['Bangun dasar penelitian.', 'Dokumentasikan pengumpulan data, temuan lapangan, dan diskusi bimbingan.', 'database'],
        'penyusunan_laporan' => ['Ubah temuan menjadi laporan.', 'Susun analisis dan pembahasan, kirim draft, lalu tindak lanjuti review dosen.', 'edit_document'],
        'seminar_hasil' => ['Bagikan hasil penelitian.', 'Siapkan bahan seminar hasil dan dokumentasikan masukan serta revisinya.', 'bar_chart'],
        'draft_sidang' => ['Rapikan sebelum sidang.', 'Lengkapi draft akhir dan berkas pendukung sesuai arahan pembimbing.', 'fact_check'],
        'sidang' => ['Pertanggungjawabkan karya Anda.', 'Siapkan presentasi sidang dan catat revisi dari pembimbing serta penguji.', 'school'],
        'achievement' => ['Tuntaskan perjalanan TA.', 'Selesaikan revisi akhir dan finalisasi. Riwayat bimbingan tetap tersimpan.', 'workspace_premium'],
    ];
@endphp
<section id="alur" class="landing-container landing-ta-section" aria-labelledby="alur-title" data-ta-journey>
    <div class="landing-ta-heading">
        <div><span class="landing-section-label">DARI PROPOSAL HINGGA SELESAI</span><h2 id="alur-title" class="landing-heading mt-4">Satu perjalanan.<br>Delapan langkah yang jelas.</h2></div>
        <p class="text-text-secondary leading-relaxed max-w-md">Lihat bagaimana setiap fase TA terhubung. Pilih fase untuk menjelajah; ini ilustrasi alur, bukan progres akun Anda.</p>
    </div>
    <div class="landing-ta-shell">
        <div class="landing-ta-toolbar"><span class="landing-section-label">PERJALANAN TUGAS AKHIR</span><button type="button" class="landing-ta-play" data-ta-play aria-pressed="false" hidden>Putar animasi</button></div>
        <ol class="landing-ta-timeline" aria-label="Fase perjalanan TA">
            @foreach($taPhases as $key => $label)
                <li data-ta-step="{{ $loop->index }}" data-phase-color="{{ $loop->index }}" class="{{ $loop->first ? 'is-current' : '' }}">
                    <button type="button" data-ta-select="{{ $loop->index }}" aria-controls="ta-phase-detail-{{ $key }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                        <span class="landing-ta-node" aria-hidden="true">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><span>{{ $label }}</span>
                    </button>
                </li>
            @endforeach
        </ol>
        <div class="landing-ta-details">
            @foreach($taPhases as $key => $label)
                <article id="ta-phase-detail-{{ $key }}" data-ta-detail="{{ $loop->index }}" data-phase-color="{{ $loop->index }}" class="landing-ta-detail" @if(!$loop->first) hidden @endif>
                    <span class="landing-ta-detail-icon material-symbols-outlined" aria-hidden="true">{{ $phaseDescriptions[$key][2] }}</span>
                    <div><span class="landing-section-label">FASE {{ $loop->iteration }} / {{ count($taPhases) }} · {{ $label }}</span><h3>{{ $phaseDescriptions[$key][0] }}</h3><p>{{ $phaseDescriptions[$key][1] }}</p></div>
                </article>
            @endforeach
        </div>
    </div>
</section>