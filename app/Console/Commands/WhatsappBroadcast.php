<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Store;
use App\Models\WhatsappChat;
use App\Services\Whatsapp\PhoneNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Manda UMA mensagem de texto para os clientes que já conversaram com as lojas.
 *
 * Três modos, do mais seguro ao mais crítico:
 *  1. sem flag: SIMULAÇÃO. Mostra a mensagem exata, quantos números por loja e uma amostra
 *     mascarada. Não envia nada.
 *  2. --test-to=NUMERO (com a loja): manda só para esse número, para ver como chega.
 *  3. --send: envio real. Pede para digitar "ENVIAR <qtd>", espera uma pausa aleatória
 *     entre as mensagens (o WhatsApp bane rajadas de texto igual) e respeita --limit.
 *
 * Guarda quem já recebeu cada texto (storage/app/broadcasts/<hash>.sent), então rodar de novo
 * continua de onde parou e nunca repete a mensagem para o mesmo chat.
 */
#[Signature('whatsapp:broadcast
    {store? : Código da loja (CENTER ou GENIUS). Sem isso, as duas}
    {--audience=reconnect : Público: reconnect (leads da aba Reconectar) ou chats (todos os chats individuais ativos)}
    {--message= : Texto da mensagem}
    {--message-file= : Arquivo com o texto da mensagem}
    {--test-to= : Manda só para este número (com DDI), pela loja informada}
    {--send : Envio real (sem isso, só simula)}
    {--limit=100 : Máximo de mensagens enviadas nesta rodada}
    {--min-delay=20 : Pausa mínima entre mensagens (segundos)}
    {--max-delay=60 : Pausa máxima entre mensagens (segundos)}')]
#[Description('Simula, testa ou envia uma mensagem para os clientes das lojas (CENTER e GENIUS), com pausa entre envios')]
class WhatsappBroadcast extends Command
{
    private const MAX_CONSECUTIVE_FAILURES = 3;

    public function handle(): int
    {
        $message = $this->resolveMessage();

        if ($message === null) {
            return self::FAILURE;
        }

        $storeArg = $this->argument('store');
        $stores = $this->resolveStores(is_string($storeArg) ? $storeArg : null);

        if ($stores === null) {
            return self::FAILURE;
        }

        $testTo = $this->option('test-to');

        if (is_string($testTo) && $testTo !== '') {
            return $this->sendTest($message, $stores, $testTo);
        }

        $recipients = $this->recipients($stores);
        $alreadySent = $this->alreadySent($message);
        $noWhatsapp = $this->noWhatsappList();
        $pending = $recipients
            ->reject(fn (array $r) => in_array($r['chat_id'], $alreadySent, true) || in_array($r['phone'], $noWhatsapp, true))
            ->values();

        $this->showSummary($message, $recipients, $pending);

        if (! $this->option('send')) {
            $this->components->info('SIMULAÇÃO: nada foi enviado. Para testar, use --test-to=NUMERO; para enviar de verdade, --send.');

            return self::SUCCESS;
        }

        return $this->sendReal($message, $pending);
    }

    private function resolveMessage(): ?string
    {
        $file = $this->option('message-file');
        $text = is_string($file) && $file !== ''
            ? (is_file($file) ? (string) file_get_contents($file) : null)
            : $this->option('message');

        if (! is_string($text) || trim($text) === '') {
            $this->components->error('Informe o texto com --message="..." ou --message-file=caminho.txt.');

            return null;
        }

        return trim($text);
    }

    /** @return Collection<int, string>|null Códigos das lojas válidas, ou null em erro. */
    private function resolveStores(?string $code): ?Collection
    {
        $available = Store::query()->whereIn('code', ['CENTER', 'GENIUS'])->pluck('code');

        if ($code === null) {
            return $available;
        }

        $code = strtoupper($code);

        if (! $available->contains($code)) {
            $this->components->error('Loja inválida. Use CENTER ou GENIUS.');

            return null;
        }

        return collect([$code]);
    }

    /**
     * Chats individuais, não ignorados, com telefone, uma vez por telefone e loja.
     *
     * @param  Collection<int, string>  $stores
     * @return Collection<int, array{store: string, chat_id: string, phone: string}>
     */
    private function recipients(Collection $stores): Collection
    {
        if ($this->option('audience') !== 'chats') {
            return $this->reconnectRecipients($stores);
        }

        return WhatsappChat::query()
            ->with('account.store')
            ->where('kind', 'individual')
            ->where('ignored', false)
            ->whereNotNull('phone')
            ->whereHas('account.store', fn ($q) => $q->whereIn('code', $stores->all()))
            ->orderBy('id')
            ->get()
            ->map(function (WhatsappChat $chat) {
                $phone = PhoneNormalizer::normalize((string) $chat->phone);

                return $phone === null ? null : [
                    'store' => (string) $chat->account->store->code,
                    'chat_id' => $phone.'@c.us',
                    'phone' => $phone,
                ];
            })
            ->filter()
            ->unique(fn (array $r) => $r['store'].'|'.$r['phone'])
            ->values();
    }

