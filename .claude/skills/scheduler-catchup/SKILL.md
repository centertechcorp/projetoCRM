---
name: scheduler-catchup
description: Diagnostica e corrige agendamentos do Laravel (routes/console.php) que não rodaram porque o container "scheduler" estava fora do ar no horário exato. Use quando o usuário perguntar por que um lead/followup não apareceu, por que uma rotina diária parece ter "pulado" um dia, ou pedir pra investigar se o scheduler caiu.
---

# Catch-up de agendamento perdido (container scheduler)

## O problema de fundo

O Laravel **não tem mecanismo de catch-up/backfill** pra agendamentos perdidos. O
`routes/console.php` define:

```php
Schedule::command('whatsapp:leads:detect')->weekdays()->at('18:00')->withoutOverlapping();
Schedule::command('whatsapp:leads:detect')->saturdays()->at('12:00')->withoutOverlapping();
Schedule::command('whatsapp:waha:health-check')->everyFiveMinutes()->withoutOverlapping();
```

Sábado roda mais cedo (12h) porque as lojas encerram o atendimento às 12:30 nesse dia.
Domingo não tem agendamento nenhum (loja fechada o dia inteiro).

Mas isso só é avaliado quando algo chama `php artisan schedule:run`. Quem faz essa chamada,
a cada 60 segundos, é o container `centercorp-scheduler-1` (loop manual em
`docker-compose.yml`, porque o WSL não mantém cron persistente entre reinícios).

**Se esse container estiver fora do ar exatamente no minuto marcado (18:00 pro
`leads:detect`), aquela execução é perdida pra sempre** — quando o container volta, ele
não "lembra" que devia ter rodado às 18h; só volta a checar a partir do minuto atual.

O `health-check`, por rodar a cada 5 minutos, quase nunca sente esse problema (perde no
máximo uma execução, a próxima já cobre). O `leads:detect`, por rodar só 1x por dia, é o que
dói — um dia sem o container de pé = um dia inteiro sem detectar leads parados.

## Como diagnosticar

1. Veja desde quando o container do scheduler está de pé:
   ```
   docker inspect centercorp-scheduler-1 --format '{{.State.StartedAt}}'
   ```
   Se o horário de início for depois das 18:00 (seg-sex) ou das 12:00 (sábado) de um dia
   que devia ter processado, a execução daquele dia foi perdida.

2. Confirme no banco que realmente não rodou — via tinker, veja o último `Lead`/
   `LeadFollowup` criado:
   ```
   docker exec centercorp-laravel.test-1 php artisan tinker --execute="
   echo \App\Models\Lead::latest()->first()?->created_at;
   echo \App\Models\LeadFollowup::latest()->first()?->created_at;
   "
   ```
   Se o último registro for de antes do dia que devia ter processado, confirma o gap.

3. (Opcional) Confira os logs do próprio scheduler pra ver se tem registro de execução
   naquele horário:
   ```
   docker logs centercorp-scheduler-1 --tail 100
   ```
   Procure por linhas em volta de 18:00 — se só tiver "No scheduled commands are ready to
   run." e nada do `leads:detect`, confirma que não rodou.

## Como corrigir

Rodar manualmente o comando que foi perdido, como catch-up:

```
docker exec centercorp-laravel.test-1 php artisan whatsapp:leads:detect
```

O comando é idempotente (confere `last_contact_at`/`next_contact_at` por conversa), então
rodar manualmente fora do horário normal não duplica nada nem quebra o próximo ciclo
agendado das 18h.

## Observação

Isso é esperado em ambiente de dev (container não fica de pé 24/7) — **sábado e domingo não
tem serviço aqui, o ambiente fica desligado o fim de semana inteiro de propósito**. Um gap de
sexta pra segunda não é bug nem motivo de alarme, é o normal; só confirmar com o catch-up
manual (`whatsapp:leads:detect`) na volta, sem precisar investigar mais a fundo ou tratar como
incidente. Só vale investigar de verdade (ver "Como diagnosticar") se o gap cair num dia de
semana, sem explicação óbvia de desligamento.

Se isso incomodar de verdade no futuro, a solução seria um mecanismo de catch-up automático
no boot do scheduler (ex.: checar se já passou das 18h do último dia útil e o comando ainda
não rodou, disparar sozinho na subida do container) — não implementado, só considerado.
