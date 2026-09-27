<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:leads:archive')]
#[Description('Marca como arquivados os leads vendidos que já passaram do prazo de retenção (nada é apagado)')]
class WhatsappLeadsArchive extends Command
{
    public function handle(): int
    {
        $count = Lead::query()
            ->where('status', 'won')
            ->whereNull('archived_at')
            ->where('archive_after', '<=', CarbonImmutable::now())
            ->update(['archived_at' => CarbonImmutable::now()]);

        $this->components->info("{$count} lead(s) arquivado(s).");

        return self::SUCCESS;
    }
}
