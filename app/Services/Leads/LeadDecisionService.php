<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Único lugar que muda o status de um lead ou de uma sugestão de reabordagem.
 * Usado tanto pelos comandos artisan quanto pelo painel, para as duas portas
 * fazerem exatamente a mesma coisa. Nunca envia mensagem nenhuma.
 */
class LeadDecisionService
{
    public function approveFollowup(LeadFollowup $followup, User $user): void
    {
        $followup->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => CarbonImmutable::now(),
        ]);

        // Aprovar a reconexão de quem desistiu já reabre o lead: sai da aba "Desistiu" e
        // volta a valer como "aberto" — a partir daqui é o funcionário quem conversa.
        $lead = $followup->lead;

        if ($followup->reason === LostLeadReconnector::REASON_LOST_RECOVERY && $lead->status === 'lost') {
            $lead->update(['status' => 'open', 'lost_at' => null, 'lost_reason' => null]);
        }
    }

    public function dismissFollowup(LeadFollowup $followup): void
    {
        $followup->update(['status' => 'dismissed', 'dismissed_at' => CarbonImmutable::now()]);
    }

    public function unapproveFollowup(LeadFollowup $followup): void
    {
        $followup->update([
            'status' => $followup->suggested_message ? 'draft_ready' : 'candidate',
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function closeLeadWon(Lead $lead): void
    {
        $now = CarbonImmutable::now();
        $retentionDays = (int) config('whatsapp.followup.retention_days');

        $lead->update(['status' => 'won', 'won_at' => $now, 'archive_after' => $now->addDays($retentionDays)]);
    }

    public function closeLeadLost(Lead $lead, ?string $reason): void
    {
        $lead->update(['status' => 'lost', 'lost_at' => CarbonImmutable::now(), 'lost_reason' => $reason]);
    }

    /**
     * Botão "Deletar" do painel: mesmo efeito do comando whatsapp:chats:ignore (o chat nunca
     * mais vira lead novo nem reconexão de perdido) + soft-delete do lead, que já some de
     * qualquer aba sozinho (nenhuma query do painel inclui soft-deleted).
     */
    public function deletePermanently(Lead $lead): void
    {
        $lead->chat?->update(['ignored' => true]);
        $lead->delete();
    }
}
