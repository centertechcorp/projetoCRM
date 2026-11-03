<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Travas de produção (o app roda atrás de um proxy HTTPS e não pode expor a rota de teste do WAHA).
 */
class ProductionHardeningTest extends TestCase
{
    public function test_urls_are_generated_as_https_when_the_proxy_says_so(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'crm.exemplo.com'])
            ->get('/login');

        $response->assertOk();
        $response->assertSee('https://crm.exemplo.com', false);
    }

    public function test_the_open_waha_test_route_does_not_exist_in_production(): void
    {
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));

        $uris = fn (string $env): array => array_column(
            json_decode((string) shell_exec("APP_ENV={$env} {$php} {$artisan} route:list --path=api/whatsapp --json 2>/dev/null"), true) ?? [],
            'uri',
        );

        $production = $uris('production');
        $local = $uris('local');

        $this->assertContains('api/whatsapp/waha/{store}', $production);
        $this->assertNotContains('api/whatsapp/waha-test/{store}', $production);
        $this->assertContains('api/whatsapp/waha-test/{store}', $local);
    }
}
