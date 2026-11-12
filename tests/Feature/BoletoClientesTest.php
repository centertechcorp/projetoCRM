<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BoletoClientesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/painel/boletos')->assertRedirect('/login');
        $this->get('/painel/boletos/novos')->assertRedirect('/login');
    }

    public function test_lists_only_received_boleto_messages_including_old_ones(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center);

        $maria = $this->makeChat($account, 'Maria Antiga', '5534999990001');
        $this->makeMessage($account, $maria, 'in', 'Vocês vendem no boleto?', now()->subMonths(3));

        $joao = $this->makeChat($account, 'João Novo', '5534999990002');
        $this->makeMessage($account, $joao, 'in', 'Posso pagar em BOLETOS?', now());

        // Não devem aparecer: mensagem da loja, outra palavra, chat ignorado e grupo.
        $loja = $this->makeChat($account, 'Só Resposta', '5534999990003');
        $this->makeMessage($account, $loja, 'out', 'No boleto não trabalhamos', now());
        $outro = $this->makeChat($account, 'Sem Palavra', '5534999990004');
        $this->makeMessage($account, $outro, 'in', 'Quero um iPhone', now());
        $ignorado = $this->makeChat($account, 'Ignorado', '5534999990005', ignored: true);
        $this->makeMessage($account, $ignorado, 'in', 'boleto', now());
        $grupo = $this->makeChat($account, 'Grupo', '5534999990006', kind: 'group');
        $this->makeMessage($account, $grupo, 'in', 'boleto', now());

        $response = $this->actingAs($this->makeUser('owner@center.com'))->get('/painel/boletos');

        $response->assertOk()
            ->assertSee('Maria Antiga')
            ->assertSee('Vocês vendem no boleto?')
            ->assertSee('João Novo')
            ->assertDontSee('Só Resposta')
            ->assertDontSee('Sem Palavra')
            ->assertDontSee('Ignorado')
            ->assertDontSee('>Grupo<', false);
    }

    public function test_seller_only_sees_own_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $centerAccount = $this->makeAccount($center);
        $geniusAccount = $this->makeAccount($genius);

        $this->makeMessage($centerAccount, $this->makeChat($centerAccount, 'Cliente Center', '5534999990011'), 'in', 'tem boleto?', now());
        $this->makeMessage($geniusAccount, $this->makeChat($geniusAccount, 'Cliente Genius', '5534999990012'), 'in', 'tem boleto?', now());

        $seller = $this->makeUser('vendedora@center.com', 'seller', $center->id);

        $this->actingAs($seller)->get('/painel/boletos')
            ->assertOk()
            ->assertSee('Cliente Center')
            ->assertDontSee('Cliente Genius');
    }

    public function test_check_counts_only_messages_after_the_last_seen_id(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center);
        $chat = $this->makeChat($account, 'Maria', '5534999990021');
        $other = $this->makeChat($account, 'Ana', '5534999990022');

        $old = $this->makeMessage($account, $chat, 'in', 'boleto?', now()->subDay());
        $new1 = $this->makeMessage($account, $chat, 'in', 'e no boleto?', now());
        $new2 = $this->makeMessage($account, $other, 'in', 'aceita boleto?', now());
        $this->makeMessage($account, $other, 'out', 'boleto não', now());

        $user = $this->makeUser('owner@center.com');

        $this->actingAs($user)->getJson('/painel/boletos/novos?after='.$old->id)
            ->assertOk()
            ->assertJson(['count' => 2, 'clients' => 2, 'latest_id' => $new2->id]);

        $this->actingAs($user)->getJson('/painel/boletos/novos?after='.$new2->id)
            ->assertOk()
            ->assertJson(['count' => 0, 'clients' => 0, 'latest_id' => $new2->id]);

        // Sem "after" (primeiro acesso do navegador): devolve só a base, sem avisar do passado.
        $this->actingAs($user)->getJson('/painel/boletos/novos')
            ->assertOk()
            ->assertJson(['count' => 0, 'latest_id' => $new2->id]);

        $this->assertNotNull($new1);
    }

    public function test_back_link_uses_history_back_with_painel_as_fallback(): void
    {
        $this->actingAs($this->makeUser('owner@center.com'))->get('/painel/boletos')
            ->assertOk()
            ->assertSee('history.back()', false)
            ->assertSee('href="'.route('painel.index').'" id="boletoBack"', false);
    }

    public function test_popup_is_included_in_the_layout_for_logged_users(): void
    {
        $this->actingAs($this->makeUser('owner@center.com'))->get('/painel')
            ->assertOk()
            ->assertSee('Novos clientes de boleto')
            ->assertSee(route('painel.boletos'), false);
    }

    private function makeUser(string $email, string $role = 'owner', ?int $storeId = null): User
    {
        return User::create([
            'name' => 'Teste',
            'email' => $email,
            'password' => Hash::make('senha-boa-123'),
            'role' => $role,
            'owner_slot' => $role === 'owner' ? 1 : null,
            'store_id' => $storeId,
            'can_decide' => true,
        ]);
    }

    private function makeAccount(Store $store): WhatsappAccount
    {
        return WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => $store->code,
            'provider' => 'web_extension',
            'token_hash' => WhatsappAccount::hashToken('token-'.$store->code),
        ]);
    }

    private function makeChat(WhatsappAccount $account, string $name, string $phone, bool $ignored = false, string $kind = 'individual'): WhatsappChat
    {
        return WhatsappChat::create([
            'whatsapp_account_id' => $account->id,
            'chat_key' => $phone,
            'jid' => $phone.'@c.us',
            'kind' => $kind,
            'ignored' => $ignored,
            'phone' => $phone,
            'display_name' => $name,
        ]);
    }

    private function makeMessage(WhatsappAccount $account, WhatsappChat $chat, string $direction, string $body, \Illuminate\Support\Carbon $sentAt): WhatsappMessage
    {
        static $counter = 0;
        $counter++;

        return WhatsappMessage::create([
            'whatsapp_account_id' => $account->id,
            'whatsapp_chat_id' => $chat->id,
            'external_id' => 'bol-'.$counter,
            'direction' => $direction,
            'type' => 'chat',
            'body' => $body,
            'sent_at' => $sentAt,
            'source' => 'web_extension',
        ]);
    }
}
