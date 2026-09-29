<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Procura conversas paradas e leads perdidos prontos para reconectar. Precisa do serviço
// "scheduler" do docker-compose.yml (ou de outro agendador) rodando `schedule:run` a cada minuto.
Schedule::command('whatsapp:leads:detect')->hourly()->withoutOverlapping();