    /**
     * Leads da aba Reconectar (mesma regra do Painel: Lead::reconnect), pela loja do lead.
     *
     * @param  Collection<int, string>  $stores
     * @return Collection<int, array{store: string, chat_id: string, phone: string}>
     */
    private function reconnectRecipients(Collection $stores): Collection
    {
        return Lead::query()
            ->reconnect()
            ->with(['customer', 'store'])
            ->whereHas('store', fn ($q) => $q->whereIn('code', $stores->all()))
            ->orderBy('id')
            ->get()
            ->map(function (Lead $lead) {
                $phone = PhoneNormalizer::normalize((string) $lead->customer?->phone);

                return $phone === null ? null : [
                    'store' => (string) $lead->store->code,
                    'chat_id' => $phone.'@c.us',
                    'phone' => $phone,
                ];
            })
            ->filter()
            ->unique(fn (array $r) => $r['store'].'|'.$r['phone'])
            ->values();
    }

    /**
     * @param  Collection<int, array{store: string, chat_id: string, phone: string}>  $recipients
     * @param  Collection<int, array{store: string, chat_id: string, phone: string}>  $pending
     */
    private function showSummary(string $message, Collection $recipients, Collection $pending): void
    {
        $this->newLine();
        $this->line('<options=bold>Mensagem que será enviada:</>');
        $this->line('────────────────────────────────');
        $this->line($message);
        $this->line('────────────────────────────────');
        $this->newLine();

        $this->components->twoColumnDetail('Total de números', (string) $recipients->count());
        foreach ($recipients->groupBy('store') as $store => $group) {
            $this->components->twoColumnDetail("  {$store}", (string) $group->count());
        }
        $this->components->twoColumnDetail('Já receberam ou sem WhatsApp (pulados)', (string) ($recipients->count() - $pending->count()));
        $this->components->twoColumnDetail('Pendentes', (string) $pending->count());

        $sample = $pending->take(5)->map(fn (array $r) => $r['store'].' '.self::mask($r['phone']))->implode(', ');
        $this->components->twoColumnDetail('Amostra (mascarada)', $sample === '' ? '—' : $sample);
        $this->newLine();
    }

    /** @param  Collection<int, string>  $stores */
    private function sendTest(string $message, Collection $stores, string $to): int
    {
        if ($stores->count() !== 1) {
            $this->components->error('Para o teste, informe a loja (CENTER ou GENIUS): a mensagem sai pelo número dela.');

            return self::FAILURE;
        }

        $phone = PhoneNormalizer::normalize($to);

        if ($phone === null) {
            $this->components->error('Número de teste inválido.');

            return self::FAILURE;
        }

        $store = (string) $stores->first();

        $resolved = $this->resolveChatId($store, $phone);

        if ($resolved['status'] === 'no_whatsapp') {
            $this->components->error('Esse número não está no WhatsApp.');

            return self::FAILURE;
        }

        if ($resolved['status'] !== 'ok' || ! $this->deliver($store, (string) $resolved['chat_id'], $message)) {
            $this->components->error("Falhou ao enviar o teste pela {$store}. Confira se a sessão está WORKING.");

            return self::FAILURE;
        }

        $this->components->info("Teste enviado para ".self::mask($phone)." pela {$store}. Nada foi gravado como 'enviado' para os clientes.");

        return self::SUCCESS;
    }

    /** @param  Collection<int, array{store: string, chat_id: string, phone: string}>  $pending */
    private function sendReal(string $message, Collection $pending): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $batch = $pending->take($limit)->values();

        if ($batch->isEmpty()) {
            $this->components->info('Ninguém pendente: todos já receberam este texto.');

            return self::SUCCESS;
        }

        $expected = 'ENVIAR '.$batch->count();
        $answer = $this->ask("Isto vai mandar mensagem a {$batch->count()} clientes de verdade. Digite \"{$expected}\" para confirmar");

        if (trim((string) $answer) !== $expected) {
            $this->components->warn('Cancelado: nada foi enviado.');

            return self::FAILURE;
        }

        $min = max(0, (int) $this->option('min-delay'));
        $max = max($min, (int) $this->option('max-delay'));
        $sent = 0;
        $failures = 0;

        $skipped = 0;

