<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Diagnóstico SOMENTE LEITURA da conexão com o WAHA (próprio ou de terceiros): confere URL,
 * chave de API, versão/tier, se a sessão existe e se o webhook dela aponta para este CRM.
 * Não altera nada no WAHA — no fim imprime o JSON de webhook que deve estar configurado lá.
 */
#[Signature('whatsapp:waha:check {--webhook-url= : URL base do CRM que o WAHA enxerga (padrão: APP_URL)}')]
#[Description('Confere (só leitura) URL, chave, sessão e webhook do WAHA de cada loja e mostra o webhook esperado')]
class WahaCheck extends Command
{
    /** Eventos que o CRM precisa receber (ver WahaWebhookController / ProcessWahaWebhook). */
    public const REQUIRED_EVENTS = ['message', 'message.any', 'session.status'];

    public function handle(): int
    {
        $crmUrl = rtrim((string) ($this->option('webhook-url') ?: config('app.url')), '/');
        $accounts = config('whatsapp.waha.accounts', []);
        $problems = 0;
        $sameInstance = [];

        foreach ($accounts as $store => $account) {
            $this->newLine();
            $this->line('<options=bold>'.strtoupper($store).'</>  ('.($account['base_url'] ?? '—').', sessão '.($account['session'] ?? '—').')');

            $problems += $this->checkStore($store, $account, $crmUrl, $sameInstance);
        }

        $problems += $this->checkSharedInstance($sameInstance);

        $this->printExpectedWebhooks($crmUrl, $accounts);

        $this->newLine();
        $problems === 0
            ? $this->components->info('Tudo certo com o WAHA.')
            : $this->components->error("{$problems} problema(s) acima — corrija antes de ligar o webhook.");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{base_url?: string, api_key?: string, session?: string}  $account
     * @param  array<string, array{tier: string, sessions: array<int, string>}>  $sameInstance
     */
    private function checkStore(string $store, array $account, string $crmUrl, array &$sameInstance): int
    {
        $baseUrl = $account['base_url'] ?? null;
        $apiKey = $account['api_key'] ?? null;
        $session = $account['session'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($session) || $session === '') {
            $this->components->error('URL ou nome da sessão não configurados (WAHA_'.strtoupper($store).'_BASE_URL / _SESSION).');

            return 1;
        }

        if (! is_string($apiKey) || $apiKey === '') {
            $this->components->error('Chave de API não configurada (WAHA_'.strtoupper($store).'_API_KEY).');

            return 1;
        }

        $base = rtrim($baseUrl, '/');

        try {
            $version = Http::withHeaders(['X-Api-Key' => $apiKey])->timeout(8)->get("{$base}/api/server/version");
        } catch (Throwable $e) {
            $this->components->error('WAHA inacessível a partir do CRM: '.$e->getMessage());

            return 1;
        }

        if (in_array($version->status(), [401, 403], true)) {
            $this->components->error('Chave de API recusada pelo WAHA (HTTP '.$version->status().').');

            return 1;
        }

        if (! $version->successful()) {
            $this->components->error('O WAHA respondeu HTTP '.$version->status().' em /api/server/version.');

            return 1;
        }

        $tier = (string) $version->json('tier', '?');
        $this->components->info(sprintf('WAHA %s, motor %s, tier %s.', $version->json('version', '?'), $version->json('engine', '?'), $tier));
        $sameInstance[rtrim($baseUrl, '/')]['tier'] = $tier;
        $sameInstance[rtrim($baseUrl, '/')]['sessions'][] = $session;

        $response = Http::withHeaders(['X-Api-Key' => $apiKey])->timeout(8)->get("{$base}/api/sessions/{$session}");

        if ($response->status() === 404) {
            $this->components->error("A sessão \"{$session}\" não existe nesse WAHA — precisa ser criada e ter o QR escaneado.");

            return 1;
        }

        if (! $response->successful()) {
            $this->components->error('O WAHA respondeu HTTP '.$response->status().' ao consultar a sessão.');

            return 1;
        }

        $problems = 0;
        $status = (string) $response->json('status', '?');

        if ($status === 'WORKING') {
            $this->components->info('Sessão conectada (WORKING).');
        } else {
            $this->components->warn("Sessão em \"{$status}\" — precisa estar WORKING (escanear o QR, se for o caso).");
            $problems++;
        }

        return $problems + $this->checkWebhook($store, $response->json('config.webhooks', []), $crmUrl);
    }

    /** @param  array<int, array<string, mixed>>  $webhooks */
    private function checkWebhook(string $store, array $webhooks, string $crmUrl): int
    {
        $expected = "{$crmUrl}/api/whatsapp/waha/{$store}";
        $hook = collect($webhooks)->first(fn ($w) => rtrim((string) ($w['url'] ?? ''), '/') === $expected);

        if ($hook === null) {
            $this->components->error("Nenhum webhook aponta para {$expected}.");

            return 1;
        }

        $problems = 0;
        $missing = array_values(array_diff(self::REQUIRED_EVENTS, (array) ($hook['events'] ?? [])));

        if ($missing !== []) {
            $this->components->error('Faltam eventos no webhook: '.implode(', ', $missing).'.');
            $problems++;
        }

        if (empty($hook['hmac']['key'] ?? null)) {
            $this->components->error('O webhook não tem chave HMAC — o CRM recusa (401) em produção.');
            $problems++;
        }

        if ($problems === 0) {
            $this->components->info("Webhook ok: {$expected}.");
        }

        return $problems;
    }

    /** @param  array<string, array{tier: string, sessions: array<int, string>}>  $instances */
    private function checkSharedInstance(array $instances): int
    {
        $problems = 0;

        foreach ($instances as $url => $info) {
            if (strtoupper($info['tier']) === 'CORE' && count(array_unique($info['sessions'])) > 1) {
                $this->newLine();
                $this->components->error("{$url} é WAHA CORE (1 sessão por instância) mas as duas lojas apontam para ele — precisa de 2 instâncias ou do WAHA Plus.");
                $problems++;
            }
        }

        return $problems;
    }

    /** @param  array<string, array{base_url?: string, api_key?: string, session?: string}>  $accounts */
    private function printExpectedWebhooks(string $crmUrl, array $accounts): void
    {
        $this->newLine();
        $this->line('<options=bold>Webhook esperado em cada sessão do WAHA</> (a chave HMAC é a WAHA_WEBHOOK_HMAC_KEY do CRM):');

        foreach ($accounts as $store => $account) {
            $json = json_encode([
                'config' => ['webhooks' => [[
                    'url' => "{$crmUrl}/api/whatsapp/waha/{$store}",
                    'events' => self::REQUIRED_EVENTS,
                    'hmac' => ['key' => '<WAHA_WEBHOOK_HMAC_KEY>'],
                ]]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $this->line("\n# ".strtoupper($store).' — sessão '.($account['session'] ?? '?')."\n{$json}");
        }
    }
}
