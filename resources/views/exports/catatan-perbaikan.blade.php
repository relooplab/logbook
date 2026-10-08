<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Catatan Perbaikan</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 4px; }
        h2 { font-size: 11px; text-align: center; font-weight: normal; margin: 0 0 14px; color: #333; }
        .info { margin-bottom: 14px; }
        .info table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 4px; vertical-align: top; font-size: 11px; }
        .pesan { border: 1px solid #999; padding: 8px 10px; margin-bottom: 14px; background: #f9f9f9; }
        .pesan .label { font-weight: bold; font-size: 10px; text-transform: uppercase; color: #555; margin-bottom: 4px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #555; padding: 5px 6px; font-size: 10px; vertical-align: top; }
        table.data th { background: #eee; text-align: left; }
        .status-sudah { color: #15803d; font-weight: bold; }
        .status-sebagian { color: #b45309; font-weight: bold; }
        .status-belum { color: #b91c1c; font-weight: bold; }
        .status-draf { color: #666; font-weight: bold; }
        .footer { margin-top: 20px; text-align: right; font-size: 10px; }
    </style>
</head>
<body>
    @php $inst = \App\Models\Institution::forUser($logbook->mahasiswaTa?->pembimbing1); @endphp
    <h1>CATATAN PERBAIKAN</h1>
    <h2>{{ $logbook->mahasiswaTa?->mahasiswa?->name }} · {{ $logbook->mahasiswaTa?->mahasiswa?->nim }}</h2>

    <div class="info">
        <table>
            <tr><td style="width:140px"><strong>Tanggal Pengiriman</strong></td><td>: {{ $logbook->tanggal_pengiriman?->format('d/m/Y') ?? '—' }}</td></tr>
            <tr><td><strong>Topik / Bagian</strong></td><td>: {{ $logbook->topik ?: '—' }}</td></tr>
        </table>
    </div>

    @if ($pesan)
        <div class="pesan">
            <div class="label">Pesan untuk Dosen</div>
            <div>{{ $pesan }}</div>
        </div>
    @endif

    <table class="data">
        <thead>
            <tr>
                <th style="width:6%">No</th>
                <th style="width:16%">Halaman/Bagian</th>
                <th style="width:28%">Komentar Dosen</th>
                <th style="width:34%">Perbaikan yang Dilakukan</th>
                <th style="width:16%">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($riwayat as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r['halaman'] ?? '—' }}</td>
                    <td>{{ $r['komentar_dosen'] ?? '—' }}</td>
                    <td>{{ $r['perbaikan'] ?? '—' }}</td>
                    <td>
                        @php $status = $r['status'] ?? '—'; @endphp
                        <span class="
                            {{ $status === 'Sudah' ? 'status-sudah' : '' }}
                            {{ $status === 'Sebagian' ? 'status-sebagian' : '' }}
                            {{ $status === 'Belum' ? 'status-belum' : '' }}
                            {{ $status === 'Draf' ? 'status-draf' : '' }}
                        ">{{ $status }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center">Tidak ada data perbaikan.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        {{ $inst->city ? $inst->city.', ' : '' }}{{ now()->translatedFormat('d F Y') }}
    </div>
</body>
</html>