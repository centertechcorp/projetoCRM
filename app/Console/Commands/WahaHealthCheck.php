<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roda a cada 5 min (routes/console.php). Por loja: se caiu, tenta reconectar sozinho uma vez
 * (igual já fizemos na mão várias vezes); se na checagem seguinte continuar caída, manda um
 * alerta de WhatsApp de verdade pro Caio usando a API de outra loja que esteja no ar. Guarda em
 * cache quais lojas estão caídas agora — o painel (layouts/app.blade.php) lê isso e mostra um
 * aviso, o que cobre o caso das duas lojas caírem ao mesmo tempo (aí não tem como mandar
 * WhatsApp nenhum).
 */
#[Signature('whatsapp:waha:health-check')]
#[Description('Confere se as sessões do WAHA (CENTER, GENIUS) estão WORKING; tenta reconectar sozinho e alerta se continuar caído')]
class WahaHealthCheck extends Command
{
    private const DOWN_STORES_CACHE_KEY = 'waha_down_stores';

    private const RESTART_ATTEMPTED_TTL_MINUTES = 15;

    private const ALERTED_TTL_MINUTES = 30;

    public function handle(): int
    {
        $accounts = config('whatsapp.waha.accounts', []);

        $statuses = collect($accounts)->mapWithKeys(fn ($account, $store) => [
            $store => $this->sessionStatus($account),
        ]);

        $workingStores = $statuses->filter(fn ($status) => $status === 'WORKING')->keys();
        $downStores = $statuses->reject(fn ($status) => $status === 'WORKING')->keys();

        foreach ($workingStores as $store) {
            $this->components->info(strtoupper($store).' ok (WORKING).');
            Cache::forget("waha_restart_attempted:{$store}");
            Cache::forget("waha_alerted:{$store}");
        }

        foreach ($downStores as $store) {
            $status = $statuses[$store];
            $this->components->error(strtoupper($store)." com problema: status=\"{$status}\".");
            Log::channel('single')->critical("WAHA {$store}: sessão fora do ar (status={$status}).", [
                'store' => $store, 'status' => $status,
            ]);

            $this->handleDownStore($store, $accounts[$store], $workingStores);
        }

        // Pro painel avisar mesmo se não der pra mandar WhatsApp (ex.: as duas lojas caídas).
        Cache::forever(self::DOWN_STORES_CACHE_KEY, $downStores->values()->all());

        return $downStores->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{base_url?: string, api_key?: string, session?: string}  $account
     * @param  \Illuminate\Support\Collection<int, string>  $workingStores
     */
    private function handleDownStore(string $store, array $account, $workingStores): void
    {
        $restartKey = "waha_restart_attempted:{$store}";

        if (! Cache::has($restartKey)) {
            // Primeira vez que vemos essa loja caída — tenta reconectar sozinho antes de
            // incomodar alguém (vimos isso resolver sozinho várias vezes nessa conversa).
            $this->restartSession($account);
            Cache::put($restartKey, true, now()->addMinutes(self::RESTART_ATTEMPTED_TTL_MINUTES));
            $this->components->warn(strtoupper($store).': tentando reconectar sozinho.');

            return;
        }

        // Já tentou reconectar antes e continua caída — alerta de verdade, só uma vez por
        // episódio (não every 5 min enquanto não resolver).
        $alertedKey = "waha_alerted:{$store}";

        if (Cache::has($alertedKey)) {
            return;
        }

        $sentTo = $this->sendAlert($store, $workingStores);

        if ($sentTo !== null) {
            Cache::put($alertedKey, true, now()->addMinutes(self::ALERTED_TTL_MINUTES));
            $this->components->warn(strtoupper($store).": alerta mandado via {$sentTo}.");
        } else {
            $this->components->warn(strtoupper($store).': nenhuma loja no ar pra mandar o alerta — só log mesmo.');
        }
    }

    /** @param  \Illuminate\Support\Collection<int, string>  $workingStores */
    private function sendAlert(string $downStore, $workingStores): ?string
    {
        $senderStore = $workingStores->first();

        if ($senderStore === null) {
            return null;
        }

        $sender = config("whatsapp.waha.accounts.{$senderStore}");
        $phones = config('whatsapp.waha.alert_phones', []);

        if (! is_array($sender) || $phones === []) {
            return null;
        }

        $text = '⚠️ WAHA '.strtoupper($downStore).' caiu e não reconectou sozinho — precisa escanear o QR de novo.';
        $sentToAny = false;

        foreach ($phones as $phone) {
            try {
                $response = Http::withHeaders(array_filter(['X-Api-Key' => $sender['api_key'] ?? null]))
                    ->timeout(10)
                    ->post(rtrim((string) ($sender['base_url'] ?? ''), '/').'/api/sendText', [
                        'session' => $sender['session'] ?? '',
                        'chatId' => "{$phone}@c.us",
                        'text' => $text,
                    ]);

                $sentToAny = $sentToAny || $response->successful();
            } catch (Throwable $e) {
                Log::channel('single')->warning("WahaHealthCheck: falha ao mandar alerta pra {$phone} via {$senderStore}: {$e->getMessage()}");
            }
        }

        return $sentToAny ? $senderStore : null;
    }

    /** @param  array{base_url?: string, api_key?: string, session?: string}  $account */
    private function restartSession(array $account): void
    {
        $baseUrl = $account['base_url'] ?? null;
        $session = $account['session'] ?? null;

        if (! is_string($baseUrl) || ! is_string($session)) {
            return;
        }

        try {
            Http::withHeaders(array_filter(['X-Api-Key' => $account['api_key'] ?? null]))
                ->timeout(10)
                ->post(rtrim($baseUrl, '/')."/api/sessions/{$session}/restart");
        } catch (Throwable) {
            // Se nem o restart respondeu, a próxima checagem continua tentando normalmente.
        }
    }

    /**
     * @param  array{base_url?: string, api_key?: string, session?: string}  $account
     */
    private function sessionStatus(array $account): string
    {
        $baseUrl = $account['base_url'] ?? null;
        $apiKey = $account['api_key'] ?? null;
        $session = $account['session'] ?? null;

        if (! is_string($baseUrl) || ! is_string($session)) {
            return 'NAO_CONFIGURADO';
        }

        try {
            $response = Http::withHeaders(array_filter(['X-Api-Key' => $apiKey]))
                ->timeout(5)
                ->get(rtrim($baseUrl, '/')."/api/sessions/{$session}");

            if (! $response->successful()) {
                return 'HTTP_'.$response->status();
            }

            return (string) ($response->json('status') ?? 'DESCONHECIDO');
        } catch (Throwable $e) {
            return 'INACESSIVEL';
        }
    }
}
