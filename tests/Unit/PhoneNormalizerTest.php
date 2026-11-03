<?php

namespace Tests\Unit;

use App\Services\Whatsapp\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_brazilian_mobile_without_the_ninth_digit_gets_it_added(): void
    {
        $this->assertSame('5534999990001', PhoneNormalizer::normalize('553499990001'));
    }

    public function test_brazilian_mobile_that_already_has_the_ninth_digit_is_kept(): void
    {
        $this->assertSame('5534999990001', PhoneNormalizer::normalize('5534999990001'));
    }

    public function test_brazilian_landline_with_twelve_digits_is_not_touched(): void
    {
        // 55 + DDD + 8 dígitos começando em 2-5 é fixo, não celular sem o 9.
        $this->assertSame('553432221111', PhoneNormalizer::normalize('553432221111'));
    }

    public function test_non_digits_are_stripped(): void
    {
        $this->assertSame('5534999990001', PhoneNormalizer::normalize('+55 (34) 99999-0001'));
    }

    public function test_foreign_numbers_are_not_guessed(): void
    {
        $this->assertSame('16505551234', PhoneNormalizer::normalize('+1 650 555 1234'));
    }

    public function test_empty_or_too_short_input_returns_null(): void
    {
        $this->assertNull(PhoneNormalizer::normalize(''));
        $this->assertNull(PhoneNormalizer::normalize('abc'));
        $this->assertNull(PhoneNormalizer::normalize('1234567'));
    }
}
