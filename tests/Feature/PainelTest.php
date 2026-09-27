<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Models\User;
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

        $response = $this->actingAs($seller)->get('/painel');

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

        $response = $this->actingAs($manager)->get('/painel');

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

    public function test_marking_a_lead_as_won_moves_it_out_of_the_open_tab(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = $this->makeUser('owner@center.com', 'senha-boa-123', 'owner');
        $lead = $this->makeLead($store, 'Cliente', '5534999990008');

        $this->actingAs($user)->post(route('painel.leads.won', $lead));

        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertNotNull($lead->archive_after);

        $openResponse = $this->actingAs($user)->get('/painel?tab=open');
        $openResponse->assertDontSee('5534999990008');

        $wonResponse = $this->actingAs($user)->get('/painel?tab=won');
        $wonResponse->assertSee('5534999990008');
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
        ]);
    }

    private function makeLead(Store $store, string $name, string $phone): Lead
    {
        $customer = Customer::create(['phone' => $phone, 'name' => $name]);

        return Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open']);
    }
}
