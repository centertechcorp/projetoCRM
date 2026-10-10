<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Services\Leads\LeadDecisionService;
use App\Services\Leads\LostLeadReconnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
    }

    public function test_approving_a_regular_followup_does_not_touch_an_open_leads_status(): void
    {
        $lead = $this->lead('open');
        $followup = $this->followup($lead, 'recover', 'customer_unanswered');
        $user = $this->user();

        $this->service()->approveFollowup($followup, $user);

        $followup->refresh();
        $this->assertSame('approved', $followup->status);
        $this->assertSame($user->id, $followup->approved_by);
        $this->assertNotNull($followup->approved_at);
        $this->assertSame('open', $lead->fresh()->status);
    }

    public function test_approving_a_lost_recovery_followup_reopens_the_lead(): void
    {
        $lead = $this->lead('lost', ['lost_at' => now(), 'lost_reason' => 'sumiu']);
        $followup = $this->followup($lead, 'recover', LostLeadReconnector::REASON_LOST_RECOVERY);

        $this->service()->approveFollowup($followup, $this->user());

        $lead->refresh();
        $this->assertSame('open', $lead->status);
        $this->assertNull($lead->lost_at);
        $this->assertNull($lead->lost_reason);
    }

    public function test_approving_a_lost_recovery_followup_on_an_already_open_lead_is_a_no_op_for_the_lead(): void
    {
        // Followup com o motivo de reconexão de perdido, mas o lead já está aberto de novo
        // por outro caminho — não deveria "reabrir" algo que já está aberto nem quebrar.
        $lead = $this->lead('open');
        $followup = $this->followup($lead, 'recover', LostLeadReconnector::REASON_LOST_RECOVERY);

        $this->service()->approveFollowup($followup, $this->user());

        $this->assertSame('open', $lead->fresh()->status);
    }

    public function test_dismiss_only_closes_the_followup_not_the_lead(): void
    {
        $lead = $this->lead('open');
        $followup = $this->followup($lead, 'recover', 'customer_unanswered');

        $this->service()->dismissFollowup($followup);

        $followup->refresh();
        $this->assertSame('dismissed', $followup->status);
        $this->assertNotNull($followup->dismissed_at);
        $this->assertSame('open', $lead->fresh()->status);
    }

    public function test_unapprove_goes_back_to_draft_ready_when_there_is_a_suggested_message(): void
    {
        $lead = $this->lead('open');
        $followup = $this->followup($lead, 'recover', 'customer_unanswered', [
            'status' => 'approved', 'suggested_message' => 'Oi! Ainda tem interesse?',
            'approved_by' => $this->user()->id, 'approved_at' => now(),
        ]);

        $this->service()->unapproveFollowup($followup);

        $followup->refresh();
        $this->assertSame('draft_ready', $followup->status);
        $this->assertNull($followup->approved_by);
        $this->assertNull($followup->approved_at);
    }

    public function test_unapprove_goes_back_to_candidate_when_there_is_no_suggested_message(): void
    {
        $lead = $this->lead('open');
        $followup = $this->followup($lead, 'recover', 'customer_unanswered', [
            'status' => 'approved', 'suggested_message' => null,
            'approved_by' => $this->user()->id, 'approved_at' => now(),
        ]);

        $this->service()->unapproveFollowup($followup);

        $this->assertSame('candidate', $followup->fresh()->status);
    }

    public function test_closing_as_won_sets_archive_after_using_the_configured_retention(): void
    {
        config(['whatsapp.followup.retention_days' => 30]);
        $lead = $this->lead('open');

        $this->service()->closeLeadWon($lead);

        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertNotNull($lead->won_at);
        $this->assertTrue($lead->archive_after->isSameDay(CarbonImmutable::now()->addDays(30)));
    }

    public function test_closing_as_lost_stores_the_given_reason(): void
    {
        $lead = $this->lead('open');

        $this->service()->closeLeadLost($lead, 'não respondeu mais');

        $lead->refresh();
        $this->assertSame('lost', $lead->status);
        $this->assertSame('não respondeu mais', $lead->lost_reason);
        $this->assertNotNull($lead->lost_at);
    }

    public function test_closing_as_lost_accepts_a_null_reason(): void
    {
        $lead = $this->lead('open');

        $this->service()->closeLeadLost($lead, null);

        $this->assertNull($lead->fresh()->lost_reason);
    }

    public function test_deleting_permanently_ignores_the_chat_and_soft_deletes_the_lead(): void
    {
        $account = WhatsappAccount::create(['store_id' => $this->store->id, 'label' => 'Conta', 'provider' => 'web_extension']);
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $account->id, 'chat_key' => '5534900000000',
            'jid' => '5534900000000@c.us', 'kind' => 'individual', 'phone' => '5534900000000',
        ]);
        $lead = $this->lead('open', ['whatsapp_chat_id' => $chat->id]);

        $this->service()->deletePermanently($lead);

        $this->assertTrue($chat->fresh()->ignored);
        $this->assertSoftDeleted($lead);
        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Lead::withTrashed()->count());
    }

    public function test_deleting_permanently_a_lead_without_a_chat_does_not_throw(): void
    {
        $lead = $this->lead('open');

        $this->service()->deletePermanently($lead);

        $this->assertSoftDeleted($lead);
    }

    public function test_a_soft_deleted_lead_does_not_block_a_brand_new_lead_for_the_same_customer_and_store(): void
    {
        $customer = Customer::create(['phone' => '5534900000099', 'name' => 'Cliente Teste']);
        $old = Lead::create(['customer_id' => $customer->id, 'store_id' => $this->store->id, 'status' => 'open']);
        $this->service()->deletePermanently($old);

        // O cliente volta a escrever depois, sem ligação com o chat antigo (ignorado) — um
        // lead novo pra ele na mesma loja precisa poder ser criado, não travar na constraint
        // única de "um lead aberto por cliente+loja" (que já exclui soft-deleted).
        $new = Lead::create(['customer_id' => $customer->id, 'store_id' => $this->store->id, 'status' => 'open']);

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(1, Lead::count());
        $this->assertSame(2, Lead::withTrashed()->count());
    }

    private function service(): LeadDecisionService
    {
        return app(LeadDecisionService::class);
    }

    private function user(): User
    {
        return User::create(['name' => 'Teste', 'email' => 'teste-decision@center.com', 'password' => 'x', 'role' => 'owner', 'can_decide' => true]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function lead(string $status, array $attributes = []): Lead
    {
        $customer = Customer::create(['phone' => '5534999'.random_int(100000, 999999), 'name' => 'Cliente Teste']);

        return Lead::create(array_merge([
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'status' => $status,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    private function followup(Lead $lead, string $type, string $reason, array $attributes = []): LeadFollowup
    {
        return LeadFollowup::create(array_merge([
            'lead_id' => $lead->id,
            'type' => $type,
            'reason' => $reason,
            'status' => 'candidate',
            'suggested_by' => 'rules',
        ], $attributes));
    }
}
