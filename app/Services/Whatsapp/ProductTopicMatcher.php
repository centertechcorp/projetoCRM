<?php

namespace App\Services\Whatsapp;

/**
 * Distingue mensagem de grupo sobre eletrônico/celular de papo banal (roupa etc.) e de
 * catálogo de preço (várias linhas "R$" — propaganda, não pergunta de cliente), para
 * MessageRecorder decidir o que vale a pena guardar em whatsapp_group_messages.
 */
class ProductTopicMatcher
{
    /** @param  array<int, string>  $keywords */
    public static function isRelevant(string $body, array $keywords, int $maxPriceMentions): bool
    {
        if (self::looksLikePriceCatalog($body, $maxPriceMentions)) {
            return false;
        }

        $normalized = self::normalize($body);

        if ($normalized === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, self::normalize($keyword))) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikePriceCatalog(string $body, int $maxPriceMentions): bool
    {
        return preg_match_all('/r\$\s*\d/i', $body) > $maxPriceMentions;
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
