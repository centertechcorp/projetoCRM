<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('whatsapp:waha:health-check')]
#[Description('Confere se as sessões do WAHA (CENTER, GENIUS) estão WORKING; loga alerta se alguma caiu')]
class WahaHealthCheck extends Command
{
    public function handle(): int
    {
        $accounts = config('whatsapp.waha.accounts', []);
        $down = [];

        foreach ($accounts as $store => $account) {
            $status = $this->sessionStatus($account);

            if ($status === 'WORKING') {
                $this->components->info(strtoupper((string) $store)." ok (WORKING).");

                continue;
            }

            $down[] = $store;
            $this->components->error(strtoupper((string) $store)." com problema: status=\"{$status}\".");

            Log::channel('single')->critical("WAHA {$store}: sessão fora do ar (status={$status}). Precisa reconectar/escanear QR.", [
                'store' => $store,
                'status' => $status,
            ]);
        }

        return $down === [] ? self::SUCCESS : self::FAILURE;
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
