<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsappBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, WhatsappAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config()->set('whatsapp.waha.accounts', [
            'center' => ['base_url' => 'http://waha-center:3000', 'api_key' => 'k1', 'session' => 'CENTER'],
            'genius' => ['base_url' => 'http://waha-genius:3000', 'api_key' => 'k2', 'session' => 'GENIUS'],
        ]);

        foreach (['CENTER', 'GENIUS'] as $code) {
            $store = Store::create(['code' => $code, 'name' => $code]);
            $this->accounts[$code] = WhatsappAccount::create([
                'store_id' => $store->id,
                'label' => $code,
                'provider' => 'web_extension',
                'token_hash' => WhatsappAccount::hashToken($code),
            ]);
        }
    }

    private function chat(string $store, string $phone, array $extra = []): WhatsappChat
    {
        return WhatsappChat::create($extra + [
            'whatsapp_account_id' => $this->accounts[$store]->id,
            'chat_key' => $phone.'_'.$store,
            'jid' => $phone.'@c.us',
            'kind' => 'individual',
            'phone' => $phone,
        ]);
    }

    public function test_simulation_shows_the_message_and_never_calls_the_api(): void
    {
        Http::fake();
        $this->chat('CENTER', '5511999990001');
        $this->chat('GENIUS', '5511999990002');

        $this->artisan('whatsapp:broadcast', ['--message' => 'Olá, promoção!'])
            ->expectsOutputToContain('Olá, promoção!')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_requires_a_message(): void
    {
        Http::fake();

        $this->artisan('whatsapp:broadcast')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_test_mode_sends_only_to_the_given_number_through_the_given_store(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 201)]);
        $this->chat('CENTER', '5511999990001');

        $this->artisan('whatsapp:broadcast', ['store' => 'CENTER', '--message' => 'Teste', '--test-to' => '19999833188'])
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://waha-center:3000/api/sendText'
            && $request['chatId'] === '19999833188@c.us'
            && $request['session'] === 'CENTER'
            && $request['text'] === 'Teste');
    }

    public function test_test_mode_requires_a_store(): void
    {
        Http::fake();

        $this->artisan('whatsapp:broadcast', ['--message' => 'Teste', '--test-to' => '19999833188'])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_send_requires_typed_confirmation(): void
    {
        Http::fake();
        $this->chat('CENTER', '5511999990001');

        $this->artisan('whatsapp:broadcast', ['--audience' => 'chats', '--message' => 'Oi', '--send' => true, '--min-delay' => 0, '--max-delay' => 0])
            ->expectsQuestion('Isto vai mandar mensagem a 1 clientes de verdade. Digite "ENVIAR 1" para confirmar', 'sim')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_send_skips_groups_ignored_and_chats_without_phone_and_uses_each_stores_session(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 201)]);
        $this->chat('CENTER', '5511999990001');
        $this->chat('GENIUS', '5511999990002');
        $this->chat('CENTER', '5511999990003', ['ignored' => true]);
        $this->chat('CENTER', '5511999990004', ['kind' => 'group']);
        $this->chat('CENTER', '5511999990005', ['phone' => null]);

        $this->artisan('whatsapp:broadcast', ['--audience' => 'chats', '--message' => 'Oi', '--send' => true, '--min-delay' => 0, '--max-delay' => 0])
            ->expectsQuestion('Isto vai mandar mensagem a 2 clientes de verdade. Digite "ENVIAR 2" para confirmar', 'ENVIAR 2')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'http://waha-center:3000/api/sendText' && $r['chatId'] === '5511999990001@c.us');
        Http::assertSent(fn ($r) => $r->url() === 'http://waha-genius:3000/api/sendText' && $r['chatId'] === '5511999990002@c.us');
    }

    public function test_limit_caps_the_round_and_a_rerun_never_repeats_the_same_chat(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 201)]);
        $this->chat('CENTER', '5511999990001');
        $this->chat('CENTER', '5511999990002');
        $this->chat('CENTER', '5511999990003');
        $opts = ['--audience' => 'chats', '--message' => 'Oi', '--send' => true, '--limit' => 2, '--min-delay' => 0, '--max-delay' => 0];

        $this->artisan('whatsapp:broadcast', $opts)
            ->expectsQuestion('Isto vai mandar mensagem a 2 clientes de verdade. Digite "ENVIAR 2" para confirmar', 'ENVIAR 2')
            ->assertSuccessful();
        Http::assertSentCount(2);

        $this->artisan('whatsapp:broadcast', $opts)
            ->expectsQuestion('Isto vai mandar mensagem a 1 clientes de verdade. Digite "ENVIAR 1" para confirmar', 'ENVIAR 1')
            ->assertSuccessful();
        Http::assertSentCount(3);

        $this->artisan('whatsapp:broadcast', $opts)->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_stops_after_three_consecutive_failures(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        foreach (range(1, 5) as $i) {
            $this->chat('CENTER', '551199999000'.$i);
        }

        $this->artisan('whatsapp:broadcast', ['--audience' => 'chats', '--message' => 'Oi', '--send' => true, '--min-delay' => 0, '--max-delay' => 0])
            ->expectsQuestion('Isto vai mandar mensagem a 5 clientes de verdade. Digite "ENVIAR 5" para confirmar', 'ENVIAR 5')
            ->assertFailed();

        Http::assertSentCount(3);
    }

    private function lead(string $store, string $phone, string $status = 'open', array $followups = []): Lead
    {
        $customer = Customer::firstOrCreate(['phone' => $phone], ['name' => 'Cliente '.$phone]);
        $lead = Lead::create([
            'customer_id' => $customer->id,
            'store_id' => $this->accounts[$store]->store_id,
            'source' => 'whatsapp',
            'status' => $status,
        ]);

        foreach ($followups as $followupStatus) {
            LeadFollowup::create([
                'lead_id' => $lead->id,
                'status' => $followupStatus,
                'reason' => 'customer_unanswered',
                'type' => 'recover',
            ]);
        }

        return $lead;
    }

    public function test_default_audience_is_the_reconnect_tab_only(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 201)]);
        $this->lead('CENTER', '5511999991001', 'open', ['candidate']);   // Reconectar (sugestão pendente)
        $this->lead('GENIUS', '5511999991002', 'open');                  // Reconectar (sem decisão)
        $this->lead('CENTER', '5511999991003', 'open', ['approved']);    // Em andamento
        $this->lead('CENTER', '5511999991004', 'open', ['dismissed']);   // Separado
        $this->lead('CENTER', '5511999991005', 'won');                   // Pós-venda
        $this->lead('CENTER', '5511999991006', 'lost');                  // Desistiu

        $this->artisan('whatsapp:broadcast', ['--message' => 'Oi', '--send' => true, '--min-delay' => 0, '--max-delay' => 0])
            ->expectsQuestion('Isto vai mandar mensagem a 2 clientes de verdade. Digite "ENVIAR 2" para confirmar', 'ENVIAR 2')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'http://waha-center:3000/api/sendText' && $r['chatId'] === '5511999991001@c.us');
        Http::assertSent(fn ($r) => $r->url() === 'http://waha-genius:3000/api/sendText' && $r['chatId'] === '5511999991002@c.us');
    }

    public function test_reconnect_audience_matches_the_painel_reconnect_tab_exactly(): void
    {
        $this->lead('CENTER', '5511999991011', 'open', ['candidate']);
        $this->lead('CENTER', '5511999991012', 'open', ['draft_ready']);
        $this->lead('CENTER', '5511999991013', 'open', ['approved']);
        $this->lead('CENTER', '5511999991014', 'open', ['dismissed']);
        $this->lead('CENTER', '5511999991015', 'won');

        $expected = Lead::query()->reconnect()->pluck('id')->sort()->values()->all();

        $this->assertCount(2, $expected);
        $this->assertSame(
            $expected,
            Lead::query()->where('status', 'open')->whereHas('customer', fn ($c) => $c->whereIn('phone', ['5511999991011', '5511999991012']))
                ->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_reconnect_audience_counts_the_same_phone_once_per_store_in_simulation(): void
    {
        Http::fake();
        $this->lead('CENTER', '5511999991021', 'open', ['candidate']);
        $this->lead('GENIUS', '5511999991021', 'open');

        $this->artisan('whatsapp:broadcast', ['--message' => 'Oi'])
            ->expectsOutputToContain('Total de números')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
