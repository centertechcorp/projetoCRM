<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Leads\LostLeadReconnector;
use App\Services\Whatsapp\StalledConversationDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:leads:detect {--store= : Código da loja (padrão: todas)}')]
#[Description('Procura conversas de WhatsApp paradas e leads perdidos prontos para reconectar')]
class WhatsappLeadsDetect extends Command
{
    public function handle(StalledConversationDetector $detector, LostLeadReconnector $reconnector): int
    {
        $storeId = null;

        if ($this->option('store')) {
            $store = Store::query()->where('code', strtoupper((string) $this->option('store')))->first();

            if ($store === null) {
                $this->components->error('Loja não encontrada. Códigos: '.Store::query()->pluck('code')->implode(', ').'.');

                return self::FAILURE;
            }

            $storeId = $store->id;
        }

        $result = $detector->detect($storeId);
        $reconnection = $reconnector->reconnect($storeId);

        $this->components->info(sprintf(
            '%d lead(s) novo(s), %d sugestão(ões) de reabordagem criada(s), %d conversa(s) sem novidade, '
            .'%d lead(s) perdido(s) com reconexão sugerida.',
            $result['leads_created'],
            $result['followups_created'],
            $result['chats_skipped'],
            $reconnection['leads_reconnected'],
        ));

        return self::SUCCESS;
    }
}
