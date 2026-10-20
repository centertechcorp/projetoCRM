<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChat;
use App\Models\WhatsappMessage;
use App\Services\Leads\LeadInfoExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_product_category_detects_a_phone_brand_as_celular(): void
    {
        $this->assertSame('celular', $this->extractor->productCategory($this->messages(['queria saber sobre o iphone'])));
    }

    public function test_product_category_detects_an_accessory_without_any_brand_as_peca(): void
    {
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['tem capinha disponível?'])));
    }

    public function test_product_category_treats_accessory_for_a_brand_as_peca_not_celular(): void
    {
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['tem capinha de iphone?'])));
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['tem carregador de galaxy?'])));
    }

    public function test_product_category_still_detects_a_genuine_phone_question_even_mentioning_brand(): void
    {
        $this->assertSame('celular', $this->extractor->productCategory($this->messages(['tem iphone disponível na loja?'])));
    }

    public function test_product_category_treats_price_question_about_a_brand_as_celular_not_peca(): void
    {
        // Bug relatado: "do iphone" tem a mesma preposição de "capinha de iphone", mas aqui
        // não tem peça nenhuma antes — é pergunta de preço sobre o aparelho em si.
        $this->assertSame('celular', $this->extractor->productCategory($this->messages(['qual o preço do iphone'])));
    }

    public function test_product_category_treats_warranty_question_about_a_brand_as_celular(): void
    {
        $this->assertSame('celular', $this->extractor->productCategory($this->messages(['o iphone tem garantia?'])));
    }

    public function test_product_category_treats_accessory_for_a_brand_with_filler_words_as_peca(): void
    {
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['tem capinha pro meu iphone'])));
    }

    /** @return array<string, array{0: string}> */
    public static function phoneBrandProvider(): array
    {
        return [
            'samsung' => ['queria saber sobre o samsung'],
            'xiaomi' => ['queria saber sobre o xiaomi'],
            'redmi' => ['queria saber sobre o redmi'],
            'motorola' => ['queria saber sobre a motorola'],
            'poco' => ['queria saber sobre o poco'],
            'lg' => ['queria saber sobre o lg'],
            'huawei' => ['queria saber sobre o huawei'],
        ];
    }

    #[DataProvider('phoneBrandProvider')]
    public function test_product_category_detects_other_phone_brands_as_celular(string $body): void
    {
        $this->assertSame('celular', $this->extractor->productCategory($this->messages([$body])));
    }

    public function test_product_category_treats_more_accessory_brand_combinations_as_peca(): void
    {
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['tem fonte de xiaomi?'])));
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['cabo do redmi vcs tem?'])));
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['queria uma capa da motorola'])));
    }

    public function test_product_category_joins_the_whole_message_window_not_just_one_message(): void
    {
        // Mensagens vêm mais recentes primeiro — a "capinha" tá na mensagem mais antiga e o
        // "iphone" na mais nova, mas precisa juntar as duas pra reconhecer o padrão.
        $messages = $this->messages(['pro meu iphone', 'queria uma capinha']);

        $this->assertSame('peca', $this->extractor->productCategory($messages));
    }

    public function test_product_category_does_not_treat_ipad_apple_watch_notebook_or_tablet_as_celular(): void
    {
        // Decisão deliberada: estão na lista "Marca/modelo" do config (mesmo grupo do
        // iphone/samsung), mas não são celular — tablet, relógio e notebook.
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['queria saber sobre o ipad'])));
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['tem apple watch?'])));
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['queria um notebook'])));
        $this->assertSame('peca', $this->extractor->productCategory($this->messages(['tem tablet disponível?'])));
    }

    public function test_product_category_is_case_insensitive(): void
    {
        $this->assertSame('celular', $this->extractor->productCategory($this->messages(['QUERIA UM IPHONE NOVO'])));
    }

    public function test_product_category_accessory_for_brand_wins_even_with_another_loose_accessory_mentioned(): void
    {
        // "capinha de iphone" (padrão peça-pra-marca) e "película" (peça solta) não
        // conflitam — os dois sinais apontam pro mesmo lugar: 'peca'.
        $this->assertSame('peca', $this->extractor->productCategory(
            $this->messages(['tem capinha de iphone? e película também?']),
        ));
    }

    public function test_product_category_known_limitation_accessory_pattern_anywhere_wins_over_a_separate_genuine_purchase(): void
    {
        // Limitação documentada de propósito: a regra junta a janela toda num texto só, então
        // mesmo com um pedido de compra genuíno do aparelho em outro trecho da conversa, a
        // presença do padrão "peça de marca" em qualquer lugar já decide 'peca'. É pra esse
        // caso raro que existe a correção manual (painel.leads.category).
        $messages = $this->messages([
            'show, bora fechar o iphone então',
            'ah e tem capinha de iphone tb? preciso de uma nova',
        ]);

        $this->assertSame('peca', $this->extractor->productCategory($messages));
    }

    public function test_product_category_is_null_without_any_mention(): void
    {
        $this->assertNull($this->extractor->productCategory($this->messages(['oi, tudo bem?'])));
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
