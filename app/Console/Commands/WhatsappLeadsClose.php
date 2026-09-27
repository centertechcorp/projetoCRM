<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\Leads\LeadDecisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:leads:close {id : ID do lead} {outcome : won ou lost} {--reason= : Motivo, quando outcome=lost}')]
#[Description('Marca um lead como vendido (won) ou perdido (lost)')]
class WhatsappLeadsClose extends Command
{
    public function handle(LeadDecisionService $decisions): int
    {
        $outcome = (string) $this->argument('outcome');

        if (! in_array($outcome, ['won', 'lost'], true)) {
            $this->components->error('outcome deve ser "won" ou "lost".');

            return self::FAILURE;
        }

        $lead = Lead::find($this->argument('id'));

        if ($lead === null) {
            $this->components->error('Lead não encontrado.');

            return self::FAILURE;
        }

        $outcome === 'won'
            ? $decisions->closeLeadWon($lead)
            : $decisions->closeLeadLost($lead, $this->option('reason'));

        $this->components->info("Lead #{$lead->id} marcado como {$outcome}.");

        return self::SUCCESS;
    }
}
