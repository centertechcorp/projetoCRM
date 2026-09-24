<?php

namespace App\Services\Whatsapp;

/**
 * Requisição HTTP/1.1 mínima: só o necessário para o daemon local.
 * Não há suporte a Transfer-Encoding (o fetch da extensão sempre envia Content-Length).
 */
final readonly class HttpRequest
{
    private const MAX_HEAD_BYTES = 16 * 1024;

    /**
     * @param  array<string, string>  $headers  nomes em minúsculas
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public string $body,
        public int $contentLength,
    ) {}

    /** O buffer já tem uma requisição inteira (ou algo que já dá para responder com erro)? */
    public static function isComplete(string $buffer, int $maxBody): bool
    {
        $end = strpos($buffer, "\r\n\r\n");

        if ($end === false) {
            return strlen($buffer) > self::MAX_HEAD_BYTES;
        }

        $length = self::declaredLength(substr($buffer, 0, $end));

        if ($length === null || $length > $maxBody) {
            return true;
        }

        return strlen($buffer) - ($end + 4) >= $length;
    }

    public static function parse(string $raw): ?self
    {
        $end = strpos($raw, "\r\n\r\n");

        if ($end === false || $end > self::MAX_HEAD_BYTES) {
            return null;
        }

        $lines = explode("\r\n", substr($raw, 0, $end));

        if (preg_match('#^([A-Z]+) (\S+) HTTP/1\.[01]$#', array_shift($lines), $m) !== 1) {
            return null;
        }

        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                return null;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        if (isset($headers['transfer-encoding'])) {
            return null;
        }

        $length = self::declaredLength(substr($raw, 0, $end));

        if ($length === null) {
            return null;
        }

        $path = parse_url($m[2], PHP_URL_PATH);

        return new self(
            $m[1],
            is_string($path) ? $path : '/',
            $headers,
            substr($raw, $end + 4, $length),
            $length,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Content-Length declarado: 0 se ausente, null se inválido. */
    private static function declaredLength(string $head): ?int
    {
        if (preg_match('/^content-length:[ \t]*([^\r\n]*?)[ \t]*\r?$/im', $head, $m) !== 1) {
            return 0;
        }

        return ctype_digit($m[1]) && strlen($m[1]) <= 12 ? (int) $m[1] : null;
    }
}
