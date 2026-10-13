<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Procura conversas paradas e leads perdidos prontos para reconectar. Uma vez por dia já basta,
// porque a regra mínima é 24h parado (WHATSAPP_FOLLOWUP_MIN_IDLE_HOURS) — não muda de hora em
// hora. Precisa do serviço "scheduler" do docker-compose.yml rodando `schedule:run` a cada minuto.
Schedule::command('whatsapp:leads:detect')->dailyAt('18:00')->withoutOverlapping();

// Confere se as sessões do WAHA caíram. 1ª vez: tenta reconectar sozinho. Continua caída:
// manda WhatsApp de verdade pro Caio via a outra loja, e alimenta o aviso no painel.
Schedule::command('whatsapp:waha:health-check')->everyFiveMinutes()->withoutOverlapping();
