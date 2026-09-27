<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\StalledConversationDetector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StalledConversationDetectorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private WhatsappAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $this->account = WhatsappAccount::create([
            'store_id' => $this->store->id,
            'label' => 'Conta',
            'provider' => 'web_extension',
        ]);
    }

    public function test_detects_customer_left_unanswered(): void
    {
        $this->chat('5534999990001', [['in', -48]]);

        $result = $this->detector()->detect();

        $this->assertSame(1, $result['leads_created']);
        $this->assertSame(1, $result['followups_created']);

        $lead = Lead::sole();
        $this->assertSame('open', $lead->status);
        $this->assertSame('5534999990001', $lead->customer->phone);

        $followup = LeadFollowup::sole();
        $this->assertSame(StalledConversationDetector::REASON_CUSTOMER_UNANSWERED, $followup->reason);
        $this->assertSame('candidate', $followup->status);
    }

    public function test_detects_customer_gone_silent_after_we_replied(): void
    {
        $this->chat('5534999990002', [['in', -96], ['out', -95]]);

        $this->detector()->detect();

        $this->assertSame(StalledConversationDetector::REASON_CUSTOMER_SILENT, LeadFollowup::sole()->reason);
    }

    public function test_ignores_group_chats(): void
    {
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $this->account->id,
            'chat_key' => 'grupo_123',
            'jid' => '123-456@g.us',
            'kind' => 'group',
        ]);
        $this->message($chat, 'in', -48, 'g1');
        $chat->update(['last_message_at' => CarbonImmutable::now()->subHours(48)]);

        $this->detector()->detect();

        $this->assertSame(0, Lead::count());
    }

    public function test_ignores_chats_that_are_too_recent(): void
    {
        $this->chat('5534999990003', [['in', -2]]);

        $this->detector()->detect();

        $this->assertSame(0, Lead::count());
    }

    public function test_ignores_chats_older_than_the_configured_max_age(): void
    {
        $this->chat('5534999990004', [['in', -24 * 45]]);

        $this->detector()->detect();

        $this->assertSame(0, Lead::count());
    }

    public function test_ignores_chat_with_no_customer_message(): void
    {
        $this->chat('5534999990005', [['out', -48]]);

        $this->detector()->detect();

        $this->assertSame(0, Lead::count());
    }

    public function test_running_twice_does_not_duplicate_lead_or_followup(): void
    {
        $this->chat('5534999990006', [['in', -48]]);
        $detector = $this->detector();

        $detector->detect();
        $result = $detector->detect();

        $this->assertSame(0, $result['leads_created']);
        $this->assertSame(0, $result['followups_created']);
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, LeadFollowup::count());
    }

    public function test_dismissed_followup_respects_cooldown_before_creating_a_new_one(): void
    {
        $this->chat('5534999990007', [['in', -48]]);
        $this->detector()->detect();

        LeadFollowup::sole()->update(['status' => 'dismissed', 'dismissed_at' => CarbonImmutable::now()]);

        $result = $this->detector()->detect();

        $this->assertSame(0, $result['followups_created']);
        $this->assertSame(1, LeadFollowup::count());
    }

    public function test_only_covers_the_given_store(): void
    {
        $other = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $otherAccount = WhatsappAccount::create(['store_id' => $other->id, 'label' => 'Outra', 'provider' => 'web_extension']);

        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $otherAccount->id,
            'chat_key' => '5534999990008',
            'jid' => '5534999990008@c.us',
            'kind' => 'individual',
            'phone' => '5534999990008',
        ]);
        $this->message($chat, 'in', -48, 'x1');
        $chat->update(['last_message_at' => CarbonImmutable::now()->subHours(48)]);

        $this->detector()->detect($this->store->id);

        $this->assertSame(0, Lead::count());
    }

    public function test_keeps_a_soft_deleted_customer_reachable_instead_of_duplicating(): void
    {
        $customer = Customer::create(['phone' => '5534999990009', 'name' => 'Zeca']);
        $customer->delete();

        $this->chat('5534999990009', [['in', -48]]);
        $this->detector()->detect();

        $this->assertSame(1, Customer::count());
        $this->assertNull(Customer::first()->deleted_at);
    }

    public function test_does_not_suggest_followup_when_customer_only_said_goodbye(): void
    {
        $this->chat('5534999990010', [
            ['in', -50, 'Quanto custa a tela do iPhone 11?'],
            ['out', -49, 'Fica R$ 150 com instalação'],
            ['in', -48, 'Obrigado!'],
        ]);

        $result = $this->detector()->detect();

        $this->assertSame(0, $result['followups_created']);
        $this->assertSame(1, Lead::count());
        $this->assertSame(0, LeadFollowup::count());
        $this->assertNotNull(Lead::sole()->last_contact_at);
    }

    public function test_still_suggests_followup_for_a_real_unanswered_question(): void
    {
        $this->chat('5534999990011', [['in', -48, 'Vocês têm tela de iPhone 11?']]);

        $result = $this->detector()->detect();

        $this->assertSame(1, $result['followups_created']);
    }

    public function test_stops_suggesting_after_reaching_the_configured_attempt_limit(): void
    {
        // Sem cooldown, para isolar só o efeito do teto de tentativas.
        config(['whatsapp.followup.max_attempts' => 2, 'whatsapp.followup.cooldown_days' => 0]);
        $chat = $this->chat('5534999990012', [['in', -48]]);
        $detector = $this->detector();

        // 1ª tentativa: cria. Descarta, e "reabre" a conversa para a próxima rodada.
        $detector->detect();
        $this->dismiss(LeadFollowup::sole());
        $chat->update(['last_message_at' => now()->subHours(48)]);

        // 2ª tentativa: ainda dentro do teto (1 < 2), cria de novo.
        $detector->detect();
        $this->assertSame(2, LeadFollowup::count());
        $this->dismiss(LeadFollowup::where('status', 'candidate')->sole());
        $chat->update(['last_message_at' => now()->subHours(48)]);

        // 3ª tentativa: já bateu o teto (2 >= 2), não cria mais.
        $result = $detector->detect();

        $this->assertSame(0, $result['followups_created']);
        $this->assertSame(2, LeadFollowup::count());
    }

    public function test_allows_more_attempts_when_the_limit_is_raised(): void
    {
        config(['whatsapp.followup.max_attempts' => 1, 'whatsapp.followup.cooldown_days' => 0]);
        $chat = $this->chat('5534999990013', [['in', -48]]);
        $detector = $this->detector();

        $detector->detect();
        $this->assertSame(1, LeadFollowup::count());
        $this->dismiss(LeadFollowup::sole());
        $chat->update(['last_message_at' => now()->subHours(48)]);

        // Já bateu o teto de 1; sobe o teto e tenta de novo.
        config(['whatsapp.followup.max_attempts' => 2]);
        $result = $detector->detect();

        $this->assertSame(1, $result['followups_created']);
        $this->assertSame(2, LeadFollowup::count());
    }

    /**
     * Descarta e marca `updated_at` claramente no passado (via query builder, sem o
     * "touch" automático do Eloquent), para o cooldown não ficar ambíguo por causa do
     * arredondamento de precisão do timestamp(0) do Postgres quando cooldown_days = 0.
     */
    private function dismiss(LeadFollowup $followup): void
    {
        DB::table('lead_followups')->where('id', $followup->id)->update([
            'status' => 'dismissed',
            'dismissed_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    private function detector(): StalledConversationDetector
    {
        return app(StalledConversationDetector::class);
    }

    /** @param  array<int, array{0: string, 1: int}|array{0: string, 1: int, 2: string}>  $messages */
    private function chat(string $phone, array $messages): WhatsappChat
    {
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $this->account->id,
            'chat_key' => $phone,
            'jid' => "{$phone}@c.us",
            'kind' => 'individual',
            'phone' => $phone,
            'display_name' => 'Cliente Teste',
        ]);

        $lastAt = null;

        foreach ($messages as $i => $message) {
            [$direction, $hoursAgo] = $message;
            $lastAt = $this->message($chat, $direction, $hoursAgo, "{$phone}-{$i}", $message[2] ?? 'oi');
        }

        $chat->update(['last_message_at' => $lastAt]);

        return $chat;
    }

    private function message(WhatsappChat $chat, string $direction, int $hoursAgo, string $externalId, string $body = 'oi'): CarbonImmutable
    {
        $sentAt = CarbonImmutable::now()->addHours($hoursAgo);

        WhatsappMessage::create([
            'whatsapp_account_id' => $chat->whatsapp_account_id,
            'whatsapp_chat_id' => $chat->id,
            'external_id' => $externalId,
            'direction' => $direction,
            'sender_name' => $direction === 'in' ? 'Cliente Teste' : null,
            'type' => 'chat',
            'body' => $body,
            'sent_at' => $sentAt,
            'source' => 'web_extension',
        ]);

        return $sentAt;
    }
}
