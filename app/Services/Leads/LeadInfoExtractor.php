<?php

namespace App\Services\Leads;

use Illuminate\Support\Collection;

/**
 * Preenche Lead::product_interest/quoted_amount por regra (sem IA), lendo as mensagens da
 * conversa — mesmo estilo de App\Services\Leads\LeadPriorityScorer e
 * App\Services\Whatsapp\ProductTopicMatcher. Limitação honesta: product_interest sai como
 * categoria genérica (ex. "iphone"), não o produto exato com modelo — isso precisaria de IA
 * de verdade pra extrair.
 */
class LeadInfoExtractor
{
    /**
     * @param  Collection<int, \App\Models\WhatsappMessage>  $customerMessages  mais recentes primeiro
     */
    public function productInterest(Collection $customerMessages): ?string
    {
        foreach ($customerMessages as $message) {
            $normalized = self::normalize($message->body);

            foreach (config('whatsapp.group_topics.keywords', []) as $keyword) {
                if (str_contains($normalized, self::normalize($keyword))) {
                    return $keyword;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, \App\Models\WhatsappMessage>  $sellerMessages  mais recentes primeiro
     */
    public function quotedAmount(Collection $sellerMessages): ?float
    {
        foreach ($sellerMessages as $message) {
            if (preg_match('/r\$\s*([\d.,]+)/i', $message->body, $m) === 1) {
                $amount = self::parseAmount($m[1]);

                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        return null;
    }

    private static function parseAmount(string $raw): ?float
    {
        $raw = rtrim($raw, '.,');

        if ($raw === '') {
            return null;
        }

        // Vírgula é decimal em formato BR; ponto (se tiver, sem vírgula) é separador de
        // milhar — ambíguo pra algo tipo "1.500" (sem vírgula), mas é o caso mais comum pra
        // preço de loja (R$ 1.500 = mil e quinhentos, não 1,5).
        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace('.', '', $raw);
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
