<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PainelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/painel')->assertRedirect('/login');
    }

    public function test_login_with_correct_credentials_reaches_the_panel(): void
    {
        $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->post('/login', ['email' => 'owner@center.com', 'password' => 'senha-boa-123']);

        $response->assertRedirect(route('painel.index'));
        $this->assertAuthenticated();
    }

    public function test_login_with_wrong_password_fails_and_does_not_authenticate(): void
    {
        $this->makeUser('owner@center.com', 'senha-boa-123');

        $response = $this->post('/login', ['email' => 'owner@center.com', 'password' => 'errada']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->makeUser('owner@center.com', 'senha-boa-123');

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_seller_only_sees_leads_from_their_own_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $seller = $this->makeUser('vendedora@center.com', 'senha-boa-123', 'seller', $center->id);

        $this->makeLead($center, 'Cliente Center', '5534999990001');
        $this->makeLead($genius, 'Cliente Genius', '5534999990002');

        $response = $this->actingAs($seller)->get('/painel?tab=all');

        $response->assertSee('Cliente Center');
        $response->assertDontSee('Cliente Genius');
    }

    public function test_manager_sees_leads_from_every_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $manager = $this->makeUser('gestor@center.com', 'senha-boa-123', 'manager');

        $this->makeLead($center, 'Cliente Center', '5534999990003');
        $this->makeLead($genius, 'Cliente Genius', '5534999990004');

        $response = $this->actingAs($manager)->get('/painel?tab=all');

        $response->assertSee('Cliente Center');
        $response->assertSee('Cliente Genius');
    }

    public function test_seller_cannot_act_on_a_lead_from_another_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $seller = $this->makeUser('vendedora@center.com', 'senha-boa-123', 'seller', $center->id);
        $lead = $this->makeLead($genius, 'Cliente Genius', '5534999990005');

        $this->actingAs($seller)->post(route('painel.leads.won', $lead))->assertForbidden();
        $this->assertSame('open', $lead->fresh()->status);
    }

    public function test_user_without_can_decide_does_not_see_action_buttons(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('vendedor@center.com', 'senha-boa-123', 'manager', null, canDecide: false);
        $lead = $this->makeLead($store, 'Cliente', '5534999990030');

        $response = $this->actingAs($user)->get('/painel?tab=ongoing');

        // Checa a URL do form, não a palavra solta — "Aprovar"/"Comprou" também aparecem
        // no texto explicativo "Como funciona", que é visível pra todo mundo.
        $response->assertDontSee(route('painel.leads.won', $lead));
        $response->assertDontSee(route('painel.leads.lost', $lead));
    }

    public function test_user_without_can_decide_is_forbidden_from_every_decision(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('vendedor@center.com', 'senha-boa-123', 'manager', null, canDecide: false);
        $lead = $this->makeLead($store, 'Cliente', '5534999990031');
        $followup = LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'recover', 'reason' => 'customer_unanswered',
            'status' => 'candidate', 'suggested_by' => 'rules',
        ]);

        $this->actingAs($user)->post(route('painel.followups.approve', $followup))->assertForbidden();
        $this->actingAs($user)->post(route('painel.followups.dismiss', $followup))->assertForbidden();
        $this->actingAs($user)->post(route('painel.leads.won', $lead))->assertForbidden();
        $this->actingAs($user)->post(route('painel.leads.lost', $lead))->assertForbidden();

        $this->assertSame('candidate', $followup->fresh()->status);
        $this->assertSame('open', $lead->fresh()->status);
    }

    public function test_seller_with_can_decide_still_cannot_act_outside_their_store(): void
    {
        $center = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $genius = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $seller = $this->makeUser('vendedora@center.com', 'senha-boa-123', 'seller', $center->id, canDecide: true);
        $lead = $this->makeLead($genius, 'Cliente Genius', '5534999990032');

        $this->actingAs($seller)->post(route('painel.leads.won', $lead))->assertForbidden();
    }

    public function test_approving_a_followup_records_who_approved_and_nothing_is_sent(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990006');
        $followup = LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'recover', 'reason' => 'customer_unanswered',
            'status' => 'candidate', 'suggested_by' => 'rules', 'suggested_message' => 'Oi, tudo bem?',
        ]);

        $response = $this->actingAs($user)->post(route('painel.followups.approve', $followup));

        $response->assertRedirect();
        $followup->refresh();
        $this->assertSame('approved', $followup->status);
        $this->assertSame($user->id, $followup->approved_by);
    }

    public function test_approving_a_lost_recovery_followup_reopens_the_lead(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $customer = Customer::create(['phone' => '5534999990020', 'name' => 'Cliente']);
        $lead = Lead::create([
            'customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'lost',
            'lost_at' => now()->subDays(10), 'lost_reason' => 'não quero nada',
        ]);
        $followup = LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'recover', 'reason' => 'lost_recovery',
            'status' => 'candidate', 'suggested_by' => 'rules',
        ]);

        $this->actingAs($user)->post(route('painel.followups.approve', $followup));

        $lead->refresh();
        $this->assertSame('open', $lead->status);
        $this->assertNull($lead->lost_at);
        $this->assertNull($lead->lost_reason);
    }

    public function test_approving_a_normal_followup_does_not_change_the_lead_status(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990021');
        $followup = LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'recover', 'reason' => 'customer_unanswered',
            'status' => 'candidate', 'suggested_by' => 'rules',
        ]);

        $this->actingAs($user)->post(route('painel.followups.approve', $followup));

        $this->assertSame('open', $lead->fresh()->status);
    }

    public function test_approving_an_upsell_followup_does_not_reopen_a_won_lead(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $customer = Customer::create(['phone' => '5534999990022', 'name' => 'Cliente']);
        $lead = Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'won', 'won_at' => now()]);
        $followup = LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'upsell', 'reason' => 'purchase',
            'status' => 'candidate', 'suggested_by' => 'rules',
        ]);

        $this->actingAs($user)->post(route('painel.followups.approve', $followup));

        $this->assertSame('won', $lead->fresh()->status);
    }

    public function test_dismissing_a_followup_marks_it_dismissed(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990007');
        $followup = LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'recover', 'reason' => 'customer_unanswered',
            'status' => 'candidate', 'suggested_by' => 'rules',
        ]);

        $this->actingAs($user)->post(route('painel.followups.dismiss', $followup));

        $this->assertSame('dismissed', $followup->fresh()->status);
    }

    public function test_marking_a_lead_as_won_moves_it_out_of_the_ongoing_tab(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990008');

        $ongoingBefore = $this->actingAs($user)->get('/painel?tab=ongoing');
        $ongoingBefore->assertSee('5534999990008');

        $this->actingAs($user)->post(route('painel.leads.won', $lead));

        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertNotNull($lead->archive_after);

        $ongoingAfter = $this->actingAs($user)->get('/painel?tab=ongoing');
        $ongoingAfter->assertDontSee('5534999990008');

        $wonResponse = $this->actingAs($user)->get('/painel?tab=won');
        $wonResponse->assertSee('5534999990008');
    }

    public function test_lost_lead_only_appears_in_the_lost_tab(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990009');

        $this->actingAs($user)->post(route('painel.leads.lost', $lead), ['reason' => 'sem retorno']);

        $lead->refresh();
        $this->assertSame('lost', $lead->status);
        $this->assertSame('sem retorno', $lead->lost_reason);

        $this->actingAs($user)->get('/painel?tab=ongoing')->assertDontSee('5534999990009');
        $this->actingAs($user)->get('/painel?tab=lost')->assertSee('5534999990009');
    }

    public function test_ongoing_and_reconnect_tabs_split_open_leads_by_pending_followup(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');

        $freshLead = $this->makeLead($store, 'Cliente Fresco', '5534999990010');

        $staleLead = $this->makeLead($store, 'Cliente Parado', '5534999990011');
        LeadFollowup::create([
            'lead_id' => $staleLead->id, 'type' => 'recover', 'reason' => 'customer_unanswered',
            'status' => 'candidate', 'suggested_by' => 'rules',
        ]);

        $ongoing = $this->actingAs($user)->get('/painel?tab=ongoing');
        $ongoing->assertSee('5534999990010');
        $ongoing->assertDontSee('5534999990011');

        $reconnect = $this->actingAs($user)->get('/painel?tab=reconnect');
        $reconnect->assertSee('5534999990011');
        $reconnect->assertDontSee('5534999990010');
    }

    public function test_a_dismissed_followup_moves_the_lead_back_to_ongoing(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990012');
        $followup = LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'recover', 'reason' => 'customer_unanswered',
            'status' => 'candidate', 'suggested_by' => 'rules',
        ]);

        $this->actingAs($user)->post(route('painel.followups.dismiss', $followup));

        $this->actingAs($user)->get('/painel?tab=reconnect')->assertDontSee('5534999990012');
        $this->actingAs($user)->get('/painel?tab=ongoing')->assertSee('5534999990012');
    }

    public function test_deleting_a_lead_ignores_its_chat_and_removes_it_from_every_tab(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $account = WhatsappAccount::create(['store_id' => $store->id, 'label' => 'Conta', 'provider' => 'web_extension']);
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $account->id, 'chat_key' => '5534999990013',
            'jid' => '5534999990013@c.us', 'kind' => 'individual', 'phone' => '5534999990013',
        ]);
        $customer = Customer::create(['phone' => '5534999990013', 'name' => 'Fornecedor']);
        $lead = Lead::create([
            'customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open',
            'whatsapp_chat_id' => $chat->id,
        ]);

        $response = $this->actingAs($user)->postJson(route('painel.leads.delete', $lead));

        $response->assertOk()->assertJson(['deleted' => true]);
        $this->assertTrue($chat->fresh()->ignored);
        $this->assertNotNull($lead->fresh()->deleted_at);
        $this->assertSame(0, Lead::count());
        $this->actingAs($user)->get('/painel?tab=all')->assertDontSee('5534999990013');
    }

    public function test_deleting_a_lead_without_a_linked_chat_does_not_error(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990014');

        $response = $this->actingAs($user)->postJson(route('painel.leads.delete', $lead));

        $response->assertOk()->assertJson(['deleted' => true]);
        $this->assertNotNull($lead->fresh()->deleted_at);
    }

    private function makeUser(string $email, string $password, string $role = 'owner', ?int $storeId = null, bool $canDecide = true): User
    {
        return User::create([
            'name' => 'Teste',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'owner_slot' => $role === 'owner' ? 1 : null,
            'store_id' => $storeId,
            'can_decide' => $canDecide,
        ]);
    }

    private function makeLead(Store $store, string $name, string $phone): Lead
    {
        $customer = Customer::create(['phone' => $phone, 'name' => $name]);

        return Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open']);
    }
}
