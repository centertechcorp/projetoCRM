<?php

namespace App\Services\Whatsapp;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Grava cada mensagem em um arquivo de texto por contato, somente com acréscimos.
 *
 * Os ids já gravados ficam em um índice lateral (`.seen/<contato>.ids`), também
 * append-only, para que reenvios da extensão não dupliquem linhas.
 */
class MessageStore
{
    public const STORED = 'stored';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    private const MEDIA_LABELS = [
        'image' => '[imagem]',
        'video' => '[vídeo]',
        'gif' => '[gif]',
        'audio' => '[áudio]',
        'ptt' => '[áudio]',
        'document' => '[documento]',
        'sticker' => '[figurinha]',
        'location' => '[localização]',
        'vcard' => '[contato]',
        'multi_vcard' => '[contatos]',
        'revoked' => '[mensagem apagada]',
    ];

    /** @var array<string, array<string, true>> */
    private array $seen = [];

    public function __construct(
        private readonly string $path,
        private readonly string $timezone,
    ) {}

    /** Um armazenamento independente em uma subpasta (uma por conta de WhatsApp). */
    public function within(string $directory): self
    {
        return new self("{$this->path}/{$directory}", $this->timezone);
    }

    public function append(IncomingMessage $message): string
    {
        $contact = self::contactKey($message->chat);

        if ($contact === null) {
            return self::IGNORED;
        }

        if (isset($this->seenIds($contact)[$message->id])) {
            return self::DUPLICATE;
        }

        $this->write("{$this->path}/{$contact}.txt", $this->formatLine($message, $contact));
        $this->write("{$this->path}/.seen/{$contact}.ids", $message->id);
        $this->seen[$contact][$message->id] = true;

        return self::STORED;
    }

    /**
     * Nome do arquivo do contato, derivado só de dígitos do JID.
     * Retorna null para status, listas de transmissão, canais e JIDs inválidos.
     */
    public static function contactKey(string $jid): ?string
    {
        if (preg_match('/^(\d+(?:-\d+)?)(?::\d+)?@(c\.us|s\.whatsapp\.net|lid|g\.us)$/', $jid, $m) !== 1) {
            return null;
        }

        return $m[2] === 'g.us'
            ? 'grupo_'.str_replace('-', '_', $m[1])
            : $m[1];
    }

    private function formatLine(IncomingMessage $message, string $contact): string
    {
        $time = CarbonImmutable::createFromTimestamp($message->timestamp, $this->timezone)
            ->format('Y-m-d H:i:s');

        $direction = $message->fromMe ? 'out' : 'in';

        $name = trim(preg_replace('/\s+/u', ' ', $this->stripControl($message->senderName)) ?? '');
        $name = mb_substr($name, 0, 100);
        $name = $name !== '' ? $name : ($message->fromMe ? 'Eu' : $contact);

        return "[{$time}] {$direction} | {$name}: {$this->formatBody($message)}";
    }

    private function formatBody(IncomingMessage $message): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], '\n', $message->body);
        $text = trim($this->stripControl($text));

        $label = self::MEDIA_LABELS[$message->type] ?? null;

        if ($label === null) {
            return $text !== '' ? $text : "[{$message->type}]";
        }

        return $text !== '' ? "{$label} {$text}" : $label;
    }

    private function stripControl(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }

    /** @return array<string, true> */
    private function seenIds(string $contact): array
    {
        if (! isset($this->seen[$contact])) {
            $file = "{$this->path}/.seen/{$contact}.ids";
            $ids = is_file($file)
                ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                : [];

            $this->seen[$contact] = array_fill_keys($ids ?: [], true);
        }

        return $this->seen[$contact];
    }

    private function write(string $file, string $line): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Não foi possível criar o diretório {$directory}.");
        }

        if (file_put_contents($file, $line.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Não foi possível gravar em {$file}.");
        }
    }
}
