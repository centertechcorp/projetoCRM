<?php

namespace Tests\Unit;

use App\Services\Whatsapp\ClosingPhraseMatcher;
use PHPUnit\Framework\TestCase;

class ClosingPhraseMatcherTest extends TestCase
{
    /** @var array<int, string> */
    private const PHRASES = ['obrigado', 'valeu', 'muito obrigada', '👍', 'ok'];

    public function test_a_plain_goodbye_is_closing(): void
    {
        $this->assertTrue(ClosingPhraseMatcher::isClosing('obrigado', self::PHRASES));
        $this->assertTrue(ClosingPhraseMatcher::isClosing('Valeu', self::PHRASES));
    }

    public function test_case_punctuation_spaces_and_accents_are_ignored(): void
    {
        $this->assertTrue(ClosingPhraseMatcher::isClosing('  OBRIGADO!!!  ', self::PHRASES));
        $this->assertTrue(ClosingPhraseMatcher::isClosing('Muito   obrigada.', self::PHRASES));
        $this->assertTrue(ClosingPhraseMatcher::isClosing('obrigádo', ['obrigado']));
    }

    public function test_emoji_closing_survives_normalization(): void
    {
        $this->assertTrue(ClosingPhraseMatcher::isClosing('👍', self::PHRASES));
    }

    public function test_a_goodbye_followed_by_a_question_is_not_closing(): void
    {
        $this->assertFalse(ClosingPhraseMatcher::isClosing('obrigado, mas qual o preço?', self::PHRASES));
        $this->assertFalse(ClosingPhraseMatcher::isClosing('ok, tem em azul?', self::PHRASES));
    }

    public function test_empty_or_unrelated_messages_are_not_closing(): void
    {
        $this->assertFalse(ClosingPhraseMatcher::isClosing('', self::PHRASES));
        $this->assertFalse(ClosingPhraseMatcher::isClosing('   ', self::PHRASES));
        $this->assertFalse(ClosingPhraseMatcher::isClosing('quanto custa a tela?', self::PHRASES));
        $this->assertFalse(ClosingPhraseMatcher::isClosing('obrigado', []));
    }
}
