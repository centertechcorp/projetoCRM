<?php

namespace App\Services\Whatsapp;

/**
 * Converte um evento de webhook do WAHA (WhatsApp HTTP API) em IncomingMessage.
 *
 * O WAHA (engine WEBJS, baseado no whatsapp-web.js) manda o mesmo tipo de id serializado
 * (`true_<jid>_<id>`) que a extensão já lê direto da página do WhatsApp Web — por isso os dois
 * dão o mesmo `external_id` pra a mesma mensagem, e o banco dedupe sozinho (único por conta +
 * external_id em MessageRecorder::record()) quando os dois rodam em paralelo na mesma conta.
 */
class WahaAdapter
{
    private const TYPE_MAP = [
        'ptt' => 'audio',
    ];

    /** @param  array<string, mixed>  $event  corpo inteiro do webhook ("event", "session", "payload") */
    public function messageFrom(array $event): ?IncomingMessage
    {
        if (($event['event'] ?? null) !== 'message' && ($event['event'] ?? null) !== 'message.any') {
            return null;
        }

        $payload = $event['payload'] ?? null;

        if (! is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;
        $from = $payload['from'] ?? null;
        $to = $payload['to'] ?? null;
        $fromMe = $payload['fromMe'] ?? null;
        $timestamp = $payload['timestamp'] ?? null;

        if (! is_string($id) || ! is_string($from) || ! is_bool($fromMe) || ! is_int($timestamp)) {
            return null;
        }

        // Como no daemon da extensão: o "chat" é sempre o outro lado da conversa, não a
        // própria conta — saindo (fromMe) o destinatário está em "to", entrando em "from".
        $chat = $fromMe && is_string($to) && $to !== '' ? $to : $from;

        $data = is_array($payload['_data'] ?? null) ? $payload['_data'] : [];
        $type = is_string($data['type'] ?? null) ? $data['type'] : 'chat';
        $type = self::TYPE_MAP[$type] ?? $type;

        $isGroup = str_ends_with($chat, '@g.us');
        $participant = $payload['participant'] ?? ($data['author']['_serialized'] ?? null);

        return new IncomingMessage(
            id: $id,
            chat: $chat,
            fromMe: $fromMe,
            senderName: $fromMe ? '' : (string) ($data['notifyName'] ?? ''),
            body: (string) ($payload['body'] ?? ''),
            type: $type,
            timestamp: $timestamp,
            senderJid: $fromMe ? null : (is_string($participant) ? $participant : null),
            media: $this->mediaOf($payload, $type),
            sentVia: $fromMe ? 'human' : null,
            groupName: $isGroup ? $this->groupNameOf($data) : null,
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function mediaOf(array $payload, string $type): ?IncomingMedia
    {
        if (($payload['hasMedia'] ?? false) !== true || ! in_array($type, IncomingMedia::KINDS, true)) {
            return null;
        }

        // O WAHA avisa que tem mídia mas não manda o arquivo no webhook — fica pendente,
        // igual acontece hoje com a Cloud API (MetaCloudApiAdapter).
        return new IncomingMedia(
            kind: $type,
            providerMediaId: is_string($payload['id'] ?? null) ? $payload['id'] : null,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function groupNameOf(array $data): ?string
    {
        $name = $data['chatName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
