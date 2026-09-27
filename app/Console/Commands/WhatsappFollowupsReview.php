<?php

namespace App\Console\Commands;

use App\Models\LeadFollowup;
use App\Models\User;
use App\Services\Leads\LeadDecisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:followups:review {id : ID da sugestão} {decision : approve ou dismiss} {--user= : ID do usuário que decidiu}')]
#[Description('Aprova ou descarta uma sugestão de reabordagem (nada é enviado; só marca a decisão)')]
class WhatsappFollowupsReview extends Command
{
    public function handle(LeadDecisionService $decisions): int
    {
        $decision = (string) $this->argument('decision');

        if (! in_array($decision, ['approve', 'dismiss'], true)) {
            $this->components->error('decision deve ser "approve" ou "dismiss".');

            return self::FAILURE;
        }

        $followup = LeadFollowup::find($this->argument('id'));

        if ($followup === null) {
            $this->components->error('Sugestão não encontrada.');

            return self::FAILURE;
        }

        $userId = $this->option('user');
        $user = $userId !== null ? User::find($userId) : null;

        if ($userId !== null && $user === null) {
            $this->components->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        if ($decision === 'approve' && $user === null) {
            $this->components->error('--user é obrigatório para aprovar (registra quem autorizou).');

            return self::FAILURE;
        }

        $decision === 'approve'
            ? $decisions->approveFollowup($followup, $user)
            : $decisions->dismissFollowup($followup);

        $this->components->info("Sugestão #{$followup->id} marcada como {$followup->fresh()->status}.");

        return self::SUCCESS;
    }
}
