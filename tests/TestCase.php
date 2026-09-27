<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Os assets (Tailwind/JS) não são compilados para rodar os testes; sem isso, qualquer
        // view com @vite(...) falha com "Vite manifest not found".
        $this->withoutVite();
    }
}
