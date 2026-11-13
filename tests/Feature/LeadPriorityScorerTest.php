<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use App\Services\Leads\LeadPriorityScorer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeadPriorityScorerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private WhatsappAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $this->account = WhatsappAccount::create([
            'store_id' => $this->store->id, 'label' => 'Conta', 'provider' => 'web_extension',
        ]);
    }

    public function test_lead_with_no_chat_and_no_quote_scores_zero_and_is_baixa(): void
    {
        $lead = $this->lead();

        $scorer = $this->scorer();

        $this->assertSame(0, $scorer->score($lead));
        $this->assertSame('Baixa', $scorer->label(0));
        $this->assertNull($scorer->reason($lead));
    }

    public function test_a_quoted_amount_alone_is_worth_30_points(): void
    {
        $lead = $this->lead(['quoted_amount' => 1500]);

        $this->assertSame(30, $this->scorer()->score($lead));
        $this->assertSame('Média', $this->scorer()->label(30));
        $this->assertSame('Já recebeu orçamento', $this->scorer()->reason($lead));
    }

    public function test_a_closing_question_is_worth_25_points_and_takes_priority_as_the_reason(): void
    {
        $lead = $this->leadWithMessages(['Quanto fica no boleto?']);

        $score = $this->scorer()->score($lead);

        // 25 (fechamento) + 3 (1 msg) + 5 (1 "?") = 33
        $this->assertSame(33, $score);
        $this->assertStringContainsString('fechamento', $this->scorer()->reason($lead));
    }

    public function test_score_never_goes_above_100_even_stacking_every_signal(): void
    {
        $messages = array_fill(0, 10, 'Quanto custa a tela do iphone? Tem parcelamento no cartao? Qual a garantia?');
        $lead = $this->leadWithMessages($messages, ['quoted_amount' => 999]);

        $this->assertSame(100, $this->scorer()->score($lead));
        $this->assertSame('Alta', $this->scorer()->label(100));
    }

    public function test_only_the_last_10_customer_messages_are_considered(): void
    {
        // 11 mensagens de cliente, só a mais antiga fala de fechamento (preco) — fora da janela de 10.
        $chat = $this->chat();
        $base = CarbonImmutable::now()->subHours(20);

        $this->message($chat, 'in', $base, 'Quanto custa?', 'old-1');

        for ($i = 1; $i <= 10; $i++) {
            $this->message($chat, 'in', $base->addMinutes($i), 'oi', "recent-{$i}");
        }

        $lead = $this->lead(['whatsapp_chat_id' => $chat->id]);

        // Nenhuma das 10 mais recentes fala de fechamento/produto — só engajamento, mas
        // min(10*3, 15) tampa em 15, não 30 (teto da própria regra de engajamento).
        $this->assertSame(15, $this->scorer()->score($lead));
        $this->assertNotSame('Já recebeu orçamento', $this->scorer()->reason($lead));
    }

    public function test_mentioning_a_product_keyword_scores_even_without_product_interest_set(): void
    {
        $lead = $this->leadWithMessages(['Vocês tem capinha pro meu celular?']);

        // 15 (produto) + 3 (1 msg) + 5 (1 "?") = 23 -> Baixa (< 25)
        $this->assertSame(23, $this->scorer()->score($lead));
        $this->assertSame('Baixa', $this->scorer()->label(23));
        $this->assertSame('Citou produto específico', $this->scorer()->reason($lead));
    }

    public function test_product_interest_field_counts_as_a_product_mention_even_if_never_typed(): void
    {
        $lead = $this->leadWithMessages(['oi'], ['product_interest' => 'iPhone 13']);

        // 15 (produto, via campo) + 3 (1 msg) = 18
        $this->assertSame(18, $this->scorer()->score($lead));
    }

    public function test_accented_and_unaccented_keywords_match_the_same_way(): void
    {
        $withAccent = $this->leadWithMessages(['Qual o PREÇO da película?']);
        $withoutAccent = $this->leadWithMessages(['Qual o preco da pelicula?']);

        $this->assertSame($this->scorer()->score($withAccent), $this->scorer()->score($withoutAccent));
    }

    public function test_label_thresholds_are_inclusive_at_the_boundaries(): void
    {
        $scorer = $this->scorer();

        $this->assertSame('Baixa', $scorer->label(24));
        $this->assertSame('Média', $scorer->label(25));
        $this->assertSame('Média', $scorer->label(49));
        $this->assertSame('Alta', $scorer->label(50));
    }

    public function test_score_messages_works_without_a_lead_for_the_daily_report_ranking(): void
    {
        $chat = $this->chat();
        $at = CarbonImmutable::now()->subHours(2);
        $this->message($chat, 'in', $at, 'Quanto fica no boleto?', 'r1');

        $messages = $chat->messages()->where('direction', 'in')->get();

        // 25 (fechamento) + 3 (1 msg) + 5 (1 "?") = 33 — mesma formula do score(Lead), só
        // sem o bonus de orcamento/produto do cadastro (nao existe fora de um Lead).
        $this->assertSame(33, $this->scorer()->scoreMessages($messages));
        $this->assertStringContainsString('fechamento', $this->scorer()->reasonForMessages($messages));
    }

    public function test_score_messages_with_no_signal_at_all_is_zero(): void
    {
        $chat = $this->chat();
        $this->message($chat, 'in', CarbonImmutable::now()->subHours(2), 'oi', 'r2');

        $messages = $chat->messages()->where('direction', 'in')->get();

        $this->assertSame(3, $this->scorer()->scoreMessages($messages));
        $this->assertNull($this->scorer()->reasonForMessages($messages));
    }

    public function test_score_messages_with_an_empty_collection_is_zero(): void
    {
        $this->assertSame(0, $this->scorer()->scoreMessages(collect()));
        $this->assertNull($this->scorer()->reasonForMessages(collect()));
    }

    public function test_preload_gives_the_same_score_reason_and_last_message_as_one_query_per_lead(): void
    {
        $bodies = array_map(fn (int $i) => "mensagem {$i}", range(1, 14));
        $leads = collect([
            $this->leadWithMessages(['Quanto fica no boleto?', 'e a entrega?']),
            // Mais de 10 recebidas: só as 10 mais recentes contam (a pergunta antiga fica de fora).
            $this->leadWithMessages(array_merge(['Tem desconto?'], $bodies)),
            $this->leadWithMessages(['oi'], ['quoted_amount' => 900]),
            $this->lead(),
        ]);

        // Mensagem enviada pela loja não entra na nota.
        $chat = $leads[0]->chat;
        $this->message($chat, 'out', CarbonImmutable::now(), 'Quanto? Boleto? Entrega?', 'out-1');

        $scorer = $this->scorer();
        $expected = $leads->map(fn (Lead $l) => [
            $scorer->score($l),
            $scorer->reason($l),
            $l->chat?->messages()->where('direction', 'in')->latest('sent_at')->first()?->id,
        ])->all();

        $fresh = Lead::query()->whereIn('id', $leads->pluck('id'))->orderBy('id')->get();
        $scorer->preload($fresh);

        $actual = $fresh->map(fn (Lead $l) => [
            $scorer->score($l),
            $scorer->reason($l),
            $l->getRelation('recentInbound')->first()?->id,
        ])->all();

        $this->assertSame($expected, $actual);
    }

    public function test_panel_runs_a_constant_number_of_queries_no_matter_how_many_leads(): void
    {
        $user = \App\Models\User::create([
            'name' => 'Teste', 'email' => 'owner@center.com', 'password' => Hash::make('senha-boa-123'),
            'role' => 'owner', 'owner_slot' => 1, 'can_decide' => true,
        ]);

        $queriesFor = function (int $leads) use ($user): int {
            for ($i = 0; $i < $leads; $i++) {
                $this->leadWithMessages(['Quanto fica no boleto?', 'tem iphone?']);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get('/painel')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $few = $queriesFor(3);
        $many = $queriesFor(30);

        $this->assertSame($few, $many, "Consultas cresceram com o nº de leads ({$few} → {$many}).");
        $this->assertLessThan(30, $many);
    }

    private int $sequence = 0;

    /** Número sequencial (sem colisão) para telefones e chaves de chat dos testes. */
    private function nextNumber(): int
    {
        return ++$this->sequence;
    }

    private function scorer(): LeadPriorityScorer
    {
        return app(LeadPriorityScorer::class);
    }

    private function chat(): WhatsappChat
    {
        return WhatsappChat::create([
            'whatsapp_account_id' => $this->account->id,
            'chat_key' => '5534999'.str_pad((string) $this->nextNumber(), 6, '0', STR_PAD_LEFT),
            'jid' => '5534999990000@c.us',
            'kind' => 'individual',
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function lead(array $attributes = []): Lead
    {
        $customer = Customer::create(['phone' => '5511999'.str_pad((string) $this->nextNumber(), 6, '0', STR_PAD_LEFT), 'name' => 'Cliente Teste']);

        return Lead::create(array_merge([
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'status' => 'open',
        ], $attributes));
    }

    /**
     * @param  array<int, string>  $bodies
     * @param  array<string, mixed>  $attributes
     */
    private function leadWithMessages(array $bodies, array $attributes = []): Lead
    {
        $chat = $this->chat();
        $at = CarbonImmutable::now()->subHours(2);

        foreach ($bodies as $i => $body) {
            $this->message($chat, 'in', $at->addMinutes($i), $body, "{$chat->id}-m{$i}");
        }

        return $this->lead(array_merge(['whatsapp_chat_id' => $chat->id], $attributes));
    }

    private function message(WhatsappChat $chat, string $direction, CarbonImmutable $sentAt, string $body, string $externalId): WhatsappMessage
    {
        return WhatsappMessage::create([
            'whatsapp_account_id' => $chat->whatsapp_account_id,
            'whatsapp_chat_id' => $chat->id,
            'external_id' => $externalId,
            'direction' => $direction,
            'type' => 'chat',
            'body' => $body,
            'sent_at' => $sentAt,
            'source' => 'web_extension',
        ]);
    }
}
