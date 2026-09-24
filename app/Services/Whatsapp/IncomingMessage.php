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

        $valid = is_string($id) && preg_match('/^[\w.@:-]{1,200}$/', $id) === 1
            && is_string($chat) && strlen($chat) <= 200
            && is_bool($fromMe)
            && is_int($timestamp) && $timestamp > 0
            && is_string($senderName) && strlen($senderName) <= 500
            && is_string($body) && strlen($body) <= 200_000
            && is_string($type) && preg_match('/^[\w-]{1,40}$/', $type) === 1;

        if (! $valid) {
            return null;
        }

        return new self($id, $chat, $fromMe, $senderName, $body, $type, $timestamp);
    }
}
