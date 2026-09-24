<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\HttpServer;
use App\Services\Whatsapp\MessageEndpoint;
use App\Services\Whatsapp\MessageStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('whatsapp:listen {--host= : Endereço de escuta (padrão: whatsapp.host)} {--port= : Porta de escuta (padrão: whatsapp.port)}')]
#[Description('Escuta as mensagens da extensão do WhatsApp Web e grava um arquivo de texto por contato')]
class WhatsappListen extends Command
{
    public function handle(): int
    {
        $token = config('whatsapp.token');

        if (! is_string($token) || $token === '') {
            $this->components->error('Defina WHATSAPP_TOKEN no .env antes de iniciar o daemon.');

            return self::FAILURE;
        }

        $host = $this->option('host') ?: config('whatsapp.host');
        $port = (int) ($this->option('port') ?: config('whatsapp.port'));
        $maxBody = (int) config('whatsapp.max_body_bytes');

        $store = new MessageStore(config('whatsapp.path'), config('app.timezone'));
        $endpoint = new MessageEndpoint(
            $store,
            $token,
            $maxBody,
            fn (string $line) => $this->line('['.now()->format('H:i:s').'] '.$line),
        );
        $server = new HttpServer($endpoint, $maxBody);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $server->stop());
            pcntl_signal(SIGINT, fn () => $server->stop());
        }

        $this->components->info("Escutando em http://{$host}:{$port} — gravando em ".config('whatsapp.path'));

        try {
            $server->listen($host, $port);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Daemon encerrado.');

        return self::SUCCESS;
    }
}
