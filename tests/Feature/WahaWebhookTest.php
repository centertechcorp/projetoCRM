<?php

namespace Tests\Feature;

use App\Jobs\Whatsapp\ProcessWahaWebhook;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WahaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const HMAC_KEY = 'chave-de-teste-do-waha';

    private WhatsappAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.waha.webhook_hmac_key' => self::HMAC_KEY]);

        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $this->account = WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => 'WhatsApp CENTER',
            'provider' => 'web_extension',
        ]);
    }

    public function test_valid_signature_is_accepted_and_queues_the_job(): void
    {
        Queue::fake();

        $response = $this->postSigned($this->payload());

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWahaWebhook::class);
    }

    public function test_wrong_signature_is_rejected(): void
    {
        Queue::fake();

        $response = $this->postSigned($this->payload(), 'assinatura-errada');

        $response->assertStatus(401);
        Queue::assertNotPushed(ProcessWahaWebhook::class);
    }

    public function test_missing_signature_is_rejected_when_a_key_is_configured(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/whatsapp/waha/center', json_decode($this->payload(), true));

        $response->assertStatus(401);
        Queue::assertNotPushed(ProcessWahaWebhook::class);
    }

    public function test_webhook_is_open_when_no_hmac_key_is_configured(): void
    {
        config(['whatsapp.waha.webhook_hmac_key' => '']);
        Queue::fake();

        $response = $this->postJson('/api/whatsapp/waha/center', json_decode($this->payload(), true));

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWahaWebhook::class);
    }

    public function test_unknown_store_in_the_url_is_a_404(): void
    {
        $response = $this->postSigned($this->payload(), 'compute', '/api/whatsapp/waha/loja-fantasma');

        $response->assertStatus(404);
    }

    public function test_known_store_without_a_matching_account_responds_200_without_queuing(): void
    {
        Queue::fake();

        $response = $this->postSigned($this->payload(), 'compute', '/api/whatsapp/waha/genius');

        $response->assertStatus(200);
        Queue::assertNotPushed(ProcessWahaWebhook::class);
    }

    public function test_inactive_account_responds_200_without_queuing(): void
    {
        $this->account->update(['active' => false]);
        Queue::fake();

        $response = $this->postSigned($this->payload());

        $response->assertStatus(200);
        Queue::assertNotPushed(ProcessWahaWebhook::class);
    }

    public function test_the_queued_job_actually_stores_the_message_when_it_runs(): void
    {
        // Sem Queue::fake() aqui de propósito: queremos ver o job rodar de verdade
        // (QUEUE_CONNECTION=sync em testes) e gravar no banco via MessageRecorder.
        $response = $this->postSigned($this->payload());

        $response->assertStatus(200);
        $message = WhatsappMessage::sole();
        $this->assertSame('oi, chegou?', $message->body);
        $this->assertSame('waha', $message->source);
        $this->assertSame('in', $message->direction);
    }

    public function test_a_message_with_media_queues_the_download_job(): void
    {
        // Só a fila do download fica "fake" — ProcessWahaWebhook roda de verdade (sync em
        // teste), então a mensagem/mídia já ficam gravadas de verdade pra conferir.
        Queue::fake([\App\Jobs\Whatsapp\DownloadWahaMedia::class]);

        $response = $this->postSigned($this->mediaPayload());

        $response->assertStatus(200);
        $media = \App\Models\WhatsappMedia::sole();
        $this->assertSame('pending', $media->download_status);
        $this->assertSame('http://host.docker.internal:3001/api/files/CENTER/abc.jpg', $media->source_url);
        Queue::assertPushed(\App\Jobs\Whatsapp\DownloadWahaMedia::class, 1);
    }

    public function test_has_media_true_with_a_null_media_object_stays_pending_without_a_source_url(): void
    {
        $body = json_encode([
            'event' => 'message',
            'session' => 'CENTER',
            'payload' => [
                'id' => 'true_5511999998888@c.us_NOMEDIA',
                'from' => '5511999998888@c.us',
                'fromMe' => false,
                'body' => '',
                'timestamp' => 1760000000,
                'hasMedia' => true,
                'media' => null,
                '_data' => ['type' => 'image', 'notifyName' => 'Cliente Teste'],
            ],
        ]);

        $this->postSigned($body)->assertStatus(200);

        $media = \App\Models\WhatsappMedia::sole();
        $this->assertSame('pending', $media->download_status);
        $this->assertNull($media->source_url);
    }

    public function test_non_message_events_like_session_status_are_accepted_but_store_nothing(): void
    {
        $body = json_encode(['event' => 'session.status', 'session' => 'CENTER', 'payload' => ['status' => 'WORKING']]);

        $this->postSigned($body)->assertStatus(200);

        $this->assertSame(0, WhatsappMessage::count());
    }

    private function payload(): string
    {
        return json_encode([
            'event' => 'message',
            'session' => 'CENTER',
            'payload' => [
                'id' => 'true_5511999998888@c.us_ABC123',
                'from' => '5511999998888@c.us',
                'fromMe' => false,
                'body' => 'oi, chegou?',
                'timestamp' => 1760000000,
                '_data' => ['type' => 'chat', 'notifyName' => 'Cliente Teste'],
            ],
        ]);
    }

    private function mediaPayload(): string
    {
        return json_encode([
            'event' => 'message',
            'session' => 'CENTER',
            'payload' => [
                'id' => 'true_5511999998888@c.us_MEDIA1',
                'from' => '5511999998888@c.us',
                'fromMe' => false,
                'body' => '',
                'timestamp' => 1760000000,
                'hasMedia' => true,
                'media' => [
                    'url' => 'http://host.docker.internal:3001/api/files/CENTER/abc.jpg',
                    'mimetype' => 'image/jpeg',
                    'filename' => null,
                ],
                '_data' => ['type' => 'image', 'notifyName' => 'Cliente Teste'],
            ],
        ]);
    }

    private function postSigned(string $body, ?string $signatureOverride = 'compute', string $url = '/api/whatsapp/waha/center'): TestResponse
    {
        $signature = $signatureOverride === 'compute'
            ? hash_hmac('sha512', $body, self::HMAC_KEY)
            : $signatureOverride;

        $headers = ['Content-Type' => 'application/json'];

        if ($signature !== null) {
            $headers['X-Webhook-Hmac'] = $signature;
        }

        return $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }
}
