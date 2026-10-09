<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Jobs\Whatsapp\ProcessWahaWebhook;
use App\Models\WhatsappAccount;
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

    public function receive(Request $request, string $store): Response
    {
        if (! $this->signatureIsValid($request)) {
            return response('', 401);
        }

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

        // Só enfileira e responde — quem grava de verdade é o job, fora do ciclo da
        // requisição (ver checklist de hospedagem: WAHA reentrega se demorar a responder).
        ProcessWahaWebhook::dispatch($account->id, $request->json()->all());

        return response('', 200);
    }

    /**
     * Sem WAHA_WEBHOOK_HMAC_KEY configurada, aceita sem validar (comportamento atual, o WAHA
     * ainda não assina). Assim que a chave for configurada dos dois lados, passa a exigir.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = config('whatsapp.waha.webhook_hmac_key');

        if (! is_string($secret) || $secret === '') {
            return true;
        }

        $header = (string) $request->header('X-Webhook-Hmac');

        if ($header === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }
}
