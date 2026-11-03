<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Copia Postgres → MariaDB com dados FABRICADOS (nunca o banco real). Roda na suíte do Postgres
 * e precisa do container mariadb (banco "testing"); sem ele, é pulado.
 */
class DbCopyPgToMariadbTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = 'mariadb_target';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A origem precisa ser o Postgres.');
        }

        try {
            DB::connection(self::TARGET)->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('MariaDB de destino indisponível.');
        }

        // Esquema novo e vazio no destino (migrate:fresh só no banco de teste do MariaDB).
        $this->assertSame('testing', DB::connection(self::TARGET)->getDatabaseName());
        Artisan::call('migrate:fresh', ['--database' => self::TARGET, '--force' => true]);
    }

    public function test_copies_every_row_with_json_booleans_and_times_intact_and_counts_match(): void
    {
        $sentAt = Carbon::create(2026, 10, 20, 14, 30, 0, 'America/Sao_Paulo');
        $store = Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        $customer = Customer::create(['phone' => '5534999990001', 'name' => 'José 😀']);
        $open = Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'open',
            'products' => ['iPhone 13 📱'], 'product_category_locked' => true, 'last_contact_at' => $sentAt]);
        $gone = Lead::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'lost']);
        $gone->delete();
        $account = WhatsappAccount::create(['store_id' => $store->id, 'label' => 'WhatsApp CENTER', 'provider' => 'waha', 'provider_account_ref' => 's']);
        $chat = WhatsappChat::create(['whatsapp_account_id' => $account->id, 'chat_key' => 'AbC', 'jid' => 'x@c.us', 'kind' => 'individual']);
        WhatsappMessage::create(['whatsapp_account_id' => $account->id, 'whatsapp_chat_id' => $chat->id, 'external_id' => 'M1',
            'direction' => 'in', 'type' => 'text', 'body' => 'Olá, tudo bem? ção', 'sent_at' => $sentAt, 'source' => 'waha',
            'payload' => ['a' => ['b' => 'ç😀']]]);

        $this->artisan('db:copy-pg-to-mariadb', ['--to' => self::TARGET])
            ->expectsOutputToContain('as contagens batem')
            ->assertExitCode(0);

        $target = DB::connection(self::TARGET);
        $this->assertSame(1, $target->table('stores')->count());
        $this->assertSame(2, $target->table('leads')->count());

        $lead = $target->table('leads')->where('id', $open->id)->first();
        $this->assertSame(['iPhone 13 📱'], json_decode($lead->products, true));
        $this->assertEquals(1, $lead->product_category_locked);
        $this->assertTrue($sentAt->equalTo(Carbon::parse($lead->last_contact_at, config('app.timezone'))), 'last_contact_at deslocou');
        $this->assertNotNull($target->table('leads')->where('id', $gone->id)->value('deleted_at'), 'soft delete se perdeu');

        $message = $target->table('whatsapp_messages')->first();
        $this->assertSame(['a' => ['b' => 'ç😀']], json_decode($message->payload, true));
        $this->assertTrue($sentAt->equalTo(Carbon::parse($message->sent_at, config('app.timezone'))), 'sent_at deslocou');
        $this->assertSame('M1', $message->external_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Store::create(['code' => 'CENTER', 'name' => 'CENTER']);

        $this->artisan('db:copy-pg-to-mariadb', ['--to' => self::TARGET, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DB::connection(self::TARGET)->table('stores')->count());
    }

    public function test_refuses_to_run_when_the_target_already_has_data_and_changes_nothing(): void
    {
        Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
        DB::connection(self::TARGET)->table('stores')->insert(['code' => 'X', 'name' => 'X', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('db:copy-pg-to-mariadb', ['--to' => self::TARGET])
            ->expectsOutputToContain('já tem dados')
            ->assertExitCode(1);

        $this->assertSame(1, DB::connection(self::TARGET)->table('stores')->count());
    }

    public function test_refuses_a_source_that_is_not_postgres(): void
    {
        $this->artisan('db:copy-pg-to-mariadb', ['--from' => self::TARGET, '--to' => self::TARGET])->assertExitCode(1);
    }
}
