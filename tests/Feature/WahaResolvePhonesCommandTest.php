<?php

namespace Tests\Feature;

use App\Jobs\Whatsapp\ResolveWahaChatPhone;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WahaResolvePhonesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_dispatches_for_lid_chats_still_missing_a_phone(): void
    {
        Queue::fake([ResolveWahaChatPhone::class]);

        $store = Store::create(['code' => 'GENIUS', 'name' => 'GENIUS']);
        $account = WhatsappAccount::create(['store_id' => $store->id, 'label' => 'WhatsApp GENIUS', 'provider' => 'web_extension']);

        $stuck = WhatsappChat::create([
            'whatsapp_account_id' => $account->id, 'chat_key' => '234982123540724@lid',
            'jid' => '234982123540724@lid', 'kind' => 'individual',
        ]);
        $alreadyResolved = WhatsappChat::create([
            'whatsapp_account_id' => $account->id, 'chat_key' => '169346433327189@lid',
            'jid' => '169346433327189@lid', 'kind' => 'individual', 'phone' => '5534999990000',
        ]);
        $normalPhone = WhatsappChat::create([
            'whatsapp_account_id' => $account->id, 'chat_key' => '5534999991111@c.us',
            'jid' => '5534999991111@c.us', 'kind' => 'individual', 'phone' => '5534999991111',
        ]);
        $group = WhatsappChat::create([
            'whatsapp_account_id' => $account->id, 'chat_key' => '120363405703953892@g.us',
            'jid' => '120363405703953892@g.us', 'kind' => 'group',
        ]);

        $this->artisan('whatsapp:waha:resolve-phones')->assertSuccessful();

        Queue::assertPushed(ResolveWahaChatPhone::class, 1);
        Queue::assertPushed(function (ResolveWahaChatPhone $job) use ($stuck) {
            return (fn () => $this->whatsappChatId)->call($job) === $stuck->id;
        });
    }
}
