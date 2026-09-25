<?php

namespace App\Services\Whatsapp;

/**
 * Converte o payload de um webhook da Meta Cloud API em IncomingMessage.
 *
 * A Cloud API não tem grupos: `from` é sempre um número, e "chat" e "contato" são a
 * mesma coisa. Ver https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples.
 */
class MetaCloudApiAdapter
{
    private const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'sticker'];

    /**
     * @param  array<string, mixed>  $value  "entry[].changes[].value" de uma mudança do tipo "messages"
     * @return array<int, IncomingMessage>
     */
    public function messagesFrom(array $value): array
    {
        $messages = $value['messages'] ?? null;

        if (! is_array($messages)) {
            return [];
        }

        $names = $this->contactNames($value['contacts'] ?? null);
        $out = [];

        foreach ($messages as $raw) {
            $message = $this->convert($raw, $names);

            if ($message !== null) {
                $out[] = $message;
            }
        }

        return $out;
    }

    /** @return array<string, string> from (número) => nome do perfil */
    private function contactNames(mixed $contacts): array
    {
        if (! is_array($contacts)) {
            return [];
        }

        $names = [];

        foreach ($contacts as $contact) {
            $waId = $contact['wa_id'] ?? null;
            $name = $contact['profile']['name'] ?? null;

            if (is_string($waId) && is_string($name)) {
                $names[$waId] = $name;
            }
        }

        return $names;
    }

    /** @param  array<string, string>  $names */
    private function convert(mixed $raw, array $names): ?IncomingMessage
    {
        if (! is_array($raw)) {
            return null;
        }

        $id = $raw['id'] ?? null;
        $from = $raw['from'] ?? null;
        $timestamp = $raw['timestamp'] ?? null;
        $type = $raw['type'] ?? null;

        if (! is_string($id) || ! is_string($from) || $from === '' || ! is_string($type)) {
            return null;
        }

        // O timestamp da Cloud API vem como texto, diferente do número usado pelos outros provedores.
        $seconds = is_numeric($timestamp) ? (int) $timestamp : null;

        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $quoted = $raw['context']['id'] ?? null;

        return new IncomingMessage(
            id: $id,
            chat: "{$from}@s.whatsapp.net",
            fromMe: false,
            senderName: $names[$from] ?? '',
            body: $this->bodyOf($raw, $type),
            type: $type,
            timestamp: $seconds,
            media: $this->mediaOf($raw, $type),
            quotedExternalId: is_string($quoted) ? $quoted : null,
        );
    }

    private function bodyOf(array $raw, string $type): string
    {
        if ($type === 'text') {
            return (string) ($raw['text']['body'] ?? '');
        }

        return (string) ($raw[$type]['caption'] ?? '');
    }

    private function mediaOf(array $raw, string $type): ?IncomingMedia
    {
        if (! in_array($type, self::MEDIA_TYPES, true)) {
            return null;
        }

        $data = $raw[$type] ?? null;

        if (! is_array($data) || ! is_string($data['id'] ?? null)) {
            return null;
        }

        // A Cloud API só entrega o id da mídia; o arquivo é baixado depois, em outra etapa.
        return new IncomingMedia(
            kind: $type,
            mimeType: is_string($data['mime_type'] ?? null) ? $data['mime_type'] : null,
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : null,
            providerMediaId: $data['id'],
        );
    }
}
