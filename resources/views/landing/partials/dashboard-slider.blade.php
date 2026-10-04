<div class="landing-slider" data-dashboard-slider role="region" aria-roledescription="carousel" aria-label="Preview dashboard">
    <div class="landing-slider-top" data-slider-controls hidden>
        <div class="landing-slider-choices" role="group" aria-label="Pilih dashboard">
            <button type="button" data-slide-select="0" aria-controls="dashboard-slide-mahasiswa" aria-pressed="true">Mahasiswa</button>
            <button type="button" data-slide-select="1" aria-controls="dashboard-slide-dosen" aria-pressed="false">Dosen</button>
        </div>
    </div>
    <div class="landing-slider-viewport" data-slider-viewport>
        <div class="landing-slider-track" data-slider-track>
            <figure id="dashboard-slide-mahasiswa" class="landing-screenshot landing-screenshot-present" data-dashboard-slide role="group" aria-roledescription="slide" aria-label="1 dari 2: Dashboard mahasiswa">
                <img src="{{ $dashboardImages['mahasiswa'] }}" alt="Tampilan dashboard mahasiswa dengan ringkasan progres bimbingan" width="1705" height="922" loading="lazy">
                <figcaption class="landing-slider-caption"><span class="material-symbols-outlined icon-sm" aria-hidden="true">school</span> Dashboard mahasiswa</figcaption>
            </figure>
            <figure id="dashboard-slide-dosen" class="landing-screenshot landing-screenshot-present" data-dashboard-slide role="group" aria-roledescription="slide" aria-label="2 dari 2: Dashboard dosen">
                <img src="{{ $dashboardImages['dosen'] }}" alt="Tampilan dashboard dosen dengan ringkasan mahasiswa dan aktivitas bimbingan" width="1705" height="922" loading="lazy">
                <figcaption class="landing-slider-caption"><span class="material-symbols-outlined icon-sm" aria-hidden="true">co_present</span> Dashboard dosen</figcaption>
            </figure>
        </div>
    </div>
    <div class="landing-slider-bottom" data-slider-controls hidden>
        <div class="landing-slider-navigation">
            <button type="button" class="landing-slider-btn" data-slide-prev aria-label="Dashboard sebelumnya">←</button>
            <span data-slide-status>01 / 02</span>
            <button type="button" class="landing-slider-btn" data-slide-next aria-label="Dashboard berikutnya">→</button>
        </div>
        <button type="button" class="landing-slider-btn landing-slider-play" data-slide-play aria-pressed="false" aria-label="Jeda otomatis"><span class="material-symbols-outlined icon-sm" aria-hidden="true">pause</span></button>
    </div>
</div>