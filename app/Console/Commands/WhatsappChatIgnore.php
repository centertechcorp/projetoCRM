<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:chats:ignore {store : Código da loja (CENTER, GENIUS ou MIXCELL)} {--id= : ID do chat (sem isso, só lista)} {--state=on : on (ignora) ou off (volta a gravar)}')]
#[Description('Lista as conversas de uma loja e liga/desliga se uma delas (normalmente grupo) deve ser ignorada')]
class WhatsappChatIgnore extends Command
{
    public function handle(): int
    {
        $store = Store::query()->where('code', strtoupper((string) $this->argument('store')))->first();

        if ($store === null) {
            $this->components->error('Loja não encontrada. Códigos: '.Store::query()->pluck('code')->implode(', ').'.');

            return self::FAILURE;
        }

        $accountIds = WhatsappAccount::query()->where('store_id', $store->id)->pluck('id');

        $id = $this->option('id');

        if ($id === null) {
            $chats = WhatsappChat::query()
                ->whereIn('whatsapp_account_id', $accountIds)
                ->orderByDesc('last_message_at')
                ->get();

            if ($chats->isEmpty()) {
                $this->components->info('Nenhuma conversa encontrada ainda para essa loja.');

                return self::SUCCESS;
            }

            $this->table(
                ['ID', 'Tipo', 'Nome/telefone', 'Última mensagem', 'Ignorado?'],
                $chats->map(fn (WhatsappChat $chat) => [
                    $chat->id,
                    $chat->kind,
                    $chat->display_name ?? $chat->phone ?? '(sem nome)',
                    $chat->last_message_at?->format('d/m H:i') ?? '—',
                    $chat->ignored ? 'sim' : 'não',
                ]),
            );

            return self::SUCCESS;
        }

        $state = (string) $this->option('state');

        if (! in_array($state, ['on', 'off'], true)) {
            $this->components->error('--state deve ser "on" ou "off".');

            return self::FAILURE;
        }

        $chat = WhatsappChat::query()->whereIn('whatsapp_account_id', $accountIds)->find((int) $id);

        if ($chat === null) {
            $this->components->error("Nenhuma conversa com id={$id} entre as contas de {$store->code}.");

            return self::FAILURE;
        }

        $chat->update(['ignored' => $state === 'on']);

        $name = $chat->display_name ?? $chat->phone ?? "id {$chat->id}";
        $this->components->info($state === 'on' ? "\"{$name}\" agora está ignorado." : "\"{$name}\" volta a ser gravado normalmente.");

        return self::SUCCESS;
    }
}
