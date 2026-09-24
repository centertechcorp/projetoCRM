<?php

namespace App\Services\Whatsapp;

use RuntimeException;
use Throwable;

/**
 * Servidor HTTP de um processo só, sem dependências: aceita conexões, monta a
 * requisição, entrega ao MessageEndpoint e fecha. Suficiente para um único
 * cliente local (a extensão).
 */
class HttpServer
{
    private const CLIENT_TIMEOUT_SECONDS = 10;

    private bool $running = true;

    public function __construct(
        private readonly MessageEndpoint $endpoint,
        private readonly int $maxBody,
    ) {}

    public function stop(): void
    {
        $this->running = false;
    }

    public function listen(string $host, int $port): void
    {
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $error);

        if ($server === false) {
            throw new RuntimeException("Não foi possível escutar em {$host}:{$port}: {$error}");
        }

        stream_set_blocking($server, false);

        /** @var array<int, resource> $clients */
        $clients = [];
        $buffers = [];
        $deadlines = [];

        while ($this->running) {
            $read = [$server, ...array_values($clients)];
            $write = $except = null;

            // Retorna false quando um sinal (SIGTERM/SIGINT) interrompe a espera.
            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $socket) {
                if ($socket === $server) {
                    $client = @stream_socket_accept($server, 0);

                    if ($client !== false) {
                        stream_set_blocking($client, false);
                        $clients[(int) $client] = $client;
                        $buffers[(int) $client] = '';
                        $deadlines[(int) $client] = time() + self::CLIENT_TIMEOUT_SECONDS;
                    }

                    continue;
                }

                $id = (int) $socket;
                $data = fread($socket, 65536);

                if ($data === false || ($data === '' && feof($socket))) {
                    $this->close($id, $clients, $buffers, $deadlines);

                    continue;
                }

                $buffers[$id] .= $data;

                if (HttpRequest::isComplete($buffers[$id], $this->maxBody)) {
                    $this->respond($socket, $buffers[$id]);
                    $this->close($id, $clients, $buffers, $deadlines);
                }
            }

            foreach ($deadlines as $id => $deadline) {
                if ($deadline < time()) {
                    $this->close($id, $clients, $buffers, $deadlines);
                }
            }
        }

        foreach ($clients as $id => $client) {
            $this->close($id, $clients, $buffers, $deadlines);
        }

        fclose($server);
    }

    /** @param resource $socket */
    private function respond($socket, string $raw): void
    {
        try {
            $response = $this->endpoint->handle($raw);
        } catch (Throwable $e) {
            report($e);
            $response = "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 2);
        @fwrite($socket, $response);
    }

    private function close(int $id, array &$clients, array &$buffers, array &$deadlines): void
    {
        if (isset($clients[$id])) {
            @fclose($clients[$id]);
        }

        unset($clients[$id], $buffers[$id], $deadlines[$id]);
    }
}
