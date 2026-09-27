<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadFollowup;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\StalledConversationDetector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Dados fictícios só para ver o painel funcionando antes de haver conversas reais.
 * Nunca rodar em produção; não entra no DatabaseSeeder::run() padrão.
 * Uso: php artisan db:seed --class=WhatsappDemoSeeder
 */
class WhatsappDemoSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::query()->where('code', 'CENTER')->firstOrFail();

        $account = WhatsappAccount::query()->updateOrCreate(
            ['label' => 'Demonstração (dados fictícios)'],
            ['store_id' => $store->id, 'provider' => 'web_extension', 'active' => true],
        );

        // Cliente perguntou e ainda não foi respondido.
        $this->chat($account, '5534999990001', 'Maria Cliente', [
            ['in', 'Maria Cliente', 'Vocês têm tela de iPhone 11 na loja?', -50],
        ]);

        // Nós respondemos e o cliente sumiu.
        $this->chat($account, '5534999990002', 'João Cliente', [
            ['in', 'João Cliente', 'Quanto fica a bateria do Moto G? Preciso pra amanhã', -96],
            ['out', 'Eu', 'Fica R$ 89,90 com instalação na hora!', -95],
        ]);

        // Cliente que já comprou: fica no cadastro (não apaga) e alimenta o pós-venda.
        $customer = Customer::query()->updateOrCreate(
            ['phone' => '5534999990003'],
            ['name' => 'Ana Cliente'],
        );

        $lead = Lead::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'store_id' => $store->id, 'status' => 'won'],
            [
                'product_interest' => 'iPhone 13, 128GB',
                'quoted_amount' => 3299.90,
                'source' => 'whatsapp',
                'won_at' => CarbonImmutable::now()->subDays(5),
                'archive_after' => CarbonImmutable::now()->addDays((int) config('whatsapp.followup.retention_days')),
                'last_contact_at' => CarbonImmutable::now()->subDays(5),
            ],
        );

        // Sugestão de pós-venda: hoje é um exemplo fixo; a Fase 2 (IA) vai gerar isso de verdade
        // a partir dos itens da venda, quando a sincronização com o GestãoClick existir.
        LeadFollowup::query()->firstOrCreate(
            ['lead_id' => $lead->id, 'type' => 'upsell'],
            [
                'reason' => 'purchase',
                'status' => 'candidate',
                'suggested_by' => 'rules',
                'confidence' => 'to_confirm',
                'suggested_message' => 'Oi Ana! Vi que você levou o iPhone 13 com a gente 🙂 '
                    .'Temos capinha e película pra ele, quer que eu separe? Qual cor você prefere?',
            ],
        );

        // Gera os leads e as sugestões dos dois casos acima com a mesma regra usada em produção.
        app(StalledConversationDetector::class)->detect($store->id);

        $this->command?->info('Dados fictícios criados: 2 conversas paradas + 1 cliente com compra (pós-venda).');
    }

    /** @param  array<int, array{0: string, 1: string, 2: string, 3: int}>  $messages */
    private function chat(WhatsappAccount $account, string $phone, string $name, array $messages): void
    {
        $chat = WhatsappChat::query()->updateOrCreate(
            ['whatsapp_account_id' => $account->id, 'chat_key' => $phone],
            ['jid' => "{$phone}@c.us", 'kind' => 'individual', 'phone' => $phone, 'display_name' => $name],
        );

        $lastAt = null;

        foreach ($messages as $i => [$direction, $sender, $body, $hoursAgo]) {
            $sentAt = CarbonImmutable::now()->addHours($hoursAgo);
            $lastAt = $sentAt;

            WhatsappMessage::query()->updateOrCreate(
                ['whatsapp_account_id' => $account->id, 'external_id' => "demo-{$phone}-{$i}"],
                [
                    'whatsapp_chat_id' => $chat->id,
                    'direction' => $direction,
                    'sender_name' => $direction === 'in' ? $sender : null,
                    'type' => 'chat',
                    'body' => $body,
                    'sent_at' => $sentAt,
                    'source' => 'web_extension',
                ],
            );
        }

        $chat->update(['last_message_at' => $lastAt]);
    }
}
