---
name: timezone-mensagens
description: Investiga horários de mensagens do WhatsApp aparecendo errados/adiantados (tipicamente 3h na frente, sintoma de UTC em vez de America/Sao_Paulo). Use quando o usuário disser que um horário mostrado está "doido", errado, ou adiantado/atrasado em relação ao que ele sabe que é o horário real da mensagem.
---

# Horário errado nas mensagens (bug já resolvido uma vez, pode voltar)

## Sintoma

Hora exibida 3h na frente do horário real (ex.: mensagem mandada 15:22, sistema mostra
18:22 ou similar) — clássico de timestamp gravado/exibido em UTC quando devia ser
`America/Sao_Paulo`.

## Causa raiz já identificada (histórico)

Duas causas distintas, já resolvidas uma vez, que podem voltar se algo for alterado sem
seguir o mesmo padrão:

**1. Conexão do Postgres sem timezone configurado** — corrigido em
`config/database.php`, bloco da conexão `pgsql`:
```php
'timezone' => env('DB_TIMEZONE', 'America/Sao_Paulo'),
```
Sem isso, o Laravel exibe timestamps em UTC puro (a sessão do Postgres não aplica o fuso).
Pra confirmar que ainda está configurado:
```
grep -n "timezone" config/database.php
```

**2. `CarbonImmutable::createFromTimestamp()` sem segundo argumento** — essa função,
chamada SEM o fuso explícito, assume UTC internamente (independente do
`config('app.timezone')` do Laravel). Se a conexão do banco está em BRT (item 1) mas o
código grava um Carbon "ingênuo" em UTC, a mesma string de horário é reinterpretada como
BRT na gravação — desloca a hora em 3h de verdade (não é só exibição, corrompe o instante
gravado).

**Padrão correto**, já usado em `app/Services/Whatsapp/MessageStore.php:86`:
```php
CarbonImmutable::createFromTimestamp($message->timestamp, $this->timezone)
```
Sempre passar `config('app.timezone')` (ou equivalente) como segundo argumento.

## Como investigar um caso novo

1. Confirma que `config/database.php` ainda tem a linha `'timezone' => ...` na conexão
   `pgsql` (não foi revertida/removida).
2. Procura todo lugar que usa `createFromTimestamp` sem segundo argumento:
   ```
   grep -rn "createFromTimestamp" app/
   ```
   Qualquer ocorrência SEM o segundo argumento de timezone é suspeita.
3. Compara o valor bruto no banco (`SELECT sent_at FROM whatsapp_messages ...` — olha se tem
   sufixo `+00` ou `-03` no timestamp) com o horário que o usuário diz ser o real.
4. Rodar `php artisan config:clear` depois de qualquer mudança em `config/database.php`
   (o Laravel cacheia config).

## Depois de corrigir

Rodar a suíte completa (`php artisan test`) — o teste
`Tests\Feature\WhatsappMetaWebhookTest > string timestamp is converted` já pegou essa
regressão uma vez; se voltar a falhar com uma diferença de exatas 3h, é o mesmo bug.
