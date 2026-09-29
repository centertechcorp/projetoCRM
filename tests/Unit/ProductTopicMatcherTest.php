<?php

namespace Tests\Unit;

use App\Services\Whatsapp\ProductTopicMatcher;
use PHPUnit\Framework\TestCase;

class ProductTopicMatcherTest extends TestCase
{
    /** @var array<int, string> */
    private const KEYWORDS = ['celular', 'iphone', 'carregador', 'fone de ouvido', 'tela', 'peça'];

    public function test_electronics_terms_are_relevant(): void
    {
        $this->assertTrue(ProductTopicMatcher::isRelevant('Alguém tem carregador de iPhone?', self::KEYWORDS, 1));
        $this->assertTrue(ProductTopicMatcher::isRelevant('Preciso trocar a TELA do celular', self::KEYWORDS, 1));
        $this->assertTrue(ProductTopicMatcher::isRelevant('vendo fone de ouvido novo', self::KEYWORDS, 1));
    }

    public function test_unrelated_chatter_is_not_relevant(): void
    {
        $this->assertFalse(ProductTopicMatcher::isRelevant('Vestido tamanho m', self::KEYWORDS, 1));
        $this->assertFalse(ProductTopicMatcher::isRelevant('Calça tamanho p, aparência de pequena', self::KEYWORDS, 1));
        $this->assertFalse(ProductTopicMatcher::isRelevant('https://chat.whatsapp.com/HzbfG3P6JacCfKUG7tM8UG', self::KEYWORDS, 1));
        $this->assertFalse(ProductTopicMatcher::isRelevant('', self::KEYWORDS, 1));
    }

    public function test_price_catalog_is_ignored_even_mentioning_a_product(): void
    {
        $catalog = "iPhone 16 Pro Max 256GB White R\$ 5.499\niPhone 16 Pro 128GB Desert R\$ 4.499\niPhone 16 256GB Branco R\$ 3.999";

        $this->assertFalse(ProductTopicMatcher::isRelevant($catalog, self::KEYWORDS, 1));
    }

    public function test_a_single_price_mention_is_still_allowed(): void
    {
        $this->assertTrue(ProductTopicMatcher::isRelevant('Tenho um iPhone por R$ 2000, aceita troca?', self::KEYWORDS, 1));
    }
}
