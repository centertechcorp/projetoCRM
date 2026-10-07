<?php

namespace App\Services\Leads;

use App\Models\Lead;

/**
 * Pontua (0-100) quão crucial é reabordar um lead agora, olhando as últimas mensagens do
 * cliente naquele chat — sem IA, só regras (mesmo estilo de
 * App\Services\Whatsapp\ProductTopicMatcher). Quanto mais perto de fechar o cliente parecer
 * (pergunta de pagamento/entrega, orçamento já dado, produto específico, engajamento), maior
 * o score.
 */
class LeadPriorityScorer
{
    private const CLOSING_KEYWORDS = [
        'parcela', 'parcelamento', 'boleto', 'cartao', 'cartão', 'desconto',
        'a vista', 'à vista', 'entrega', 'garantia', 'disponivel', 'disponível',
        'troca', 'quanto', 'preco', 'preço', 'valor',
    ];

    private const MAX_MESSAGES_CONSIDERED = 10;

    public function score(Lead $lead): int
    {
        $messages = $lead->chat
            ?->messages()
            ->where('direction', 'in')
            ->orderByDesc('sent_at')
            ->limit(self::MAX_MESSAGES_CONSIDERED)
            ->get() ?? collect();

        $body = self::normalize($messages->pluck('body')->implode(' '));

        $score = 0;
        $score += $lead->quoted_amount !== null ? 30 : 0;
        $score += $this->hasClosingQuestion($body) ? 25 : 0;
        $score += $this->mentionsProduct($lead, $body) ? 15 : 0;
        $score += min($messages->count() * 3, 15);
        $score += min(substr_count($body, '?') * 5, 15);

        return min($score, 100);
    }

    public function label(int $score): string
    {
        return match (true) {
            $score >= 50 => 'Alta',
            $score >= 25 => 'Média',
            default => 'Baixa',
        };
    }

    public function reason(Lead $lead): ?string
    {
        $messages = $lead->chat
            ?->messages()
            ->where('direction', 'in')
            ->orderByDesc('sent_at')
            ->limit(self::MAX_MESSAGES_CONSIDERED)
            ->get() ?? collect();

        $body = self::normalize($messages->pluck('body')->implode(' '));

        if ($lead->quoted_amount !== null) {
            return 'Já recebeu orçamento';
        }

        if ($this->hasClosingQuestion($body)) {
            return 'Perguntou sobre fechamento (preço, parcelamento, entrega...)';
        }

        if ($this->mentionsProduct($lead, $body)) {
            return 'Citou produto específico';
        }

        if (substr_count($body, '?') > 0) {
            return 'Fez pergunta direta';
        }

        return null;
    }

    private function hasClosingQuestion(string $normalizedBody): bool
    {
        foreach (self::CLOSING_KEYWORDS as $keyword) {
            if (str_contains($normalizedBody, self::normalize($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function mentionsProduct(Lead $lead, string $normalizedBody): bool
    {
        if ($lead->product_interest !== null && $lead->product_interest !== '') {
            return true;
        }

        foreach (config('whatsapp.group_topics.keywords', []) as $keyword) {
            if (str_contains($normalizedBody, self::normalize($keyword))) {
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

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
