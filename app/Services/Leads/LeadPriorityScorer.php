<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\WhatsappMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Carrega, numa consulta só, as últimas mensagens recebidas de todos os chats desses leads e
     * as deixa penduradas em cada Lead (relação "recentInbound"). Sem isso, score() e reason()
     * fazem uma consulta por lead — com centenas de leads na aba, a página demora segundos.
     * Quem não chamar preload() continua funcionando, só mais devagar (uma consulta por lead).
     *
     * @param  Collection<int, Lead>  $leads
     */
    public function preload(Collection $leads): void
    {
        $chatIds = $leads->pluck('whatsapp_chat_id')->filter()->unique()->values();

        $byChat = collect();

        if ($chatIds->isNotEmpty()) {
            $ranked = WhatsappMessage::query()
                ->select(['id', 'whatsapp_chat_id', 'type', 'body', 'sent_at'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY whatsapp_chat_id ORDER BY sent_at DESC, id DESC) as rn')
                ->where('direction', 'in')
                ->whereIn('whatsapp_chat_id', $chatIds);

            $byChat = WhatsappMessage::query()
                ->fromSub($ranked, 'whatsapp_messages')
                ->where('rn', '<=', self::MAX_MESSAGES_CONSIDERED)
                ->orderBy('whatsapp_chat_id')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('whatsapp_chat_id');
        }

        foreach ($leads as $lead) {
            $lead->setRelation('recentInbound', $byChat->get($lead->whatsapp_chat_id, collect()));
        }
    }

    /** @return Collection<int, WhatsappMessage> */
    private function recentInbound(Lead $lead): Collection
    {
        if ($lead->relationLoaded('recentInbound')) {
            return $lead->getRelation('recentInbound');
        }

        return $lead->chat
            ?->messages()
            ->where('direction', 'in')
            ->orderByDesc('sent_at')
            ->limit(self::MAX_MESSAGES_CONSIDERED)
            ->get() ?? collect();
    }

    public function score(Lead $lead): int
    {
        $messages = $this->recentInbound($lead);

        $body = self::normalize($messages->pluck('body')->implode(' '));

        $score = 0;
        $score += $lead->quoted_amount !== null ? 30 : 0;
        $score += $this->hasClosingQuestion($body) ? 25 : 0;
        $score += $this->mentionsProduct($lead, $body) ? 15 : 0;
        $score += min($messages->count() * 3, 15);
        $score += min(substr_count($body, '?') * 5, 15);

        return min($score, 100);
    }

    /**
     * Mesma conta do score(), mas sem precisar de um Lead — usado pra ranquear conversa do
     * Relatório do dia (App\Http\Controllers\Painel\PainelReportController), que nem sempre
     * já virou lead. Sem o bônus de orçamento/produto do cadastro (não existe nesse contexto).
     *
     * @param  Collection<int, \App\Models\WhatsappMessage>  $customerMessages
     */
    public function scoreMessages(Collection $customerMessages): int
    {
        $body = self::normalize($customerMessages->pluck('body')->implode(' '));

        $score = 0;
        $score += $this->hasClosingQuestion($body) ? 25 : 0;
        $score += $this->mentionsProductText($body) ? 15 : 0;
        $score += min($customerMessages->count() * 3, 15);
        $score += min(substr_count($body, '?') * 5, 15);

        return min($score, 100);
    }

    /** @param  Collection<int, \App\Models\WhatsappMessage>  $customerMessages */
    public function reasonForMessages(Collection $customerMessages): ?string
    {
        $body = self::normalize($customerMessages->pluck('body')->implode(' '));

        if ($this->hasClosingQuestion($body)) {
            return 'Perguntou sobre fechamento (preço, parcelamento, entrega...)';
        }

        if ($this->mentionsProductText($body)) {
            return 'Citou produto específico';
        }

        if (substr_count($body, '?') > 0) {
            return 'Fez pergunta direta';
        }

        return null;
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
        $messages = $this->recentInbound($lead);

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

        return $this->mentionsProductText($normalizedBody);
    }

    private function mentionsProductText(string $normalizedBody): bool
    {
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
