<?php

namespace App\Services\Whatsapp;

use App\Models\Event;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
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

        $exists = WhatsappMessage::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('external_id', $message->id)
            ->exists();

        if ($exists) {
            return self::DUPLICATE;
        }

        DB::transaction(function () use ($account, $message, $source, $payload, $chatKey): void {
            $sentAt = CarbonImmutable::createFromTimestamp($message->timestamp);
            $chat = $this->chatFor($account, $message, $chatKey, $sentAt);

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

        return self::STORED;
    }

    private function chatFor(WhatsappAccount $account, IncomingMessage $message, string $chatKey, CarbonImmutable $sentAt): WhatsappChat
    {
        $isGroup = str_ends_with($message->chat, '@g.us');

        $chat = WhatsappChat::firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'chat_key' => $chatKey],
            [
                'jid' => $message->chat,
                'kind' => $isGroup ? 'group' : 'individual',
                // Só JIDs de telefone trazem o número; "@lid" é um identificador de privacidade.
                'phone' => ! $isGroup && preg_match('/^(\d+)(?::\d+)?@(c\.us|s\.whatsapp\.net)$/', $message->chat, $m) === 1 ? $m[1] : null,
            ],
        );

        $changes = [];

        // O nome do contato vem de quem escreve; em grupo, quem escreve é outra pessoa.
        $name = $this->nullable($message->senderName);
        if (! $isGroup && ! $message->fromMe && $name !== null && $chat->display_name !== $name) {
            $changes['display_name'] = $name;
        }

        if ($chat->last_message_at === null || $sentAt->greaterThan($chat->last_message_at)) {
            $changes['last_message_at'] = $sentAt;
        }

        if ($changes !== []) {
            $chat->update($changes);
        }

        return $chat;
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
            'download_status' => $media->downloadStatus(),
            'duration_seconds' => $media->durationSeconds,
            // Só áudio é transcrito; quem processa a fila muda o status depois.
            'transcript_status' => $media->kind === 'audio' ? 'pending' : null,
        ]);

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
