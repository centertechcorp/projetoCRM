<?php

namespace App\Services\Whatsapp;

use App\Jobs\Whatsapp\DownloadWahaMedia;
use App\Models\Event;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappGroupMessage;
use App\Models\WhatsappMedia;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Única porta de escrita das mensagens no banco. Quem recebe as mensagens (o daemon da
 * extensão hoje; um webhook do Evolution, WAHA ou da API oficial depois) converte o que
 * recebeu em IncomingMessage e chama record(), então trocar de provedor não muda as tabelas.
 */
class MessageRecorder
{
    public const STORED = MessageStore::STORED;

    public const DUPLICATE = MessageStore::DUPLICATE;

    public const IGNORED = MessageStore::IGNORED;

    public const EVENT_MESSAGE = 'whatsapp.message.received';

    public const EVENT_MEDIA = 'whatsapp.media.stored';

    public const EVENT_MEDIA_PENDING = 'whatsapp.media.pending';

    /** Conta ativa dona do token, ou null. O token nunca é guardado, só o hash. */
    public function authenticate(?string $token): ?WhatsappAccount
    {
        if ($token === null || $token === '') {
            return null;
        }

        return WhatsappAccount::query()
            ->where('token_hash', WhatsappAccount::hashToken($token))
            ->where('active', true)
            ->first();
    }

    /**
     * @param  ?array<string, mixed>  $payload  o que o provedor enviou, guardado sem alterações
     */
    public function record(WhatsappAccount $account, IncomingMessage $message, string $source, ?array $payload = null): string
    {
        $chatKey = MessageStore::contactKey($message->chat);

        if ($chatKey === null) {
            return self::IGNORED;
        }

        if (str_ends_with($message->chat, '@g.us')) {
            return $this->recordGroupMessage($account, $message, $chatKey);
        }

        $exists = WhatsappMessage::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('external_id', $message->id)
            ->exists();

        if ($exists) {
            return self::DUPLICATE;
        }

        $result = self::STORED;

        DB::transaction(function () use ($account, $message, $source, $payload, $chatKey, &$result): void {
            $sentAt = CarbonImmutable::createFromTimestamp($message->timestamp);
            $chat = $this->chatFor($account, $message, $chatKey, $sentAt);

            // Mesma regra do grupo: chat marcado como ignorado (whatsapp:chats:ignore) não
            // grava mensagem nova de conversa individual também — antes só valia pra grupo.
            if ($chat->ignored) {
                $result = self::IGNORED;

                return;
            }

            $stored = WhatsappMessage::create([
                'whatsapp_account_id' => $account->id,
                'whatsapp_chat_id' => $chat->id,
                'external_id' => $message->id,
                'direction' => $message->fromMe ? 'out' : 'in',
                'sender_name' => $this->nullable($message->senderName),
                'sender_jid' => $message->senderJid,
                'sent_via' => $message->sentVia ?? ($message->fromMe ? 'human' : null),
                'quoted_external_id' => $message->quotedExternalId,
                'type' => $message->type,
                'body' => $this->clean($message->body),
                'sent_at' => $sentAt,
                'source' => $source,
                'payload' => $payload === null ? null : $this->cleanPayload($payload),
            ]);

            $this->emit(self::EVENT_MESSAGE, $account, 'whatsapp_message', $stored->id, $sentAt, [
                'chat_id' => $chat->id,
                'type' => $message->type,
                'direction' => $stored->direction,
            ]);

            if ($message->media !== null) {
                $this->recordMedia($account, $stored, $message->media, $sentAt);
            }
        });

        return $result;
    }

    /**
     * Grupo não é conversa de cliente individual: grupo na denylist (config/whatsapp.php)
     * é ignorado por completo assim que aparece; os demais só são gravados em
     * whatsapp_group_messages se ProductTopicMatcher achar que falam de eletrônico.
     */
    private function recordGroupMessage(WhatsappAccount $account, IncomingMessage $message, string $chatKey): string
    {
        $sentAt = CarbonImmutable::createFromTimestamp($message->timestamp);
        $result = self::IGNORED;

        DB::transaction(function () use ($account, $message, $chatKey, $sentAt, &$result): void {
            $chat = $this->chatFor($account, $message, $chatKey, $sentAt);

            if ($chat->ignored) {
                return;
            }

            $exists = WhatsappGroupMessage::query()
                ->where('whatsapp_account_id', $account->id)
                ->where('external_id', $message->id)
                ->exists();

            if ($exists) {
                $result = self::DUPLICATE;

                return;
            }

            $keywords = config('whatsapp.group_topics.keywords', []);
            $maxPriceMentions = (int) config('whatsapp.group_topics.max_price_mentions', 1);

            if (! ProductTopicMatcher::isRelevant($message->body, $keywords, $maxPriceMentions)) {
                return;
            }

            WhatsappGroupMessage::create([
                'whatsapp_account_id' => $account->id,
                'external_id' => $message->id,
                'group_jid' => $message->chat,
                'group_name' => $chat->display_name,
                'sender_jid' => $message->senderJid,
                'sender_name' => $this->nullable($message->senderName),
                'body' => $this->clean($message->body),
                'sent_at' => $sentAt,
            ]);

            $result = self::STORED;
        });

        return $result;
    }

