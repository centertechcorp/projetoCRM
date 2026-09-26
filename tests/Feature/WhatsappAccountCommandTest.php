<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\WhatsappAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Store::create(['code' => 'CENTER', 'name' => 'CENTER']);
    }

    public function test_cloud_api_account_keeps_the_provider_reference_and_has_no_extension_token(): void
    {
        $this->artisan('whatsapp:account', [
            'store' => 'center',
            'label' => 'WhatsApp CENTER (Meta teste)',
            '--provider' => 'cloud_api',
            '--ref' => '1293439387195061',
        ])->assertSuccessful();

        $account = WhatsappAccount::sole();
        $this->assertSame('cloud_api', $account->provider);
        $this->assertSame('1293439387195061', $account->provider_account_ref);
        $this->assertNull($account->token_hash);
        $this->assertSame('CENTER', $account->store->code);
    }

    public function test_extension_account_gets_a_hashed_token(): void
    {
        $this->artisan('whatsapp:account', ['store' => 'CENTER', 'label' => 'Extensão'])->assertSuccessful();

        $account = WhatsappAccount::sole();
        $this->assertSame('web_extension', $account->provider);
        $this->assertNull($account->provider_account_ref);
        $this->assertSame(64, strlen($account->token_hash));
    }

    public function test_invalid_ref_and_unknown_store_are_rejected(): void
    {
        $this->artisan('whatsapp:account', ['store' => 'CENTER', 'label' => 'x', '--ref' => 'abc'])->assertFailed();
        $this->artisan('whatsapp:account', ['store' => 'NADA', 'label' => 'x'])->assertFailed();

        $this->assertSame(0, WhatsappAccount::count());
    }
}
