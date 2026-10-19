<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regresi: notifikasi realtime di BROWSER putus karena dua hal yang saling
 * melengkapi dan keduanya tidak terlihat dari sisi server:
 *
 *  1. nginx tidak punya location untuk handshake WebSocket (`/app/<key>`),
 *     sehingga permintaan jatuh ke Laravel => 404/502.
 *  2. blok JS memakai `broadcasting.connections.reverb.options.host` yang
 *     berisi alamat internal (mis. logbook-ta-reverb:8080) — hostname yang
 *     tidak bisa di-resolve dari komputer user.
 *
 * Server-side broadcast tetap "sukses" pada kedua kondisi di atas, jadi
 * kegagalannya senyap: event terkirim tapi tidak pernah sampai ke klien.
 */
class RealtimeWebsocketTest extends TestCase
{
    public function test_nginx_mem_proxy_handshake_websocket_ke_reverb(): void
    {
        $conf = file_get_contents(base_path('nginx/default.conf'));

        $this->assertStringContainsString('location ~ ^/(app|apps)/', $conf, 'Handshake /app/ harus punya location sendiri.');
        $this->assertStringContainsString('logbook-ta-reverb:8080', $conf, 'Proxy harus menuju service reverb.');
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $conf);
        $this->assertStringContainsString('proxy_set_header Connection $connection_upgrade;', $conf);
        // map wajib ada supaya Connection hanya "upgrade" saat klien meminta.
        $this->assertStringContainsString('map $http_upgrade $connection_upgrade', $conf);
    }

    public function test_layout_memakai_endpoint_publik_untuk_browser_bukan_host_internal(): void
    {
        $blade = file_get_contents(resource_path('views/layouts/app.blade.php'));

        // Host/port/scheme browser harus berasal dari config client_* dan
        // fallback ke lokasi halaman itu sendiri.
        $this->assertStringContainsString('reverb.client_host', $blade);
        $this->assertStringContainsString('reverb.client_port', $blade);
        $this->assertStringContainsString('reverb.client_scheme', $blade);
        $this->assertStringContainsString('window.location.hostname', $blade);

        // Jangan pernah memakai alamat internal untuk klien.
        $this->assertStringNotContainsString("reverb.host') }", $blade, 'Host internal tidak boleh dipakai klien.');
        $this->assertDoesNotMatchRegularExpression(
            '/var rHost = @json\(config\(\'broadcasting\.connections\.reverb\.host\'\)\)/',
            $blade,
        );
    }

    public function test_config_menyediakan_override_endpoint_websocket_klien(): void
    {
        // Default: kosong => klien memakai host halaman (single-host via nginx).
        $this->assertNull(config('broadcasting.connections.reverb.client_host'));
        $this->assertNull(config('broadcasting.connections.reverb.client_port'));
        $this->assertNull(config('broadcasting.connections.reverb.client_scheme'));

        // Endpoint internal tetap terpisah dan tidak dipakai browser.
        $this->assertSame('logbook-ta-reverb', config('broadcasting.connections.reverb.options.host'));

        // Override tersedia untuk deployment yang memisahkan domain WS.
        config([
            'broadcasting.connections.reverb.client_host' => 'ws.example.test',
            'broadcasting.connections.reverb.client_port' => 443,
            'broadcasting.connections.reverb.client_scheme' => 'https',
        ]);

        $this->assertSame('ws.example.test', config('broadcasting.connections.reverb.client_host'));
        $this->assertSame(443, config('broadcasting.connections.reverb.client_port'));
        $this->assertSame('https', config('broadcasting.connections.reverb.client_scheme'));
    }

    public function test_handshake_websocket_tidak_dilayani_oleh_route_laravel(): void
    {
        // /app/<key> bukan route aplikasi: kalau nginx salah konfigurasi,
        // request ini berakhir di sini sebagai 404 dan realtime mati.
        $this->get('/app/test-key?protocol=7&client=js')->assertNotFound();
    }
}
