<?php

namespace App\Jobs\Whatsapp;

use App\Models\WhatsappAccount;
use App\Services\Whatsapp\MessageRecorder;
use App\Services\Whatsapp\WahaAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * O controller só valida a assinatura e enfileira isso, responde 200 na hora — é o que o
 * checklist de hospedagem do WAHA pede (responder rápido, processar depois), e evita reentrega
 * duplicada do WAHA se a gravação no banco demorar.
 */
class ProcessWahaWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(
        private readonly int $accountId,
        private readonly array $event,
    ) {}

    public function handle(WahaAdapter $adapter, MessageRecorder $recorder): void
    {
        $account = WhatsappAccount::query()->find($this->accountId);

        if ($account === null || ! $account->active) {
            return;
        }

        $message = $adapter->messageFrom($this->event);

        if ($message === null) {
            return;
        }

        $recorder->record($account, $message, 'waha', $this->event);
    }
}
