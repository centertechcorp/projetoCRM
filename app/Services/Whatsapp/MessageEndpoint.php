<?php

namespace App\Services\Whatsapp;

use Closure;

/**
 * Trata uma requisição HTTP completa e devolve a resposta HTTP completa.
 * Não conhece sockets, então dá para testar passando strings.
 */
class MessageEndpoint
{
    private const REASONS = [
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        413 => 'Payload Too Large',
        500 => 'Internal Server Error',
    ];

    /**
     * @param  ?Closure(string): void  $logger
     */
    public function __construct(
        private readonly MessageStore $store,
        private readonly ?string $token,
        private readonly int $maxBody,
        private readonly ?Closure $logger = null,
    ) {}

    public function handle(string $raw): string
    {
        $request = HttpRequest::parse($raw);

        if ($request === null) {
            return $this->json(400, ['error' => 'requisição inválida']);
        }

        if ($request->contentLength > $this->maxBody) {
            return $this->json(413, ['error' => 'corpo grande demais']);
        }

        return match ($request->path) {
            '/health' => $request->method === 'GET'
                ? $this->json(200, ['status' => 'ok'])
                : $this->json(405, ['error' => 'método não permitido']),
            '/messages' => $request->method === 'POST'
                ? $this->receive($request)
                : $this->json(405, ['error' => 'método não permitido']),
            default => $this->json(404, ['error' => 'não encontrado']),
        };
    }

    private function receive(HttpRequest $request): string
    {
        $given = $request->header('x-token');

        if ($this->token === null || $this->token === '' || $given === null || ! hash_equals($this->token, $given)) {
            return $this->json(401, ['error' => 'token inválido']);
        }

        $message = IncomingMessage::fromArray(json_decode($request->body, true));

        if ($message === null) {
            return $this->json(400, ['error' => 'mensagem inválida']);
        }

        $status = $this->store->append($message);

        if ($this->logger !== null) {
            ($this->logger)(sprintf(
                '%s %s %s',
                $status,
                $message->fromMe ? 'out' : 'in',
                $this->store->contactKey($message->chat) ?? $message->chat,
            ));
        }

        return $this->json(200, ['status' => $status]);
    }

    /** @param  array<string, mixed>  $data */
    private function json(int $status, array $data): string
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);

        return sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $status,
            self::REASONS[$status],
            strlen($body),
            $body,
        );
    }
}
