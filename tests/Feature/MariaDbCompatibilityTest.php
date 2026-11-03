<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pontos do esquema que só funcionam por caminhos diferentes no MariaDB (o Postgres é o padrão).
 * Rodam só em MariaDB/MySQL: php artisan test --configuration não serve, use
 * vendor/bin/phpunit --configuration phpunit.mariadb.xml
 */
class MariaDbCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Só roda em MariaDB/MySQL.');
        }
    }

    public function test_open_lead_is_unique_per_customer_and_store_but_history_is_allowed(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $customer = Customer::create(['phone' => '5534999990001', 'name' => 'Cliente']);
        $make = fn (string $status) => Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => $status]);

        $open = $make('open');

        try {
            $make('open');
            $this->fail('Dois leads abertos para o mesmo cliente e loja não deveriam ser aceitos.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('leads_open_customer_store_unique', $e->getMessage());
        }

        // Histórico fechado não conta.
        $make('won');
        $make('lost');
        $make('lost');

        // Fechado o aberto, pode abrir outro; apagado (soft delete) também libera.
        $open->update(['status' => 'won']);
        $second = $make('open');
        $second->delete();
        $make('open');

        $this->assertSame(1, Lead::where('status', 'open')->count());
    }

    public function test_owner_role_requires_a_valid_owner_slot(): void
    {
        try {
            DB::table('users')->insert(['name' => 'A', 'email' => 'a@x.com', 'password' => 'x', 'role' => 'owner', 'owner_slot' => 9,
                'created_at' => now(), 'updated_at' => now()]);
            $this->fail('owner com owner_slot inválido deveria ser rejeitado.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('users_role_owner_slot_check', $e->getMessage());
        }

        DB::table('users')->insert(['name' => 'A', 'email' => 'a@x.com', 'password' => 'x', 'role' => 'owner', 'owner_slot' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_non_owner_cannot_have_an_owner_slot(): void
    {
        try {
            DB::table('users')->insert(['name' => 'B', 'email' => 'b@x.com', 'password' => 'x', 'role' => 'seller', 'owner_slot' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            $this->fail('seller com owner_slot deveria ser rejeitado.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('users_role_owner_slot_check', $e->getMessage());
        }
    }

    public function test_search_is_case_and_accent_insensitive_and_treats_wildcards_literally_enough(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $customer = Customer::create(['phone' => '5534999990002', 'name' => 'José da Silva']);
        Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open']);
        $user = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($user)->get('/painel?tab=reconnect&q=JOSE')->getContent();

        $this->assertStringContainsString('José da Silva', $html);
    }

    public function test_json_columns_round_trip_with_emoji_and_accents(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $payload = ['texto' => 'Olá, tudo bem? 😀 ção', 'n' => [1, 2, 3]];

        $event = Event::create(['type' => 'teste', 'subject_type' => 'lead', 'subject_id' => 1, 'occurred_at' => now(), 'payload' => $payload]);

        $this->assertSame($payload, $event->fresh()->payload);

        $customer = Customer::create(['phone' => '5534999990003', 'name' => 'C']);
        $lead = Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open',
            'products' => ['iPhone 13 📱', 'Película']]);

        $this->assertSame(['iPhone 13 📱', 'Película'], $lead->fresh()->products);
    }

    public function test_boolean_columns_come_back_as_real_booleans(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $customer = Customer::create(['phone' => '5534999990004', 'name' => 'C']);
        $lead = Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open',
            'product_category_locked' => true]);

        $this->assertTrue($lead->fresh()->product_category_locked);
    }

    public function test_invalid_enum_value_is_rejected(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $customer = Customer::create(['phone' => '5534999990005', 'name' => 'C']);

        $this->expectException(QueryException::class);

        Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'x']);
    }

    public function test_whatsapp_ids_that_differ_only_by_case_do_not_collide(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = WhatsappAccount::create(['store_id' => $store->id, 'label' => 'A', 'provider' => 'waha', 'provider_account_ref' => 's']);

        $chat = fn (string $key) => WhatsappChat::create(['whatsapp_account_id' => $account->id, 'chat_key' => $key,
            'jid' => "{$key}@s.whatsapp.net", 'kind' => 'individual']);
        $chat('AbC');
        $second = $chat('abc');

        $message = fn (string $id) => WhatsappMessage::create(['whatsapp_account_id' => $account->id,
            'whatsapp_chat_id' => $second->id, 'external_id' => $id, 'direction' => 'in', 'type' => 'text',
            'body' => 'oi', 'sent_at' => now(), 'source' => 'waha']);
        $message('3EB0AAA');
        $message('3eb0aaa');

        $this->assertSame(2, WhatsappChat::count());
        $this->assertSame(2, WhatsappMessage::count());
    }

    public function test_a_message_body_bigger_than_64kb_is_stored_whole(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = WhatsappAccount::create(['store_id' => $store->id, 'label' => 'A', 'provider' => 'waha', 'provider_account_ref' => 's']);
        $chat = WhatsappChat::create(['whatsapp_account_id' => $account->id, 'chat_key' => 'k', 'jid' => 'k@s.whatsapp.net', 'kind' => 'individual']);
        // Já aconteceu de verdade: uma foto em base64 (70 KB) caiu no body e estourou o TEXT do MariaDB.
        $big = str_repeat('/9j/4AAQSkZJRg', 8000);

        $message = WhatsappMessage::create(['whatsapp_account_id' => $account->id, 'whatsapp_chat_id' => $chat->id,
            'external_id' => 'big', 'direction' => 'in', 'type' => 'image', 'body' => $big, 'sent_at' => now(), 'source' => 'waha']);

        $this->assertSame(strlen($big), strlen($message->fresh()->body));
    }

    public function test_the_messages_view_exposes_store_and_account(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = WhatsappAccount::create(['store_id' => $store->id, 'label' => 'WhatsApp CENTER', 'provider' => 'waha', 'provider_account_ref' => 's']);
        $chat = WhatsappChat::create(['whatsapp_account_id' => $account->id, 'chat_key' => 'k', 'jid' => 'k@s.whatsapp.net', 'kind' => 'individual']);
        WhatsappMessage::create(['whatsapp_account_id' => $account->id, 'whatsapp_chat_id' => $chat->id,
            'external_id' => 'm1', 'direction' => 'in', 'type' => 'text', 'body' => 'oi', 'sent_at' => now(), 'source' => 'waha']);

        $row = DB::table('whatsapp_messages_view')->first();

        $this->assertSame('CENTER', $row->loja);
        $this->assertSame('WhatsApp CENTER', $row->conta);
    }

    public function test_timestamps_are_stored_and_read_back_without_a_three_hour_shift(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $account = WhatsappAccount::create(['store_id' => $store->id, 'label' => 'A', 'provider' => 'waha', 'provider_account_ref' => 's']);
        $chat = WhatsappChat::create(['whatsapp_account_id' => $account->id, 'chat_key' => 'k', 'jid' => 'k@s.whatsapp.net', 'kind' => 'individual']);
        $sentAt = now()->setTimezone('America/Sao_Paulo')->setDate(2026, 10, 20)->setTime(14, 30, 0);

        $message = WhatsappMessage::create(['whatsapp_account_id' => $account->id, 'whatsapp_chat_id' => $chat->id,
            'external_id' => 'm1', 'direction' => 'in', 'type' => 'text', 'body' => 'oi', 'sent_at' => $sentAt, 'source' => 'waha']);

        $this->assertTrue($sentAt->equalTo($message->fresh()->sent_at), 'o horário voltou diferente do gravado');
        $this->assertSame('14:30', $message->fresh()->sent_at->setTimezone('America/Sao_Paulo')->format('H:i'));
    }

    public function test_the_painel_orders_leads_with_a_null_last_contact_without_breaking(): void
    {
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $user = User::factory()->create(['role' => 'admin']);
        foreach ([null, now()->subDay(), now()] as $i => $contact) {
            $customer = Customer::create(['phone' => "553499999010{$i}", 'name' => "Cliente {$i}"]);
            Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open', 'last_contact_at' => $contact]);
        }

        $this->actingAs($user)->get('/painel?tab=reconnect')
            ->assertOk()->assertSee('Cliente 0')->assertSee('Cliente 1')->assertSee('Cliente 2');
    }
}
