<?php

namespace App\Services\Whatsapp;

final readonly class IncomingMessage
{
    public function __construct(
        public string $id,
        public string $chat,
        public bool $fromMe,
        public string $senderName,
        public string $body,
        public string $type,
        public int $timestamp,
        public ?string $senderJid = null,
        public ?IncomingMedia $media = null,
        public ?string $quotedExternalId = null,
        public ?string $sentVia = null,
    ) {}

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? null;
        $chat = $data['chat'] ?? null;
        $fromMe = $data['from_me'] ?? null;
        $timestamp = $data['timestamp'] ?? null;
        $senderName = $data['sender_name'] ?? '';
        $body = $data['body'] ?? '';
        $type = $data['type'] ?? 'chat';
        $senderJid = $data['sender_jid'] ?? null;
        $quoted = $data['quoted_external_id'] ?? null;
        $sentVia = $data['sent_via'] ?? null;

        $valid = is_string($id) && preg_match('/^[\w.@:-]{1,200}$/', $id) === 1
            && is_string($chat) && strlen($chat) <= 200
            && is_bool($fromMe)
            && is_int($timestamp) && $timestamp > 0
            && is_string($senderName) && strlen($senderName) <= 500
            && is_string($body) && strlen($body) <= 200_000
            && is_string($type) && preg_match('/^[\w-]{1,40}$/', $type) === 1
            && ($senderJid === null || (is_string($senderJid) && strlen($senderJid) <= 200))
            && ($quoted === null || (is_string($quoted) && strlen($quoted) <= 200))
            && ($sentVia === null || (is_string($sentVia) && strlen($sentVia) <= 16));

        if (! $valid) {
            return null;
        }

        return new self(
            $id, $chat, $fromMe, $senderName, $body, $type, $timestamp,
            $senderJid === '' ? null : $senderJid,
            null,
            $quoted === '' ? null : $quoted,
            $sentVia === '' ? null : $sentVia,
        );
    }
}
