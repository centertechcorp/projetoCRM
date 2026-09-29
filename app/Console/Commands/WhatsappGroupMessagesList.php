<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappGroupMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:groups:list {store : Código da loja (CENTER, GENIUS ou MIXCELL)} {--limit=20 : Quantas mensagens mostrar}')]
#[Description('Lista as últimas mensagens de grupo sobre eletrônico gravadas para uma loja (roupa, papo banal e catálogo de preço já ficam de fora)')]
class WhatsappGroupMessagesList extends Command
{
    public function handle(): int
    {
        $store = Store::query()->where('code', strtoupper((string) $this->argument('store')))->first();

        if ($store === null) {
            $this->components->error('Loja não encontrada. Códigos: '.Store::query()->pluck('code')->implode(', ').'.');

            return self::FAILURE;
        }

        $accountIds = WhatsappAccount::query()->where('store_id', $store->id)->pluck('id');

        $messages = WhatsappGroupMessage::query()
            ->whereIn('whatsapp_account_id', $accountIds)
            ->orderByDesc('sent_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($messages->isEmpty()) {
            $this->components->info('Nenhuma mensagem de grupo sobre eletrônico gravada ainda.');

            return self::SUCCESS;
        }

        $this->table(
            ['Data', 'Grupo', 'De', 'Mensagem'],
            $messages->map(fn (WhatsappGroupMessage $message) => [
                $message->sent_at->format('d/m H:i'),
                $message->group_name ?? '(sem nome)',
                $message->sender_name ?? '—',
                str($message->body)->limit(60),
            ]),
        );

        return self::SUCCESS;
    }
}
