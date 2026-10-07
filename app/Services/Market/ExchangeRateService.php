<?php

namespace App\Services\Market;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cotação do dólar pro card do painel. Usa a AwesomeAPI (gratuita, sem chave,
 * https://docs.awesomeapi.com.br/api-de-moedas) e guarda em cache — não é informação que
 * precisa ser por segundo, e evita derrubar o painel se a API cair.
 */
class ExchangeRateService
{
    private const CACHE_KEY = 'market.usd_brl';

    private const CACHE_MINUTES = 30;

    /** @return array{bid: float, pct_change: float, updated_at: CarbonImmutable}|null */
    public function usdToBrl(): ?array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), function () {
            try {
                $response = Http::timeout(3)->get('https://economia.awesomeapi.com.br/last/USD-BRL');

                if (! $response->ok()) {
                    return null;
                }

                $data = $response->json('USDBRL');

                if (! is_array($data) || ! isset($data['bid'], $data['pctChange'])) {
                    return null;
                }

                return [
                    'bid' => (float) $data['bid'],
                    'pct_change' => (float) $data['pctChange'],
                    'updated_at' => CarbonImmutable::now(),
                ];
            } catch (Throwable $e) {
                Log::warning('Não consegui buscar a cotação do dólar: '.$e->getMessage());

                return null;
            }
        });
    }
}
