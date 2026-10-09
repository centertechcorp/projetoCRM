<?php

namespace App\Services\Leads;

use App\Models\Lead;
use Carbon\CarbonImmutable;

/**
 * Tenta reconectar quem desistiu (Lead com status=lost), depois de um tempo parado —
 * mesmo que o cliente tenha dito explicitamente que não queria nada. A IA (quando existir)
 * só escreve o rascunho da primeira mensagem; uma pessoa sempre aprova e manda pelo painel.
 * Ao aprovar, App\Services\Leads\LeadDecisionService reabre o lead (volta para "aberto").
 */
class LostLeadReconnector
{
    public const REASON_LOST_RECOVERY = 'lost_recovery';

    public function __construct(private readonly FollowupGate $gate) {}

    /** @return array{leads_reconnected: int} */
    public function reconnect(?int $storeId = null): array
    {
        $config = config('whatsapp.followup');
        $notReconnectedSince = CarbonImmutable::now()->subDays($config['lost_reconnect_after_days']);

        $leads = Lead::query()
            ->where('status', 'lost')
            ->whereNotNull('lost_at')
            ->where('lost_at', '<=', $notReconnectedSince)
            // Chat marcado como ignorado (whatsapp:chats:ignore) não é cliente de verdade —
            // não insiste em reconectar com fornecedor, grupo interno ou número de teste.
            // Lead sem chat vinculado (ex.: import manual) passa normal, nada a filtrar.
            ->where(fn ($q) => $q->whereDoesntHave('chat')->orWhereHas('chat', fn ($c) => $c->where('ignored', false)))
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->get();

        $reconnected = 0;

        foreach ($leads as $lead) {
            $created = $this->gate->createIfAllowed(
                $lead,
                'recover',
                self::REASON_LOST_RECOVERY,
                $config['cooldown_days'],
                $config['max_attempts'],
            );

            $reconnected += $created ? 1 : 0;
        }

        return ['leads_reconnected' => $reconnected];
    }
}