    private function chatFor(WhatsappAccount $account, IncomingMessage $message, string $chatKey, CarbonImmutable $sentAt): WhatsappChat
    {
        $isGroup = str_ends_with($message->chat, '@g.us');
        $groupName = $isGroup ? $this->nullable($message->groupName ?? '') : null;

        $chat = WhatsappChat::firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'chat_key' => $chatKey],
            [
                'jid' => $message->chat,
                'kind' => $isGroup ? 'group' : 'individual',
                // Só JIDs de telefone trazem o número; "@lid" é um identificador de privacidade.
                'phone' => ! $isGroup && preg_match('/^(\d+)(?::\d+)?@(c\.us|s\.whatsapp\.net)$/', $message->chat, $m) === 1 ? $m[1] : null,
                'display_name' => $groupName,
                'ignored' => $isGroup && $this->isDenylistedGroup($groupName),
            ],
        );

        $changes = [];

        if ($isGroup) {
            if ($groupName !== null && $chat->display_name !== $groupName) {
                $changes['display_name'] = $groupName;
            }

            // Cobre o caso do nome do grupo só ficar disponível numa mensagem seguinte.
            if (! $chat->ignored && $this->isDenylistedGroup($groupName)) {
                $changes['ignored'] = true;
            }
        } else {
            // O nome do contato vem de quem escreve; em grupo, quem escreve é outra pessoa.
            $name = $this->nullable($message->senderName);
            if (! $message->fromMe && $name !== null && $chat->display_name !== $name) {
                $changes['display_name'] = $name;
            }
        }

        if ($chat->last_message_at === null || $sentAt->greaterThan($chat->last_message_at)) {
            $changes['last_message_at'] = $sentAt;
        }

        if ($changes !== []) {
            $chat->update($changes);
        }

        return $chat;
    }

    private function isDenylistedGroup(?string $groupName): bool
    {
        if ($groupName === null) {
            return false;
        }

        $normalized = mb_strtolower(trim($groupName));

        foreach (config('whatsapp.group_denylist', []) as $denied) {
            if ($normalized === mb_strtolower(trim($denied))) {
                return true;
            }
        }

        return false;
    }

    private function recordMedia(WhatsappAccount $account, WhatsappMessage $message, IncomingMedia $media, CarbonImmutable $sentAt): void
    {
        $stored = WhatsappMedia::create([
            'whatsapp_message_id' => $message->id,
            'kind' => $media->kind,
            'mime_type' => $media->mimeType,
            'size_bytes' => $media->sizeBytes,
            'filename' => $media->filename,
            'sha256' => $media->sha256,
            'storage_disk' => $media->storageDisk,
            'storage_path' => $media->storagePath,
            'provider_media_id' => $media->providerMediaId,
            'source_url' => $media->sourceUrl,
            'download_status' => $media->downloadStatus(),
            'duration_seconds' => $media->durationSeconds,
            // Só áudio é transcrito; quem processa a fila muda o status depois.
            'transcript_status' => $media->kind === 'audio' ? 'pending' : null,
        ]);

        // Pendente e com link pra buscar (hoje só o WAHA manda isso) — enfileira o download de
        // verdade. Sem link (ex.: Cloud API, que só dá um id de mídia), fica pendente mesmo.
        if ($stored->download_status === 'pending' && $media->sourceUrl !== null) {
            DownloadWahaMedia::dispatch($stored->id);
        }

        // Sem arquivo ainda, quem baixa a mídia (e depois transcreve) é avisado pelo evento "pending".
        $this->emit(
            $stored->download_status === 'done' ? self::EVENT_MEDIA : self::EVENT_MEDIA_PENDING,
            $account,
            'whatsapp_media',
            $stored->id,
            $sentAt,
            ['message_id' => $message->id, 'kind' => $media->kind],
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function emit(string $type, WhatsappAccount $account, string $subjectType, int $subjectId, CarbonImmutable $at, array $payload): void
    {
        Event::create([
            'type' => $type,
            'store_id' => $account->store_id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
            'occurred_at' => $at,
        ]);
    }

    /** O PostgreSQL rejeita o caractere nulo em texto e em jsonb. */
    private function clean(string $value): string
    {
        return str_replace("\0", '', $value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cleanPayload(array $payload): array
    {
        array_walk_recursive($payload, function (&$value) {
            if (is_string($value)) {
                $value = $this->clean($value);
            }
        });

        return $payload;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($this->clean($value));

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
