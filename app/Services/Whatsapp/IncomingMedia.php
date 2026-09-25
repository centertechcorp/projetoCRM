<?php

namespace App\Services\Whatsapp;

/**
 * Mídia de uma mensagem. O banco só guarda metadados; o arquivo fica em disco.
 *
 * Se quem recebeu já baixou o arquivo, informe disco, caminho e o SHA-256 (hexadecimal) do
 * arquivo. Provedores como a Cloud API só entregam um id de mídia: nesse caso deixe o caminho
 * vazio e informe `providerMediaId`, e a mídia fica com download pendente.
 */
final readonly class IncomingMedia
{
    public const KINDS = ['image', 'audio', 'video', 'document', 'sticker'];

    public function __construct(
        public string $kind,
        public ?string $sha256 = null,
        public ?string $storageDisk = null,
        public ?string $storagePath = null,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public ?int $durationSeconds = null,
        public ?string $filename = null,
        public ?string $providerMediaId = null,
    ) {}

    public function downloadStatus(): string
    {
        return $this->storagePath !== null && $this->storageDisk !== null ? 'done' : 'pending';
    }
}
