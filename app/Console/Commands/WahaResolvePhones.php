<?php

namespace App\Console\Commands;

use App\Jobs\Whatsapp\ResolveWahaChatPhone;
use App\Models\WhatsappChat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Backfill pros contatos "@lid" que ficaram sem telefone antes de
 * App\Services\Whatsapp\MessageRecorder::chatFor() passar a disparar
 * App\Jobs\Whatsapp\ResolveWahaChatPhone sozinho a cada mensagem nova. Rodar uma vez só
 * (manual); contatos novos a partir de agora já se resolvem sozinhos.
 */
#[Signature('whatsapp:waha:resolve-phones')]
#[Description('Tenta resolver o telefone real dos contatos "@lid" que já ficaram gravados sem telefone')]
class WahaResolvePhones extends Command
{
    public function handle(): int
    {
        $chats = WhatsappChat::query()
            ->where('kind', 'individual')
            ->whereNull('phone')
            ->where('jid', 'like', '%@lid')
            ->get();

        foreach ($chats as $chat) {
            ResolveWahaChatPhone::dispatch($chat->id);
        }

        $this->components->info("{$chats->count()} contato(s) na fila pra resolver o telefone.");

        return self::SUCCESS;
    }
}
