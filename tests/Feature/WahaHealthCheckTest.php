<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'whatsapp.waha.alert_phones' => ['5534900000000', '5519900000001'],
            'whatsapp.waha.accounts.center' => [
                'base_url' => 'http://center.local', 'api_key' => 'key-center', 'session' => 'CENTER',
            ],
            'whatsapp.waha.accounts.genius' => [
                'base_url' => 'http://genius.local', 'api_key' => 'key-genius', 'session' => 'GENIUS',
            ],
        ]);
    }

    public function test_both_working_does_nothing_and_clears_the_painel_banner(): void
    {
        Http::fake([
            'http://center.local/api/sessions/CENTER' => Http::response(['status' => 'WORKING']),
            'http://genius.local/api/sessions/GENIUS' => Http::response(['status' => 'WORKING']),
        ]);

        $this->artisan('whatsapp:waha:health-check')->assertSuccessful();

        $this->assertSame([], Cache::get('waha_down_stores'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/restart') || str_contains($r->url(), '/sendText'));
    }

    public function test_first_time_down_tries_to_restart_but_does_not_alert_yet(): void
    {
        Http::fake([
            'http://center.local/api/sessions/CENTER' => Http::response(['status' => 'SCAN_QR_CODE']),
            'http://center.local/api/sessions/CENTER/restart' => Http::response([]),
            'http://genius.local/api/sessions/GENIUS' => Http::response(['status' => 'WORKING']),
        ]);

        $this->artisan('whatsapp:waha:health-check')->assertFailed();

        Http::assertSent(fn ($r) => $r->url() === 'http://center.local/api/sessions/CENTER/restart');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendText'));
        $this->assertSame(['center'], Cache::get('waha_down_stores'));
    }

    public function test_still_down_on_the_next_check_sends_a_real_whatsapp_alert_via_the_working_store(): void
    {
        Http::fake([
            'http://center.local/api/sessions/CENTER' => Http::response(['status' => 'SCAN_QR_CODE']),
            'http://center.local/api/sessions/CENTER/restart' => Http::response([]),
            'http://genius.local/api/sessions/GENIUS' => Http::response(['status' => 'WORKING']),
            'http://genius.local/api/sendText' => Http::response([], 201),
        ]);

        $this->artisan('whatsapp:waha:health-check'); // 1ª checagem: só tenta reconectar
        $this->artisan('whatsapp:waha:health-check'); // 2ª checagem: continua caído, alerta

        foreach (['5534900000000', '5519900000001'] as $phone) {
            Http::assertSent(function ($request) use ($phone) {
                return $request->url() === 'http://genius.local/api/sendText'
                    && $request['session'] === 'GENIUS'
                    && $request['chatId'] === "{$phone}@c.us"
                    && str_contains($request['text'], 'CENTER');
            });
        }
    }

    public function test_once_alerted_it_does_not_send_a_second_whatsapp_while_still_down(): void
    {
        Http::fake([
            'http://center.local/api/sessions/CENTER' => Http::response(['status' => 'SCAN_QR_CODE']),
            'http://center.local/api/sessions/CENTER/restart' => Http::response([]),
            'http://genius.local/api/sessions/GENIUS' => Http::response(['status' => 'WORKING']),
            'http://genius.local/api/sendText' => Http::response([], 201),
        ]);

        $this->artisan('whatsapp:waha:health-check'); // tenta reconectar
        $this->artisan('whatsapp:waha:health-check'); // alerta
        $this->artisan('whatsapp:waha:health-check'); // continua caído, já alertou

        // 2 números configurados, 1 episódio de alerta só = 2 envios no total (1 por número).
        $sent = Http::recorded(fn ($request) => $request->url() === 'http://genius.local/api/sendText');
        $this->assertCount(2, $sent);
    }

    public function test_both_stores_down_cannot_whatsapp_but_still_feeds_the_painel_banner(): void
    {
        Http::fake([
            'http://center.local/api/sessions/CENTER' => Http::response(['status' => 'SCAN_QR_CODE']),
            'http://center.local/api/sessions/CENTER/restart' => Http::response([]),
            'http://genius.local/api/sessions/GENIUS' => Http::response(['status' => 'FAILED']),
            'http://genius.local/api/sessions/GENIUS/restart' => Http::response([]),
        ]);

        $this->artisan('whatsapp:waha:health-check');
        $this->artisan('whatsapp:waha:health-check');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendText'));
        $this->assertEqualsCanonicalizing(['center', 'genius'], Cache::get('waha_down_stores'));
    }

    public function test_recovering_clears_the_cached_state_and_the_banner(): void
    {
        Http::fake([
            'http://center.local/api/sessions/CENTER' => Http::sequence()
                ->push(['status' => 'SCAN_QR_CODE'])
                ->push(['status' => 'WORKING']),
            'http://center.local/api/sessions/CENTER/restart' => Http::response([]),
            'http://genius.local/api/sessions/GENIUS' => Http::response(['status' => 'WORKING']),
        ]);

        $this->artisan('whatsapp:waha:health-check'); // cai, tenta reconectar
        $this->assertSame(['center'], Cache::get('waha_down_stores'));

        $this->artisan('whatsapp:waha:health-check')->assertSuccessful(); // voltou

        $this->assertSame([], Cache::get('waha_down_stores'));
        $this->assertFalse(Cache::has('waha_restart_attempted:center'));
    }
}
