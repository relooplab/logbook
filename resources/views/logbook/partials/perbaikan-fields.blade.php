{{-- Isian satu kartu perbaikan (dipakai loop statis di step 2 revisi). --}}
{{-- Variabel: $i (indeks baris), $row (nilai lama/prefill), $statusOptions. --}}
{{-- Opsional (wizard create-revisi): $statusList (subset status), $commentOptions
    ([['id','text','label']]) untuk select komentar + prefill status otomatis. --}}
{{-- Urutan: komentar yang dijawab -> lokasi + perbaikan sebaris -> keputusan status. --}}
@if (! empty($commentOptions ?? []))
    @php
        $komentarText = $row['komentar_dosen'] ?? '';
        $komentarManual = $komentarText !== '' && ! collect($commentOptions)->contains('text', $komentarText);
    @endphp
    <div>
        <label class="mb-1 block text-xs text-text-secondary" for="riwayat-komentar-{{ $i }}">Komentar Dosen</label>
        <select id="riwayat-komentar-{{ $i }}" data-komentar-select @if (! $komentarManual) name="riwayat_perbaikan[{{ $i }}][komentar_dosen]" @endif class="form-control">
            <option value="">— Pilih komentar —</option>
            @foreach ($commentOptions as $c)
                <option value="{{ $c['text'] }}" data-comment-id="{{ $c['id'] }}" @selected($komentarText === $c['text'])>{{ $c['label'] }}</option>
            @endforeach
            <option value="__manual" @selected($komentarManual)>Tulis manual…</option>
        </select>
        <input type="text" data-komentar-manual @if ($komentarManual) name="riwayat_perbaikan[{{ $i }}][komentar_dosen]" value="{{ $komentarText }}" @endif
            placeholder="Tulis komentar dosen…" class="form-control mt-2 @if (! $komentarManual) hidden @endif">
        @error("riwayat_perbaikan.{$i}.komentar_dosen")
            <p class="form-field-error">{{ $message }}</p>
        @enderror
    </div>
@else
<div>
    <label class="mb-1 block text-xs text-text-secondary" for="riwayat-komentar-{{ $i }}">Komentar Dosen <span class="text-status-pending">(wajib diisi bila dari isi-otomatis)</span></label>
    <input type="text" id="riwayat-komentar-{{ $i }}" name="riwayat_perbaikan[{{ $i }}][komentar_dosen]"
        value="{{ $row['komentar_dosen'] ?? '' }}" placeholder="mis. Jelaskan dasar pemilihan metode" class="form-control">
    @error("riwayat_perbaikan.{$i}.komentar_dosen")
        <p class="form-field-error">{{ $message }}</p>
    @enderror
</div>
@endif
<div class="flex gap-3">
    <div class="w-[110px] shrink-0">
        <label class="mb-1 block text-xs text-text-secondary" for="riwayat-halaman-{{ $i }}">Halaman</label>
        <input type="text" id="riwayat-halaman-{{ $i }}" name="riwayat_perbaikan[{{ $i }}][halaman]"
            value="{{ $row['halaman'] ?? '' }}" placeholder="mis. Hal. 5" class="form-control">
        @error("riwayat_perbaikan.{$i}.halaman")
            <p class="form-field-error">{{ $message }}</p>
        @enderror
    </div>
    <div class="min-w-0 flex-1">
        <label class="mb-1 block text-xs text-text-secondary" for="riwayat-perbaikan-{{ $i }}">Perbaikan yang Dilakukan</label>
        <textarea id="riwayat-perbaikan-{{ $i }}" name="riwayat_perbaikan[{{ $i }}][perbaikan]" rows="3"
            placeholder="mis. Menambah penjelasan metode di Hal. 5 paragraf 2" class="form-control">{{ $row['perbaikan'] ?? '' }}</textarea>
        @error("riwayat_perbaikan.{$i}.perbaikan")
            <p class="form-field-error">{{ $message }}</p>
        @enderror
    </div>
</div>
<div>
    <label class="mb-1 block text-xs text-text-secondary" for="riwayat-status-{{ $i }}">Status</label>
    <select id="riwayat-status-{{ $i }}" name="riwayat_perbaikan[{{ $i }}][status]" data-card-status class="form-control">
        @php $statusList = $statusList ?? $statusOptions; @endphp
        <option value="">— Pilih —</option>
        @foreach ($statusList as $s)
            <option value="{{ $s }}" @selected(($row['status'] ?? '') === $s)>{{ $s }}</option>
        @endforeach
        @if (($row['status'] ?? '') !== '' && ! in_array($row['status'], $statusList, true))
            <option value="{{ $row['status'] }}" selected>{{ $row['status'] }} (pilih ulang)</option>
        @endif
    </select>
    @error("riwayat_perbaikan.{$i}.status")
        <p class="form-field-error">{{ $message }}</p>
    @enderror
</div>
