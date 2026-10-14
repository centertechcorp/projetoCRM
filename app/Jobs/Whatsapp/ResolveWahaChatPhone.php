<?php

namespace App\Jobs\Whatsapp;

use App\Models\WhatsappChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O WhatsApp vem migrando contatos individuais pra um identificador de privacidade
 * ("{id}@lid"), que não traz o telefone real em nenhum campo do webhook do WAHA
 * (App\Services\Whatsapp\MessageRecorder::chatFor() grava phone=null nesse caso, de propósito).
 * O WAHA ainda consegue resolver isso via GET /api/contacts — esse job busca o telefone real e
 * completa o WhatsappChat quando consegue. Se não conseguir agora, tenta de novo na próxima
 * mensagem desse contato (não é "falha permanente" como a mídia expirada).
 */
class ResolveWahaChatPhone implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private readonly int $whatsappChatId) {}

    public function handle(): void
    {
        $chat = WhatsappChat::find($this->whatsappChatId);

        if ($chat === null || $chat->phone !== null || $chat->kind !== 'individual') {
            return;
        }

        $config = $this->wahaConfigFor($chat);

        if ($config === null) {
            return;
        }

        [$apiKey, $baseUrl, $session] = $config;

        try {
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                ->timeout(10)
                ->get(rtrim($baseUrl, '/').'/api/contacts', [
                    'session' => $session,
                    // Tem que ser o jid inteiro (com "@lid"), não o chat_key — esse é só os
                    // dígitos (MessageStore::contactKey() tira o sufixo), e sem o "@lid" o WAHA
                    // não acha o contato e devolve os mesmos dígitos de volta como se fossem
                    // telefone (testei e confirmei isso ao vivo).
                    'contactId' => $chat->jid,
                ]);

            if (! $response->successful()) {
                Log::info("ResolveWahaChatPhone: WAHA não resolveu o chat {$chat->id} ainda (status {$response->status()}).");

                return;
            }

            $id = $response->json('id');

            if (! is_string($id) || preg_match('/^(\d+)(?::\d+)?@(c\.us|s\.whatsapp\.net)$/', $id, $m) !== 1) {
                Log::info("ResolveWahaChatPhone: resposta do WAHA sem telefone resolvido pro chat {$chat->id}.");

                return;
            }

            // Já vimos o WAHA devolver os mesmos dígitos do "@lid" formatados como se fossem
            // "@c.us" quando não consegue resolver de verdade — isso não é um telefone real.
            if ($m[1] === $chat->chat_key) {
                Log::info("ResolveWahaChatPhone: WAHA devolveu os mesmos dígitos do lid pro chat {$chat->id}, ignorando (não é telefone de verdade).");

                return;
            }

            $chat->update(['phone' => $m[1]]);
        } catch (Throwable $e) {
            Log::warning("ResolveWahaChatPhone: erro resolvendo o chat {$chat->id}: {$e->getMessage()}");
        }
    }

    /** @return array{0: string, 1: string, 2: string}|null [api_key, base_url, session] */
    private function wahaConfigFor(WhatsappChat $chat): ?array
    {
        $storeCode = $chat->account?->store?->code;

        if (! is_string($storeCode)) {
            return null;
        }

        $account = config('whatsapp.waha.accounts.'.strtolower($storeCode));
        $apiKey = $account['api_key'] ?? null;
        $baseUrl = $account['base_url'] ?? null;
        $session = $account['session'] ?? null;

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($baseUrl) || $baseUrl === '' || ! is_string($session) || $session === '') {
            return null;
        }

        return [$apiKey, $baseUrl, $session];
    }
}
