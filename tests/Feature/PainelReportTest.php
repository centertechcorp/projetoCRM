<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PainelReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/painel/relatorio')->assertRedirect('/login');
    }

    public function test_shows_todays_individual_messages_with_counts_per_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $centerAccount = $this->makeAccount($center, 'CENTER');
        $geniusAccount = $this->makeAccount($genius, 'GENIUS');

        $centerChat = $this->makeChat($centerAccount, 'Maria', '5534999990001');
        $this->makeMessage($centerAccount, $centerChat, 'in', 'Oi, tem iPhone 13?', now());
        $this->makeMessage($centerAccount, $centerChat, 'out', 'Temos sim!', now());

        $geniusChat = $this->makeChat($geniusAccount, 'João', '5534999990002');
        $this->makeMessage($geniusAccount, $geniusChat, 'in', 'Quanto custa a capinha?', now());

        // De ontem: não deve aparecer no relatório de hoje.
        $this->makeMessage($centerAccount, $centerChat, 'in', 'Mensagem de ontem', now()->subDay());

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->get('/painel/relatorio');

        $response->assertOk();
        $response->assertSee('Oi, tem iPhone 13?');
        $response->assertSee('Temos sim!');
        $response->assertSee('Quanto custa a capinha?');
        $response->assertDontSee('Mensagem de ontem');
        $response->assertSeeInOrder(['CENTER', '1 ', 'recebidas', '1 ', 'enviadas']);
    }

    public function test_an_invalid_date_falls_back_to_today_instead_of_crashing(): void
    {
        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $this->actingAs($user)->get('/painel/relatorio?date=data-invalida-aqui')
            ->assertOk()
            ->assertSee(CarbonImmutable::now()->format('d/m/Y'));
    }

    public function test_a_non_numeric_store_filter_is_ignored_instead_of_crashing(): void
    {
        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $this->actingAs($user)->get('/painel/relatorio?store=abc')->assertOk();
    }

    public function test_group_messages_never_appear_in_the_report(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($store, 'CENTER');
        $group = WhatsappChat::create([
            'whatsapp_account_id' => $account->id,
            'chat_key' => 'grupo_1',
            'jid' => '1@g.us',
            'kind' => 'group',
            'display_name' => 'Grupo qualquer',
            'ignored' => false,
        ]);
        $this->makeMessage($account, $group, 'in', 'Mensagem de grupo', now());

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $this->actingAs($user)->get('/painel/relatorio')->assertDontSee('Mensagem de grupo');
    }

    public function test_seller_only_sees_their_own_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $centerAccount = $this->makeAccount($center, 'CENTER');
        $geniusAccount = $this->makeAccount($genius, 'GENIUS');

        $this->makeMessage($centerAccount, $this->makeChat($centerAccount, 'Maria', '5534999990001'), 'in', 'Mensagem do CENTER', now());
        $this->makeMessage($geniusAccount, $this->makeChat($geniusAccount, 'João', '5534999990002'), 'in', 'Mensagem do GENIUS', now());

        $seller = $this->makeUser('vendedor@center.com', 'senha-boa-123', 'seller', $center->id);

        $response = $this->actingAs($seller)->get('/painel/relatorio');

        $response->assertSee('Mensagem do CENTER');
        $response->assertDontSee('Mensagem do GENIUS');
    }

    private function makeUser(string $email, string $password, string $role = 'owner', ?int $storeId = null): User
    {
        return User::create([
            'name' => 'Teste',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'owner_slot' => $role === 'owner' ? 1 : null,
            'store_id' => $storeId,
            'can_decide' => true,
        ]);
    }

    private function makeAccount(Store $store, string $label): WhatsappAccount
    {
        return WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => $label,
            'provider' => 'web_extension',
            'token_hash' => WhatsappAccount::hashToken('token-'.$store->code),
        ]);
    }

    private function makeChat(WhatsappAccount $account, string $name, string $phone): WhatsappChat
    {
        return WhatsappChat::create([
            'whatsapp_account_id' => $account->id,
            'chat_key' => $phone,
            'jid' => $phone.'@c.us',
            'kind' => 'individual',
            'phone' => $phone,
            'display_name' => $name,
        ]);
    }

    private function makeMessage(WhatsappAccount $account, WhatsappChat $chat, string $direction, string $body, CarbonImmutable|\Illuminate\Support\Carbon $sentAt): WhatsappMessage
    {
        static $counter = 0;
        $counter++;

        return WhatsappMessage::create([
            'whatsapp_account_id' => $account->id,
            'whatsapp_chat_id' => $chat->id,
            'external_id' => 'msg-'.$counter,
            'direction' => $direction,
            'type' => 'chat',
            'body' => $body,
            'sent_at' => $sentAt,
            'source' => 'web_extension',
        ]);
    }
}
