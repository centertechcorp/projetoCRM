<?php

namespace App\Services\Whatsapp;

/**
 * Distingue uma mensagem de encerramento ("obrigado", "valeu") de uma conversa que
 * ainda precisa de resposta, para o StalledConversationDetector não sugerir reabordagem
 * de quem só se despediu.
 */
class ClosingPhraseMatcher
{
    /** @param  array<int, string>  $phrases */
    public static function isClosing(string $body, array $phrases): bool
    {
        $normalized = self::normalize($body);

        if ($normalized === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            if ($normalized === self::normalize($phrase)) {
                return true;
            }
        }

        return false;
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

        // Remove só pontuação (., !, ? etc.) — emoji como 👍 não é pontuação e sobrevive.
        $value = preg_replace('/\p{P}+/u', '', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
