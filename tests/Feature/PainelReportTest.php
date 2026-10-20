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
use Illuminate\Support\Facades\DB;
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

    public function test_the_report_is_always_ranked_by_priority_with_no_toggle(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');

        // O fraco é mais recente que o forte — por hora ficaria primeiro; por prioridade, não.
        $weak = $this->makeChat($account, 'Cliente Fraco', '5534999990010');
        $this->makeMessage($account, $weak, 'in', 'oi', now()->subMinutes(10));

        $strong = $this->makeChat($account, 'Cliente Forte', '5534999990011');
        $this->makeMessage($account, $strong, 'in', 'Quanto fica parcelado no cartão pra esse iphone? Tem desconto à vista?', now()->subHours(5));

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->get('/painel/relatorio');

        $response->assertOk();
        // Rótulos de prioridade (Alta/Média/Baixa) já aparecem sem precisar clicar em nada,
        // e não existe mais nenhum botão de alternância.
        $response->assertDontSee('Gerar leads do dia');
        $response->assertDontSee('Voltar pra ordem cronológica');
        $response->assertSeeInOrder(['Cliente Forte', 'Cliente Fraco']);
        $response->assertSee('🔥 Alta');
        $response->assertSee('⚪ Baixa');
    }

    public function test_an_old_rank_query_param_no_longer_changes_anything(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');

        $weak = $this->makeChat($account, 'Cliente Fraco', '5534999990010');
        $this->makeMessage($account, $weak, 'in', 'oi', now()->subMinutes(10));

        $strong = $this->makeChat($account, 'Cliente Forte', '5534999990011');
        $this->makeMessage($account, $strong, 'in', 'Quanto fica parcelado no cartão pra esse iphone? Tem desconto à vista?', now()->subHours(5));

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        // rank=0 é um link antigo (favorito salvo, histórico do navegador) — o parâmetro
        // simplesmente não existe mais no controller, então o resultado é sempre ranqueado.
        $response = $this->actingAs($user)->get('/painel/relatorio?rank=0');

        $response->assertOk();
        $response->assertSeeInOrder(['Cliente Forte', 'Cliente Fraco']);
        $response->assertSee('🔥 Alta');
    }

    public function test_shows_a_deletar_button_for_a_conversation_that_never_became_a_lead(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');
        $chat = $this->makeChat($account, 'Multishine Casa Da limpeza', '5534999990099');
        $this->makeMessage($account, $chat, 'in', 'Pedido mínimo para entrega 40,00', now());

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->get('/painel/relatorio');

        $response->assertOk();
        $response->assertSee('Deletar');
    }

    public function test_restoring_an_ignored_chat_that_never_became_a_lead_undoes_it(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = $this->makeAccount($center, 'CENTER');
        $chat = $this->makeChat($account, 'Fornecedor', '5534999990098');
        $this->makeMessage($account, $chat, 'in', 'oi', now());

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $this->actingAs($user)->post(route('painel.chats.ignore', $chat))->assertRedirect();
        $this->assertTrue($chat->fresh()->ignored);

        $response = $this->actingAs($user)->postJson(route('painel.chats.restore', $chat));

        $response->assertOk();
        $this->assertFalse($chat->fresh()->ignored);
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

    public function test_a_seller_cannot_restore_a_chat_from_another_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $geniusAccount = $this->makeAccount($genius, 'GENIUS');
        $chat = $this->makeChat($geniusAccount, 'Cliente Genius', '5534999990097');
        $chat->update(['ignored' => true]);

        $seller = $this->makeUser('vendedor@center.com', 'senha-boa-123', 'seller', $center->id);

        $this->actingAs($seller)->post("/painel/chats/{$chat->id}/restore")->assertForbidden();
        $this->assertTrue($chat->fresh()->ignored);
    }

    public function test_guest_is_redirected_to_login_for_actions_summary(): void
    {
        $this->get('/painel/relatorio/acoes')->assertRedirect('/login');
    }

    public function test_actions_summary_counts_each_outcome_inside_the_date_range_and_excludes_outside(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);

        $openIn = $this->makeLead($center, 'Open In', 'open');
        $this->forceLeadDate($openIn, 'created_at', '2026-10-05 10:00:00');

        $openOut = $this->makeLead($center, 'Open Out', 'open');
        $this->forceLeadDate($openOut, 'created_at', '2026-09-15 10:00:00');

        $wonIn = $this->makeLead($center, 'Won In', 'won', ['won_at' => '2026-10-10 23:50:00']);
        $wonOut = $this->makeLead($center, 'Won Out', 'won', ['won_at' => '2026-10-15 10:00:00']);

        $lostIn = $this->makeLead($center, 'Lost In', 'lost', ['lost_at' => '2026-10-01 00:05:00']);
        $lostOut = $this->makeLead($center, 'Lost Out', 'lost', ['lost_at' => '2026-09-01 10:00:00']);

        $deletedIn = $this->makeLead($center, 'Deleted In', 'open');
        $deletedIn->delete();
        $this->forceLeadDate($deletedIn, 'deleted_at', '2026-10-07 12:00:00');

        $deletedOut = $this->makeLead($center, 'Deleted Out', 'open');
        $deletedOut->delete();
        $this->forceLeadDate($deletedOut, 'deleted_at', '2026-09-20 12:00:00');

        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->getJson('/painel/relatorio/acoes?from=2026-10-01&to=2026-10-10');

        $response->assertOk();
        $response->assertJson([
            'labels' => ['Em aberto', 'Comprou', 'Perdeu', 'Deletado/Ignorado'],
            'counts' => [1, 1, 1, 1],
        ]);
    }

    public function test_actions_summary_falls_back_gracefully_on_invalid_dates(): void
    {
        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($user)->getJson('/painel/relatorio/acoes?from=data-invalida&to=tambem-invalida');

        $response->assertOk();
        $response->assertJsonStructure(['labels', 'counts']);
        $this->assertCount(4, $response->json('counts'));
    }

    public function test_seller_only_sees_their_own_store_in_actions_summary(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);

        $centerLead = $this->makeLead($center, 'Cliente Center', 'open');
        $this->forceLeadDate($centerLead, 'created_at', '2026-10-05 10:00:00');

        $geniusLead = $this->makeLead($genius, 'Cliente Genius', 'open');
        $this->forceLeadDate($geniusLead, 'created_at', '2026-10-05 10:00:00');

        $seller = $this->makeUser('vendedor@center.com', 'senha-boa-123', 'seller', $center->id);

        $response = $this->actingAs($seller)->getJson('/painel/relatorio/acoes?from=2026-10-01&to=2026-10-10');

        $response->assertOk();
        $response->assertJson(['counts' => [1, 0, 0, 0]]);
    }

    public function test_owner_can_filter_actions_summary_by_store_param(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);

        $centerLead = $this->makeLead($center, 'Cliente Center', 'open');
        $this->forceLeadDate($centerLead, 'created_at', '2026-10-05 10:00:00');

        $geniusLead = $this->makeLead($genius, 'Cliente Genius', 'open');
        $this->forceLeadDate($geniusLead, 'created_at', '2026-10-05 10:00:00');

        $owner = $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->actingAs($owner)
            ->getJson("/painel/relatorio/acoes?from=2026-10-01&to=2026-10-10&store={$genius->id}");

        $response->assertOk();
        $response->assertJson(['counts' => [1, 0, 0, 0]]);
    }

    /** @param  array<string, mixed>  $extra */
    private function makeLead(Store $store, string $customerName, string $status, array $extra = []): Lead
    {
        static $counter = 0;
        $counter++;

        $customer = Customer::create(['name' => $customerName, 'phone' => '55349999'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT)]);

        return Lead::create(array_merge([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'source' => 'whatsapp',
            'status' => $status,
        ], $extra));
    }

    private function forceLeadDate(Lead $lead, string $column, string $value): void
    {
        // Contorna o SoftDeletingScope (que filtraria a linha do próprio UPDATE se já tiver
        // sido deletada) e evita timestamps automáticos do Eloquent sobrescreverem o valor.
        DB::table('leads')->where('id', $lead->id)->update([$column => $value]);
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
