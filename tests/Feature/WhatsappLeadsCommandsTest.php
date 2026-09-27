<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappLeadsCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_close_won_sets_archive_after_from_retention_config(): void
    {
        config(['whatsapp.followup.retention_days' => 10]);
        $lead = $this->makeLead();

        $this->artisan('whatsapp:leads:close', ['id' => $lead->id, 'outcome' => 'won'])->assertSuccessful();

        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertNotNull($lead->won_at);
        $this->assertSame(
            $lead->won_at->addDays(10)->toDateString(),
            $lead->archive_after->toDateString(),
        );
    }

    public function test_close_lost_records_the_reason(): void
    {
        $lead = $this->makeLead();

        $this->artisan('whatsapp:leads:close', ['id' => $lead->id, 'outcome' => 'lost', '--reason' => 'preço'])
            ->assertSuccessful();

        $lead->refresh();
        $this->assertSame('lost', $lead->status);
        $this->assertSame('preço', $lead->lost_reason);
    }

    public function test_close_rejects_invalid_outcome_and_missing_lead(): void
    {
        $lead = $this->makeLead();

        $this->artisan('whatsapp:leads:close', ['id' => $lead->id, 'outcome' => 'x'])->assertFailed();
        $this->artisan('whatsapp:leads:close', ['id' => 999999, 'outcome' => 'won'])->assertFailed();
    }

    public function test_archive_only_moves_won_leads_past_the_deadline_and_deletes_nothing(): void
    {
        $due = $this->makeLead(phone: '5534999991001');
        $due->update(['status' => 'won', 'archive_after' => now()->subDay()]);

        $notYet = $this->makeLead(phone: '5534999991002');
        $notYet->update(['status' => 'won', 'archive_after' => now()->addDay()]);

        $this->artisan('whatsapp:leads:archive')->assertSuccessful();

        $this->assertNotNull($due->refresh()->archived_at);
        $this->assertNull($notYet->refresh()->archived_at);
        $this->assertSame(2, Lead::count());
    }

    public function test_followup_approve_requires_a_user_and_records_who_approved(): void
    {
        $followup = $this->makeFollowup();
        $user = User::create(['name' => 'Caio', 'role' => 'owner', 'owner_slot' => 1]);

        $this->artisan('whatsapp:followups:review', ['id' => $followup->id, 'decision' => 'approve'])->assertFailed();

        $this->artisan('whatsapp:followups:review', [
            'id' => $followup->id, 'decision' => 'approve', '--user' => $user->id,
        ])->assertSuccessful();

        $followup->refresh();
        $this->assertSame('approved', $followup->status);
        $this->assertSame($user->id, $followup->approved_by);
        $this->assertNotNull($followup->approved_at);
    }

    public function test_followup_dismiss_does_not_require_a_user(): void
    {
        $followup = $this->makeFollowup();

        $this->artisan('whatsapp:followups:review', ['id' => $followup->id, 'decision' => 'dismiss'])->assertSuccessful();

        $this->assertSame('dismissed', $followup->refresh()->status);
    }

    private function makeLead(string $phone = '5534999990000'): Lead
    {
        $store = Store::firstOrCreate(['code' => 'CENTER'], ['name' => 'CENTER']);
        $customer = Customer::create(['phone' => $phone, 'name' => 'Cliente Teste']);

        return Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open']);
    }

    private function makeFollowup(): LeadFollowup
    {
        return LeadFollowup::create([
            'lead_id' => $this->makeLead()->id,
            'type' => 'recover',
            'reason' => 'customer_unanswered',
            'status' => 'candidate',
            'suggested_by' => 'rules',
        ]);
    }
}
