<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappChatIgnoreCommandTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $this->account = WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => 'CENTER',
            'provider' => 'web_extension',
            'token_hash' => WhatsappAccount::hashToken('t'),
        ]);
    }

    public function test_lists_chats_of_the_store_with_their_ignored_state(): void
    {
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $this->account->id,
            'chat_key' => 'grupo_1',
            'jid' => '1@g.us',
            'kind' => 'group',
            'display_name' => 'Grupo de vendas',
            'ignored' => true,
        ]);

        $this->artisan('whatsapp:chats:ignore', ['store' => 'CENTER'])
            ->expectsTable(
                ['ID', 'Tipo', 'Nome/telefone', 'Última mensagem', 'Ignorado?'],
                [[$chat->id, 'group', 'Grupo de vendas', '—', 'sim']],
            )
            ->assertSuccessful();
    }

    public function test_toggles_ignored_state_by_id(): void
    {
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $this->account->id,
            'chat_key' => 'grupo_1',
            'jid' => '1@g.us',
            'kind' => 'group',
            'display_name' => 'Grupo de vendas',
            'ignored' => false,
        ]);

        $this->artisan('whatsapp:chats:ignore', ['store' => 'CENTER', '--id' => $chat->id, '--state' => 'on'])->assertSuccessful();
        $this->assertTrue($chat->fresh()->ignored);

        $this->artisan('whatsapp:chats:ignore', ['store' => 'CENTER', '--id' => $chat->id, '--state' => 'off'])->assertSuccessful();
        $this->assertFalse($chat->fresh()->ignored);
    }

    public function test_rejects_a_chat_that_belongs_to_another_store(): void
    {
        $other = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $otherAccount = WhatsappAccount::create([
            'store_id' => $other->id,
            'label' => 'GENIUS',
            'provider' => 'web_extension',
            'token_hash' => WhatsappAccount::hashToken('t2'),
        ]);
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $otherAccount->id,
            'chat_key' => 'grupo_1',
            'jid' => '1@g.us',
            'kind' => 'group',
            'ignored' => false,
        ]);

        $this->artisan('whatsapp:chats:ignore', ['store' => 'CENTER', '--id' => $chat->id, '--state' => 'on'])->assertFailed();
        $this->assertFalse($chat->fresh()->ignored);
    }
}
