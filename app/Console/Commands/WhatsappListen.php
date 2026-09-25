<?php

namespace App\Console\Commands;

use App\Models\WhatsappAccount;
use App\Services\Whatsapp\HttpServer;
use App\Services\Whatsapp\MessageEndpoint;
use App\Services\Whatsapp\MessageRecorder;
use App\Services\Whatsapp\MessageStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('whatsapp:listen {--host= : Endereço de escuta (padrão: whatsapp.host)} {--port= : Porta de escuta (padrão: whatsapp.port)} {--database : Grava também no banco, com o token de cada conta (padrão: whatsapp.database)}')]
#[Description('Escuta as mensagens da extensão do WhatsApp Web e grava um arquivo de texto por contato (e no banco, com --database)')]
class WhatsappListen extends Command
{
    public function handle(): int
    {
        $useDatabase = $this->option('database') || config('whatsapp.database');
        $token = config('whatsapp.token');

        if (! $useDatabase && (! is_string($token) || $token === '')) {
            $this->components->error('Defina WHATSAPP_TOKEN no .env antes de iniciar o daemon (ou use --database com uma conta criada por whatsapp:account).');

            return self::FAILURE;
        }

        $recorder = null;

        if ($useDatabase) {
            try {
                $accounts = WhatsappAccount::query()->where('active', true)->count();
            } catch (Throwable $e) {
                $this->components->error('Não consegui ler o banco: '.$e->getMessage());
                $this->components->warn('Rodando no host, use DB_HOST=127.0.0.1 (o nome "pgsql" só existe dentro do Sail).');

                return self::FAILURE;
            }

            if ($accounts === 0) {
                $this->components->error('Nenhuma conta ativa. Crie uma com: php artisan whatsapp:account <loja> "<nome>"');

                return self::FAILURE;
            }

            $recorder = new MessageRecorder;
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
            $recorder,
        );
        $server = new HttpServer($endpoint, $maxBody);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $server->stop());
            pcntl_signal(SIGINT, fn () => $server->stop());
        }

        $this->components->info("Escutando em http://{$host}:{$port} — gravando em ".config('whatsapp.path').($recorder ? ' e no banco' : ''));

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
