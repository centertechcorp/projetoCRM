<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Services\Leads\FollowupGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowupGateTest extends TestCase
{
    use RefreshDatabase;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $customer = Customer::create(['phone' => '5534999990001', 'name' => 'Cliente']);
        $this->lead = Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open']);
    }

    public function test_creates_a_candidate_when_nothing_blocks_it(): void
    {
        $this->assertTrue($this->gate()->createIfAllowed($this->lead, 'recover', 'sumiu', 3, 2));

        $followup = LeadFollowup::sole();
        $this->assertSame('candidate', $followup->status);
        $this->assertSame('recover', $followup->type);
        $this->assertSame('rules', $followup->suggested_by);
    }

    public function test_does_not_create_while_an_open_suggestion_exists(): void
    {
        foreach (FollowupGate::OPEN_STATUSES as $status) {
            LeadFollowup::query()->delete();
            $this->followup($status, now()->subDays(30));

            $this->assertFalse($this->gate()->createIfAllowed($this->lead, 'recover', 'x', 3, 5), "status {$status}");
            $this->assertSame(1, LeadFollowup::count());
        }
    }

    public function test_cooldown_blocks_after_a_recent_dismissed_or_sent_suggestion(): void
    {
        foreach (FollowupGate::CLOSED_STATUSES as $status) {
            LeadFollowup::query()->delete();
            $this->followup($status, now()->subDay());

            $this->assertFalse($this->gate()->createIfAllowed($this->lead, 'recover', 'x', 3, 5), "status {$status}");
        }
    }

    public function test_allows_again_after_the_cooldown_has_passed(): void
    {
        $this->followup('dismissed', now()->subDays(4));

        $this->assertTrue($this->gate()->createIfAllowed($this->lead, 'recover', 'x', 3, 5));
        $this->assertSame(2, LeadFollowup::count());
    }

    public function test_stops_for_good_at_the_attempt_ceiling_even_after_cooldown(): void
    {
        $this->followup('dismissed', now()->subDays(30));
        $this->followup('sent', now()->subDays(20));

        $this->assertFalse($this->gate()->createIfAllowed($this->lead, 'recover', 'x', 3, 2));
        $this->assertSame(2, LeadFollowup::count());
    }

    public function test_attempts_are_counted_per_type(): void
    {
        $this->followup('dismissed', now()->subDays(30), 'recover');

        $this->assertTrue($this->gate()->createIfAllowed($this->lead, 'upsell', 'x', 3, 1));
    }

    private function gate(): FollowupGate
    {
        return new FollowupGate;
    }

    private function followup(string $status, \DateTimeInterface $updatedAt, string $type = 'recover'): LeadFollowup
    {
        $followup = LeadFollowup::create([
            'lead_id' => $this->lead->id, 'type' => $type, 'reason' => 'x',
            'status' => $status, 'suggested_by' => 'rules',
        ]);
        $followup->forceFill(['updated_at' => $updatedAt])->saveQuietly();

        return $followup;
    }
}
