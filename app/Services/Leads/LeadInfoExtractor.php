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
     * Filtro "Celular x Peças" do painel. Junta toda a janela de mensagens num texto só (ao
     * contrário de productInterest(), que para na primeira mensagem que bater) — "apareceu em
     * qualquer ponto da conversa" precisa olhar tudo de uma vez, e celular ganha de peça
     * quando os dois aparecem.
     *
     * @param  Collection<int, \App\Models\WhatsappMessage>  $customerMessages  vem mais recente primeiro
     */
    public function productCategory(Collection $customerMessages): ?string
    {
        // Reordena do mais antigo pro mais novo antes de juntar — o padrão "peça DE marca"
        // (ver mais abaixo) depende da ordem natural de leitura. Se a frase for partida em
        // duas mensagens (ex.: "queria uma capinha" / "pro meu iphone"), juntar na ordem
        // "mais recente primeiro" inverteria peça e marca e o padrão não seria reconhecido.
        $normalized = self::normalize($customerMessages->sortBy('sent_at')->pluck('body')->implode(' '));

        $phoneKeywords = config('whatsapp.group_topics.phone_keywords', []);
        $accessoryKeywords = array_diff(config('whatsapp.group_topics.keywords', []), $phoneKeywords);

        // "capinha DE iphone", "carregador DO galaxy", "fonte PRA xiaomi" — uma peça de
        // verdade ligada à marca por uma preposição é pedido de peça PRA aquele aparelho, não
        // compra do aparelho em si. Só conta como esse padrão se tiver uma peça/acessório de
        // verdade antes da preposição — "preço DO iphone", "garantia DO iphone" não têm peça
        // nenhuma antes, então não caem aqui (a marca é detectada normalmente mais abaixo).
        foreach ($accessoryKeywords as $accessoryKeyword) {
            $normalizedAccessory = self::normalize($accessoryKeyword);

            if (! str_contains($normalized, $normalizedAccessory)) {
                continue;
            }

            foreach ($phoneKeywords as $phoneKeyword) {
                $normalizedPhone = self::normalize($phoneKeyword);

                if (! str_contains($normalized, $normalizedPhone)) {
                    continue;
                }

                $pattern = '/\b'.preg_quote($normalizedAccessory, '/').'\b.{0,15}?\b(?:de|do|da|pra|pro|para)\s+(?:\w+\s+){0,2}'.preg_quote($normalizedPhone, '/').'\b/';

                if (preg_match($pattern, $normalized) === 1) {
                    return 'peca';
                }
            }
        }

        foreach ($phoneKeywords as $keyword) {
            if (str_contains($normalized, self::normalize($keyword))) {
                return 'celular';
            }
        }

        foreach (config('whatsapp.group_topics.keywords', []) as $keyword) {
            if (str_contains($normalized, self::normalize($keyword))) {
                return 'peca';
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
