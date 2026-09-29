<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadFollowup;
use Carbon\CarbonImmutable;

/**
 * Decide se um lead pode ganhar uma nova sugestão de reabordagem (LeadFollowup) agora,
 * e cria — mesma regra para quem sumiu, quem só respondeu e sumiu de novo, ou quem
 * desistiu: não repete enquanto já tiver uma sugestão em aberto, respeita o cooldown
 * entre tentativas e para de vez depois do teto de tentativas.
 */
class FollowupGate
{
    /** Status de uma sugestão ainda não decidida. */
    public const OPEN_STATUSES = ['candidate', 'draft_ready', 'approved'];

    /** Status de uma sugestão já encerrada (conta para o cooldown). */
    public const CLOSED_STATUSES = ['dismissed', 'sent'];

    public function createIfAllowed(Lead $lead, string $type, string $reason, int $cooldownDays, int $maxAttempts): bool
    {
        $attempts = $lead->followups()->where('type', $type)->count();

        if ($attempts >= $maxAttempts) {
            return false;
        }

        $openExists = $lead->followups()->whereIn('status', self::OPEN_STATUSES)->exists();

        if ($openExists) {
            return false;
        }

        $inCooldown = $lead->followups()
            ->whereIn('status', self::CLOSED_STATUSES)
            ->where('updated_at', '>=', CarbonImmutable::now()->subDays($cooldownDays))
            ->exists();

        if ($inCooldown) {
            return false;
        }

        LeadFollowup::create([
            'lead_id' => $lead->id,
            'type' => $type,
            'reason' => $reason,
            'status' => 'candidate',
            'suggested_by' => 'rules',
        ]);

        return true;
    }
}
