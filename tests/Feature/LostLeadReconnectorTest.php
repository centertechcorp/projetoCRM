<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Services\Leads\LostLeadReconnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LostLeadReconnectorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        config(['whatsapp.followup.lost_reconnect_after_days' => 5]);
    }

    public function test_suggests_reconnection_after_the_configured_days(): void
    {
        $lead = $this->lostLead('5534999990001', now()->subDays(6));

        $result = $this->reconnector()->reconnect();

        $this->assertSame(1, $result['leads_reconnected']);
        $followup = LeadFollowup::sole();
        $this->assertSame($lead->id, $followup->lead_id);
        $this->assertSame('recover', $followup->type);
        $this->assertSame(LostLeadReconnector::REASON_LOST_RECOVERY, $followup->reason);
        $this->assertSame('candidate', $followup->status);
    }

    public function test_ignores_a_lead_lost_too_recently(): void
    {
        $this->lostLead('5534999990002', now()->subDays(2));

        $result = $this->reconnector()->reconnect();

        $this->assertSame(0, $result['leads_reconnected']);
        $this->assertSame(0, LeadFollowup::count());
    }

    public function test_reconnects_even_when_the_customer_explicitly_declined(): void
    {
        $customer = Customer::create(['phone' => '5534999990003', 'name' => 'Cliente']);
        Lead::create([
            'customer_id' => $customer->id, 'store_id' => $this->store->id, 'status' => 'lost',
            'lost_at' => now()->subDays(10), 'lost_reason' => 'não quero nada, obrigado',
        ]);

        $result = $this->reconnector()->reconnect();

        $this->assertSame(1, $result['leads_reconnected']);
    }

    public function test_ignores_leads_that_are_not_lost(): void
    {
        $customer = Customer::create(['phone' => '5534999990004', 'name' => 'Cliente']);
        Lead::create(['customer_id' => $customer->id, 'store_id' => $this->store->id, 'status' => 'open']);

        $result = $this->reconnector()->reconnect();

        $this->assertSame(0, $result['leads_reconnected']);
    }

    public function test_only_covers_the_given_store(): void
    {
        $other = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $customer = Customer::create(['phone' => '5534999990005', 'name' => 'Cliente']);
        Lead::create([
            'customer_id' => $customer->id, 'store_id' => $other->id, 'status' => 'lost', 'lost_at' => now()->subDays(10),
        ]);

        $result = $this->reconnector()->reconnect($this->store->id);

        $this->assertSame(0, $result['leads_reconnected']);
    }

    public function test_respects_the_shared_attempt_limit_with_the_stalled_conversation_reasons(): void
    {
        config(['whatsapp.followup.max_attempts' => 1]);
        $lead = $this->lostLead('5534999990006', now()->subDays(10));

        // Já usou a única tentativa permitida por outro motivo qualquer.
        LeadFollowup::create([
            'lead_id' => $lead->id, 'type' => 'recover', 'reason' => 'customer_unanswered',
            'status' => 'dismissed', 'suggested_by' => 'rules',
        ]);

        $result = $this->reconnector()->reconnect();

        $this->assertSame(0, $result['leads_reconnected']);
    }

    public function test_does_not_duplicate_when_run_twice(): void
    {
        $this->lostLead('5534999990007', now()->subDays(10));
        $reconnector = $this->reconnector();

        $reconnector->reconnect();
        $result = $reconnector->reconnect();

        $this->assertSame(0, $result['leads_reconnected']);
        $this->assertSame(1, LeadFollowup::count());
    }

    private function reconnector(): LostLeadReconnector
    {
        return app(LostLeadReconnector::class);
    }

    private function lostLead(string $phone, \DateTimeInterface $lostAt): Lead
    {
        $customer = Customer::create(['phone' => $phone, 'name' => 'Cliente Teste']);

        return Lead::create([
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'status' => 'lost',
            'lost_at' => $lostAt,
        ]);
    }
}
