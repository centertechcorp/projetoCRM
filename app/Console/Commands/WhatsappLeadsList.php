<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:leads:list {--status=open : open, won, lost ou all}')]
#[Description('Lista os leads gerados a partir do WhatsApp')]
class WhatsappLeadsList extends Command
{
    public function handle(): int
    {
        $status = (string) $this->option('status');

        $leads = Lead::query()
            ->with(['customer', 'store', 'followups' => fn ($q) => $q->latest()->limit(1)])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('last_contact_at')
            ->get();

        if ($leads->isEmpty()) {
            $this->components->info('Nenhum lead encontrado.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Cliente', 'Telefone', 'Loja', 'Status', 'Último contato', 'Follow-up'],
            $leads->map(fn (Lead $lead) => [
                $lead->id,
                $lead->customer->name ?? '(sem nome)',
                $lead->customer->phone,
                $lead->store->code,
                $lead->status,
                $lead->last_contact_at?->format('d/m H:i'),
                $lead->followups->first()?->status ?? '—',
            ]),
        );

        return self::SUCCESS;
    }
}
