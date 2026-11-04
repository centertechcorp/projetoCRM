<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://crm.exemplo.com',
            'whatsapp.waha.accounts' => [
                'center' => ['base_url' => 'http://center.local', 'api_key' => 'k-center', 'session' => 'CENTER'],
                'genius' => ['base_url' => 'http://genius.local', 'api_key' => 'k-genius', 'session' => 'GENIUS'],
            ],
        ]);
    }

    private function hook(string $store, array $events = ['message', 'message.any', 'session.status'], ?string $hmac = 'segredo'): array
    {
        return ['url' => "https://crm.exemplo.com/api/whatsapp/waha/{$store}", 'events' => $events,
            'hmac' => $hmac === null ? [] : ['key' => $hmac]];
    }

    private function fakeAll(array $center = [], array $genius = []): void
    {
        $version = ['version' => '2026.9.2', 'engine' => 'WEBJS', 'tier' => 'CORE'];

        Http::fake([
            'http://center.local/api/server/version' => Http::response($version),
            'http://genius.local/api/server/version' => Http::response($version),
            'http://center.local/api/sessions/CENTER' => $center['session'] ?? Http::response([
                'status' => 'WORKING', 'config' => ['webhooks' => [$this->hook('center')]]]),
            'http://genius.local/api/sessions/GENIUS' => $genius['session'] ?? Http::response([
                'status' => 'WORKING', 'config' => ['webhooks' => [$this->hook('genius')]]]),
        ]);
    }

    public function test_everything_right_passes_and_prints_the_expected_webhook(): void
    {
        $this->fakeAll();

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('Tudo certo com o WAHA')
            ->expectsOutputToContain('https://crm.exemplo.com/api/whatsapp/waha/center')
            ->assertSuccessful();
    }

    public function test_it_never_changes_anything_in_the_waha(): void
    {
        $this->fakeAll();

        $this->artisan('whatsapp:waha:check')->assertSuccessful();

        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_a_refused_api_key_is_reported(): void
    {
        Http::fake(['*' => Http::response([], 401)]);

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('Chave de API recusada')
            ->assertFailed();
    }

    public function test_an_unreachable_waha_is_reported(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('inacessível')
            ->assertFailed();
    }

    public function test_a_missing_session_is_reported(): void
    {
        $this->fakeAll(center: ['session' => Http::response([], 404)]);

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('não existe nesse WAHA')
            ->assertFailed();
    }

    public function test_a_session_waiting_for_qr_is_a_problem(): void
    {
        $this->fakeAll(genius: ['session' => Http::response(['status' => 'SCAN_QR_CODE', 'config' => ['webhooks' => [$this->hook('genius')]]])]);

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('SCAN_QR_CODE')
            ->assertFailed();
    }

    public function test_a_webhook_pointing_somewhere_else_is_reported(): void
    {
        $this->fakeAll(center: ['session' => Http::response(['status' => 'WORKING', 'config' => ['webhooks' => [
            ['url' => 'https://outro.com/hook', 'events' => ['message'], 'hmac' => ['key' => 'x']]]]])]);

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('Nenhum webhook aponta para https://crm.exemplo.com/api/whatsapp/waha/center')
            ->assertFailed();
    }

    public function test_missing_events_and_missing_hmac_are_reported(): void
    {
        $this->fakeAll(center: ['session' => Http::response(['status' => 'WORKING', 'config' => ['webhooks' => [
            $this->hook('center', events: ['message'], hmac: null)]]])]);

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('Faltam eventos no webhook: message.any, session.status')
            ->expectsOutputToContain('não tem chave HMAC')
            ->assertFailed();
    }

    public function test_two_stores_on_one_core_instance_are_flagged(): void
    {
        config(['whatsapp.waha.accounts.genius.base_url' => 'http://center.local']);
        Http::fake([
            'http://center.local/api/server/version' => Http::response(['version' => '1', 'engine' => 'WEBJS', 'tier' => 'CORE']),
            'http://center.local/api/sessions/CENTER' => Http::response(['status' => 'WORKING', 'config' => ['webhooks' => [$this->hook('center')]]]),
            'http://center.local/api/sessions/GENIUS' => Http::response(['status' => 'WORKING', 'config' => ['webhooks' => [$this->hook('genius')]]]),
        ]);

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('WAHA CORE (1 sessão por instância)')
            ->assertFailed();
    }

    public function test_session_names_and_webhook_url_can_be_customised(): void
    {
        config(['whatsapp.waha.accounts.center.session' => 'loja-centro']);
        Http::fake([
            'http://center.local/api/server/version' => Http::response(['version' => '1', 'engine' => 'NOWEB', 'tier' => 'PLUS']),
            'http://center.local/api/sessions/loja-centro' => Http::response(['status' => 'WORKING', 'config' => ['webhooks' => [
                ['url' => 'http://app/api/whatsapp/waha/center', 'events' => ['message', 'message.any', 'session.status'], 'hmac' => ['key' => 'k']]]]]),
            'http://genius.local/*' => Http::response([], 404),
        ]);

        $this->artisan('whatsapp:waha:check', ['--webhook-url' => 'http://app'])
            ->expectsOutputToContain('Webhook ok: http://app/api/whatsapp/waha/center')
            ->assertFailed(); // genius ainda falha (404), center passou
    }

    public function test_missing_configuration_is_reported_without_calling_anything(): void
    {
        config(['whatsapp.waha.accounts.center.api_key' => null]);
        Http::fake();

        $this->artisan('whatsapp:waha:check')
            ->expectsOutputToContain('Chave de API não configurada')
            ->assertFailed();
    }
}
