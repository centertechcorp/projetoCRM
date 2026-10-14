<?php

namespace Tests\Feature;

use App\Jobs\Whatsapp\ResolveWahaChatPhone;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveWahaChatPhoneTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'chave-da-genius';

    private const BASE_URL = 'http://waha-genius-reachable.local';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.waha.accounts.genius.api_key' => self::API_KEY,
            'whatsapp.waha.accounts.genius.base_url' => self::BASE_URL,
            'whatsapp.waha.accounts.genius.session' => 'GENIUS',
        ]);
    }

    public function test_resolves_the_real_phone_and_saves_it(): void
    {
        Http::fake([
            self::BASE_URL.'/api/contacts*' => Http::response(['id' => '553488395950@c.us', 'number' => '234982123540724']),
        ]);

        $chat = $this->lidChat('234982123540724@lid');

        (new ResolveWahaChatPhone($chat->id))->handle();

        $this->assertSame('553488395950', $chat->fresh()->phone);
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), self::BASE_URL.'/api/contacts')
                && $request->hasHeader('X-Api-Key', self::API_KEY)
                && $request['session'] === 'GENIUS'
                && $request['contactId'] === '234982123540724@lid';
        });
    }

    public function test_an_unresolved_response_leaves_the_phone_null_to_retry_later(): void
    {
        Http::fake([self::BASE_URL.'/api/contacts*' => Http::response(['id' => '234982123540724@lid'])]);

        $chat = $this->lidChat('234982123540724@lid');

        (new ResolveWahaChatPhone($chat->id))->handle();

        $this->assertNull($chat->fresh()->phone);
    }

    public function test_a_waha_error_leaves_the_phone_null_without_throwing(): void
    {
        Http::fake([self::BASE_URL.'/api/contacts*' => Http::response([], 500)]);

        $chat = $this->lidChat('234982123540724@lid');

        (new ResolveWahaChatPhone($chat->id))->handle();

        $this->assertNull($chat->fresh()->phone);
    }

    public function test_a_chat_that_already_has_a_phone_does_not_call_waha(): void
    {
        Http::fake();

        $chat = $this->lidChat('234982123540724@lid');
        $chat->update(['phone' => '5534999990000']);

        (new ResolveWahaChatPhone($chat->id))->handle();

        Http::assertNothingSent();
    }

    public function test_a_group_chat_never_calls_waha(): void
    {
        Http::fake();

        $store = Store::firstOrCreate(['code' => 'GENIUS'], ['name' => 'GENIUS']);
        $account = WhatsappAccount::firstOrCreate(
            ['store_id' => $store->id, 'label' => 'WhatsApp GENIUS'],
            ['provider' => 'web_extension'],
        );
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $account->id,
            'chat_key' => '120363405703953892@g.us',
            'jid' => '120363405703953892@g.us',
            'kind' => 'group',
        ]);

        (new ResolveWahaChatPhone($chat->id))->handle();

        Http::assertNothingSent();
    }

    public function test_a_deleted_chat_row_is_a_silent_no_op(): void
    {
        Http::fake();

        (new ResolveWahaChatPhone(999999))->handle();

        Http::assertNothingSent();
    }

    private function lidChat(string $jid): WhatsappChat
    {
        $store = Store::firstOrCreate(['code' => 'GENIUS'], ['name' => 'GENIUS']);
        $account = WhatsappAccount::firstOrCreate(
            ['store_id' => $store->id, 'label' => 'WhatsApp GENIUS'],
            ['provider' => 'web_extension'],
        );

        return WhatsappChat::create([
            'whatsapp_account_id' => $account->id,
            // Em produção chat_key é só os dígitos (MessageStore::contactKey() tira o sufixo) —
            // diferente de propósito do jid, pra pegar o job usando o campo errado.
            'chat_key' => (string) preg_replace('/@.*$/', '', $jid),
            'jid' => $jid,
            'kind' => 'individual',
        ]);
    }
}
