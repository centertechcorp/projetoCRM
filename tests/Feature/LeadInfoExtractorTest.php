<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use App\Services\Leads\LeadInfoExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LeadInfoExtractorTest extends TestCase
{
    use RefreshDatabase;

    private LeadInfoExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new LeadInfoExtractor;
    }

    public function test_quoted_amount_parses_simple_value(): void
    {
        $this->assertSame(150.0, $this->extractor->quotedAmount($this->messages(['fica R$ 150 pra você'])));
    }

    public function test_quoted_amount_parses_decimal_with_comma(): void
    {
        $this->assertSame(150.0, $this->extractor->quotedAmount($this->messages(['fica R$150,00'])));
    }

    public function test_quoted_amount_parses_thousands_separator(): void
    {
        $this->assertSame(1500.0, $this->extractor->quotedAmount($this->messages(['fica R$ 1.500,00 à vista'])));
    }

    public function test_quoted_amount_uses_the_most_recent_mention(): void
    {
        // Mensagens vêm mais recentes primeiro (mesmo padrão do orderByDesc do detector).
        $messages = $this->messages(['agora fica R$ 2.000', 'tinha dito R$ 1.800 antes']);

        $this->assertSame(2000.0, $this->extractor->quotedAmount($messages));
    }

    public function test_quoted_amount_is_null_without_any_mention(): void
    {
        $this->assertNull($this->extractor->quotedAmount($this->messages(['pode ser sim'])));
    }

    public function test_product_interest_matches_a_keyword(): void
    {
        $this->assertSame('iphone', $this->extractor->productInterest($this->messages(['queria saber sobre o iphone'])));
    }

    public function test_product_interest_is_accent_insensitive(): void
    {
        // "película" no texto (com acento) ainda bate com "pelicula" (sem acento) na config.
        $this->assertSame('pelicula', $this->extractor->productInterest($this->messages(['tem película pro meu aparelho'])));
    }

    public function test_product_interest_is_null_without_any_mention(): void
    {
        $this->assertNull($this->extractor->productInterest($this->messages(['oi, tudo bem?'])));
    }

    /** @param  array<int, string>  $bodies */
    private function messages(array $bodies): Collection
    {
        $store = Store::firstOrCreate(['code' => 'CENTER'], ['name' => 'CENTER']);
        $account = WhatsappAccount::firstOrCreate(
            ['store_id' => $store->id, 'label' => 'WhatsApp CENTER'],
            ['provider' => 'web_extension'],
        );
        $chat = WhatsappChat::create([
            'whatsapp_account_id' => $account->id,
            'chat_key' => 'chave-'.random_int(10000, 99999),
            'jid' => '5534999990000@c.us',
            'kind' => 'individual',
        ]);

        return collect($bodies)->map(fn (string $body, int $i) => WhatsappMessage::create([
            'whatsapp_account_id' => $account->id,
            'whatsapp_chat_id' => $chat->id,
            'external_id' => 'ext-'.random_int(100000, 999999),
            'direction' => 'in',
            'type' => 'chat',
            'body' => $body,
            'sent_at' => now()->subMinutes($i),
            'source' => 'waha',
        ]));
    }
}