        foreach ($batch as $i => $recipient) {
            $label = sprintf('[%d/%d]', $i + 1, $batch->count());
            $who = $recipient['store'].' '.self::mask($recipient['phone']);
            $resolved = $this->resolveChatId($recipient['store'], $recipient['phone']);

            if ($resolved['status'] === 'no_whatsapp') {
                // Não é falha da sessão: o número só não está no WhatsApp. Anota para nunca mais tentar.
                $this->markNoWhatsapp($recipient['phone']);
                $skipped++;
                $this->line("{$label} sem WhatsApp, pulado {$who}");

                continue;
            }

            if ($resolved['status'] === 'ok' && $this->deliver($recipient['store'], (string) $resolved['chat_id'], $message)) {
                $this->markSent($message, $recipient['chat_id']);
                $sent++;
                $failures = 0;
                $this->line("{$label} enviado {$who}");
            } else {
                $failures++;
                $this->components->warn("{$label} falhou {$who}");

                if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->components->error('Parei: '.self::MAX_CONSECUTIVE_FAILURES.' falhas seguidas (sessão caída?). Rode de novo depois; quem já recebeu não repete.');

                    return self::FAILURE;
                }
            }

            if ($i < $batch->count() - 1 && $max > 0) {
                sleep(random_int($min, $max));
            }
        }

        $this->components->info("Concluído: {$sent} enviadas, {$skipped} sem WhatsApp (puladas). Faltam ".($pending->count() - $sent - $skipped).' pendentes.');

        return self::SUCCESS;
    }

    /**
     * Pergunta ao WAHA se o número está no WhatsApp e qual é o endereço dele (o WhatsApp Web
     * identifica as pessoas por um "LID"; enviar direto para o número falha com "No LID for user").
     *
     * @return array{status: 'ok'|'no_whatsapp'|'error', chat_id: ?string}
     */
    private function resolveChatId(string $store, string $phone): array
    {
        $account = config('whatsapp.waha.accounts.'.strtolower($store));

        if (! is_array($account) || empty($account['base_url'])) {
            return ['status' => 'error', 'chat_id' => null];
        }

        try {
            $response = Http::withHeaders(array_filter(['X-Api-Key' => $account['api_key'] ?? null]))
                ->timeout(20)
                ->get(rtrim((string) $account['base_url'], '/').'/api/contacts/check-exists', [
                    'phone' => $phone,
                    'session' => $account['session'] ?? '',
                ]);
        } catch (Throwable) {
            return ['status' => 'error', 'chat_id' => null];
        }

        if (! $response->successful() || ! is_bool($response->json('numberExists'))) {
            return ['status' => 'error', 'chat_id' => null];
        }

        if ($response->json('numberExists') === false) {
            return ['status' => 'no_whatsapp', 'chat_id' => null];
        }

        $chatId = $response->json('chatId');

        return is_string($chatId) && $chatId !== ''
            ? ['status' => 'ok', 'chat_id' => $chatId]
            : ['status' => 'error', 'chat_id' => null];
    }

    private function deliver(string $store, string $chatId, string $text): bool
    {
        $account = config('whatsapp.waha.accounts.'.strtolower($store));

        if (! is_array($account) || empty($account['base_url'])) {
            return false;
        }

        try {
            return Http::withHeaders(array_filter(['X-Api-Key' => $account['api_key'] ?? null]))
                ->timeout(20)
                ->post(rtrim((string) $account['base_url'], '/').'/api/sendText', [
                    'session' => $account['session'] ?? '',
                    'chatId' => $chatId,
                    'text' => $text,
                ])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function sentPath(string $message): string
    {
        return 'broadcasts/'.substr(hash('sha256', $message), 0, 16).'.sent';
    }

    /** @return list<string> */
    private function alreadySent(string $message): array
    {
        $path = $this->sentPath($message);

        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", Storage::disk('local')->get($path)))));
    }

    private function markSent(string $message, string $chatId): void
    {
        $disk = Storage::disk('local');
        $path = $this->sentPath($message);
        $disk->append($path, $chatId);
    }

    private const NO_WHATSAPP_PATH = 'broadcasts/no-whatsapp.list';

    /** @return list<string> Telefones que o WhatsApp disse não existir (valem para qualquer texto). */
    private function noWhatsappList(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::NO_WHATSAPP_PATH)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $disk->get(self::NO_WHATSAPP_PATH)))));
    }

    private function markNoWhatsapp(string $phone): void
    {
        Storage::disk('local')->append(self::NO_WHATSAPP_PATH, $phone);
    }

    private static function mask(string $phone): string
    {
        return strlen($phone) <= 6 ? $phone : substr($phone, 0, 4).str_repeat('*', strlen($phone) - 6).substr($phone, -2);
    }
}
