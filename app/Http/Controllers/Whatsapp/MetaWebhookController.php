<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Whatsapp\MessageRecorder;
use App\Services\Whatsapp\MetaCloudApiAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook da Meta WhatsApp Cloud API. Ver
 * https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks.
 */
class MetaWebhookController extends Controller
{
    public function __construct(
        private readonly MetaCloudApiAdapter $adapter,
        private readonly MessageRecorder $recorder,
    ) {}

    /** Checagem feita pela Meta ao salvar a URL do webhook no painel do app. */
    public function verify(Request $request): Response
    {
        $token = config('whatsapp.meta.verify_token');
        $matches = is_string($token) && $token !== ''
            && $request->query('hub_verify_token') === $token
            && $request->query('hub_mode') === 'subscribe';

        if (! $matches) {
            return response('', 403);
        }

        return response((string) $request->query('hub_challenge'), 200);
    }

    public function receive(Request $request): Response
    {
        if (! $this->signatureIsValid($request)) {
            return response('', 401);
        }

        $payload = $request->json()->all();

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $this->handleChange($change['value'] ?? null);
            }
        }

        // A Meta reenvia (com backoff) qualquer resposta fora da faixa 2xx, então erros de
        // conta desconhecida ou de mensagem em formato inesperado só são ignorados, não falham.
        return response('', 200);
    }

    private function handleChange(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! is_string($phoneNumberId)) {
            return;
        }

        $account = WhatsappAccount::query()
            ->where('provider', 'cloud_api')
            ->where('provider_account_ref', $phoneNumberId)
            ->where('active', true)
            ->first();

        if ($account === null) {
            return;
        }

        foreach ($this->adapter->messagesFrom($value) as $message) {
            $this->recorder->record($account, $message, 'cloud_api', $value);
        }
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = config('whatsapp.meta.app_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, substr($header, strlen('sha256=')));
    }
}
