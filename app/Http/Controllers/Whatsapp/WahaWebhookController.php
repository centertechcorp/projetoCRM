<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\MessageRecorder;
use App\Services\Whatsapp\WahaAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Etapa 2 do teste do WAHA (ver plano salvo): grava de verdade no banco, em paralelo com o
 * daemon da extensão, reaproveitando MessageRecorder — a mesma porta de escrita de sempre.
 * Roda junto com o daemon por enquanto; nada muda nas tabelas nem na detecção de lead.
 */
class WahaWebhookController extends Controller
{
    /** Rota => label da conta já cadastrada (whatsapp_accounts), igual a usada pelo daemon. */
    private const ACCOUNT_LABEL = [
        'center' => 'WhatsApp CENTER',
        'genius' => 'WhatsApp GENIUS',
    ];

    public function __construct(
        private readonly WahaAdapter $adapter,
        private readonly MessageRecorder $recorder,
    ) {}

    public function receive(Request $request, string $store): Response
    {
        $label = self::ACCOUNT_LABEL[$store] ?? null;

        if ($label === null) {
            return response('', 404);
        }

        $account = WhatsappAccount::query()
            ->where('label', $label)
            ->where('active', true)
            ->first();

        if ($account === null) {
            return response('', 200);
        }

        $event = $request->json()->all();
        $message = $this->adapter->messageFrom($event);

        if ($message !== null) {
            $this->recorder->record($account, $message, 'waha', $event);
        }

        return response('', 200);
    }
}
