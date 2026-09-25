<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMedia;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsappMetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_TOKEN = 'verifica-isso';

    private const APP_SECRET = 'segredo-do-app';

    private const PHONE_NUMBER_ID = '106540352242922';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.meta.verify_token' => self::VERIFY_TOKEN,
            'whatsapp.meta.app_secret' => self::APP_SECRET,
        ]);
    }

    public function test_verification_handshake_echoes_the_challenge_when_the_token_matches(): void
    {
        $response = $this->get('/api/whatsapp/meta?hub_mode=subscribe&hub_verify_token='.self::VERIFY_TOKEN.'&hub_challenge=1234567890');

        $response->assertStatus(200)->assertSee('1234567890');
    }

    public function test_verification_handshake_rejects_the_wrong_token(): void
    {
        $response = $this->get('/api/whatsapp/meta?hub_mode=subscribe&hub_verify_token=errado&hub_challenge=xyz');

        $response->assertStatus(403);
    }

    public function test_incoming_request_without_a_valid_signature_is_rejected(): void
    {
        $body = $this->payload($this->textChange());

        $this->assertSame(401, $this->postSigned($body, null)->status());
        $this->assertSame(401, $this->postSigned($body, 'sha256=errado')->status());
        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_text_message_is_stored_for_the_matching_account(): void
    {
        $this->makeAccount();

        $response = $this->postSigned($this->payload($this->textChange()));

        $response->assertStatus(200);
        $message = WhatsappMessage::sole();
        $this->assertSame('oi, tudo bem?', $message->body);
        $this->assertSame('in', $message->direction);
        $this->assertSame('cloud_api', $message->source);
        $this->assertSame('16505551234', WhatsappChat::sole()->chat_key);
        $this->assertSame('Mary Cell', $message->sender_name);
    }

    public function test_image_message_is_stored_with_download_pending(): void
    {
        $this->makeAccount();

        $change = $this->textChange();
        $change['messages'][0] = [
            'id' => 'wamid.IMG1',
            'from' => '16505551234',
            'timestamp' => '1749416400',
            'type' => 'image',
            'image' => [
                'id' => '1003383421387256',
                'mime_type' => 'image/jpeg',
                'sha256' => 'SfInY0gGKTsJlUWbwxC1k+FAD0FZHvzwfpvO0zX0GUI=',
                'caption' => 'Taj Mahal',
            ],
        ];

        $this->postSigned($this->payload($change))->assertStatus(200);

        $media = WhatsappMedia::sole();
        $this->assertSame('pending', $media->download_status);
        $this->assertNull($media->storage_path);
        $this->assertNull($media->sha256);
        $this->assertSame('1003383421387256', $media->provider_media_id);
        $this->assertSame('Taj Mahal', WhatsappMessage::sole()->body);
    }

    public function test_unknown_phone_number_id_is_ignored_without_error(): void
    {
        // Nenhuma conta cadastrada para esse phone_number_id.
        $response = $this->postSigned($this->payload($this->textChange()));

        $response->assertStatus(200);
        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_string_timestamp_is_converted_to_an_integer(): void
    {
        $this->makeAccount();

        $this->postSigned($this->payload($this->textChange()))->assertStatus(200);

        $this->assertSame(1749416383, WhatsappMessage::sole()->sent_at->getTimestamp());
    }

    private function makeAccount(): WhatsappAccount
    {
        $store = Store::firstOrCreate(['code' => 'CENTER'], ['name' => 'CENTER']);

        return WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => 'WhatsApp CENTER (Meta)',
            'provider' => 'cloud_api',
            'provider_account_ref' => self::PHONE_NUMBER_ID,
        ]);
    }

    /** @return array<string, mixed> */
    private function textChange(): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'display_phone_number' => '15550783881',
                'phone_number_id' => self::PHONE_NUMBER_ID,
            ],
            'contacts' => [
                ['profile' => ['name' => 'Mary Cell'], 'wa_id' => '16505551234'],
            ],
            'messages' => [
                [
                    'id' => 'wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQTRBNjU5OUFFRTAzODEwMTQ0RgA=',
                    'from' => '16505551234',
                    'timestamp' => '1749416383',
                    'type' => 'text',
                    'text' => ['body' => 'oi, tudo bem?'],
                ],
            ],
        ];
    }

    /** @param  array<string, mixed>  $value */
    private function payload(array $value): string
    {
        return json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [
                ['id' => 'waba-1', 'changes' => [['value' => $value, 'field' => 'messages']]],
            ],
        ]);
    }

    private function postSigned(string $body, ?string $signatureOverride = 'compute'): TestResponse
    {
        $signature = $signatureOverride === 'compute'
            ? 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET)
            : $signatureOverride;

        $headers = ['Content-Type' => 'application/json'];

        if ($signature !== null) {
            $headers['X-Hub-Signature-256'] = $signature;
        }

        return $this->call('POST', '/api/whatsapp/meta', server: $this->transformHeadersToServerVars($headers), content: $body);
    }
}
