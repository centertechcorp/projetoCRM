<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
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

    public function test_searching_finds_a_contact_from_a_different_day(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');
        $chat = $this->makeChat($account, 'Gisele', '5534999990099');
        $this->makeMessage($account, $chat, 'in', 'oi, tudo bem?', now()->subDays(3));

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        // Vendo o dia de hoje sem busca, a mensagem de 3 dias atrás não aparece.
        $this->actingAs($user)->get('/painel/relatorio')->assertDontSee('Gisele');

        // Buscando pelo nome, aparece mesmo não sendo hoje — "se tá no banco, tem que aparecer".
        $response = $this->actingAs($user)->get('/painel/relatorio?search=Gisele');
        $response->assertOk();
        $response->assertSee('Gisele');
        $response->assertSee('buscando em todos os dias');
    }

    public function test_live_search_with_accept_json_returns_only_the_results_partial(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');
        $chat = $this->makeChat($account, 'Gisele', '5534999990099');
        $this->makeMessage($account, $chat, 'in', 'oi, tudo bem?', now()->subDays(3));

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->getJson('/painel/relatorio?search=Gisele');

        $response->assertOk();
        $response->assertJsonStructure(['html']);
        $html = $response->json('html');
        $this->assertStringContainsString('Gisele', $html);
        $this->assertStringContainsString('buscando em todos os dias', $html);
        // Só o pedaço dos resultados, não a página inteira (sem o cabeçalho "CRM WhatsApp").
        $this->assertStringNotContainsString('CRM WhatsApp', $html);
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

    public function test_without_rank_the_button_says_gerar_leads_do_dia_and_order_is_chronological(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');

        $weak = $this->makeChat($account, 'Cliente Fraco', '5534999990010');
        $this->makeMessage($account, $weak, 'in', 'oi', now()->subHours(3));

        $strong = $this->makeChat($account, 'Cliente Forte', '5534999990011');
        $this->makeMessage($account, $strong, 'in', 'Quanto fica no boleto, tem desconto?', now()->subHour());

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->get('/painel/relatorio');

        $response->assertOk();
        $response->assertSee('Gerar leads do dia');
        $response->assertDontSee('Voltar pra ordem cronológica');
        // Sem rank, é por hora — "oi" (3h atrás) vem antes de "Quanto fica..." (1h atrás)? Não:
        // ordena por last_sent_at DESC, então o mais recente (Forte) aparece primeiro mesmo sem rank.
        $response->assertSeeInOrder(['Cliente Forte', 'Cliente Fraco']);
    }

    public function test_rank_orders_strongest_lead_first_regardless_of_time(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');

        // O fraco é mais recente que o forte — sem rank ficaria primeiro; com rank, não.
        $weak = $this->makeChat($account, 'Cliente Fraco', '5534999990012');
        $this->makeMessage($account, $weak, 'in', 'oi', now()->subMinutes(10));

        $strong = $this->makeChat($account, 'Cliente Forte', '5534999990013');
        $this->makeMessage($account, $strong, 'in', 'Quanto fica parcelado no cartão pra esse iphone? Tem desconto à vista?', now()->subHours(5));

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->get('/painel/relatorio?rank=1');

        $response->assertOk();
        $response->assertSee('Voltar pra ordem cronológica');
        $response->assertSeeInOrder(['Cliente Forte', 'Cliente Fraco']);
        $response->assertSee('🔥 Alta');
        $response->assertSee('⚪ Baixa');
    }

    public function test_shows_an_ignorar_button_for_a_conversation_that_never_became_a_lead(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');
        $chat = $this->makeChat($account, 'Multishine Casa Da limpeza', '5534999990099');
        $this->makeMessage($account, $chat, 'in', 'Pedido mínimo para entrega 40,00', now());

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->get('/painel/relatorio');

        $response->assertOk();
        $response->assertSee('Ignorar');
        $response->assertDontSee('Deletar');
    }

    public function test_shows_the_same_lead_action_buttons_when_the_conversation_already_has_a_lead(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');
        $chat = $this->makeChat($account, 'Alana', '5534999990099');
        $this->makeMessage($account, $chat, 'in', 'oi', now());

        $customer = Customer::create(['name' => 'Alana', 'phone' => '5534999990099']);
        Lead::create([
            'customer_id' => $customer->id,
            'store_id' => $center->id,
            'whatsapp_chat_id' => $chat->id,
            'source' => 'whatsapp',
            'status' => 'open',
        ]);

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->get('/painel/relatorio');

        $response->assertOk();
        $response->assertSee('Comprou');
        $response->assertSee('Perdeu');
        $response->assertSee('Deletar');
        $response->assertDontSee('Ignorar');
    }

    public function test_ignoring_a_chat_from_the_report_removes_it_and_it_never_becomes_a_lead(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');
        $chat = $this->makeChat($account, 'Multishine Casa Da limpeza', '5534999990099');
        $this->makeMessage($account, $chat, 'in', 'Pedido mínimo para entrega 40,00', now());

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $this->actingAs($user)->post("/painel/chats/{$chat->id}/ignore")
            ->assertRedirect();

        $this->assertTrue($chat->fresh()->ignored);

        $this->actingAs($user)->get('/painel/relatorio')->assertDontSee('Multishine');
    }

    public function test_a_seller_cannot_ignore_a_chat_from_another_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $geniusAccount = $this->makeAccount($genius, 'GENIUS');
        $chat = $this->makeChat($geniusAccount, 'Cliente Genius', '5534999990098');

        $seller = $this->makeUser('vendedor@center.com', 'senha-boa-123', 'seller', $center->id);

        $this->actingAs($seller)->post("/painel/chats/{$chat->id}/ignore")->assertForbidden();
        $this->assertFalse($chat->fresh()->ignored);
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
