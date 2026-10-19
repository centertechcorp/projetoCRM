---
name: ambiente-status
description: Verifica se algum container do ambiente (laravel.test, queue, scheduler, pgsql, waha-center, waha-genius) caiu ou reiniciou, e se as sessões do WAHA ainda estão autenticadas. Use quando o usuário perguntar se algo caiu, se as lojas caíram, se o ambiente está de pé, ou relatar que algo parou de funcionar sem saber o motivo.
---

# Status geral do ambiente

## Containers esperados

```
docker ps --format "table {{.Names}}\t{{.Status}}"
```

Os 6 que devem estar "Up": `centercorp-laravel.test-1`, `centercorp-queue-1`,
`centercorp-scheduler-1`, `centercorp-pgsql-1`, `waha-center`, `waha-genius`.

## Se algum tiver pouco tempo de uptime (reiniciou recentemente)

```
docker inspect <nome-do-container> --format '{{.State.StartedAt}} restarts={{.RestartCount}}'
```

- `restarts=0` com `StartedAt` recente e **todos** os containers nessa mesma situação ao
  mesmo tempo → o Docker/WSL inteiro reiniciou (já aconteceu — não é um problema isolado de
  um serviço, é o ambiente todo caindo e voltando junto).
- Só um container com uptime baixo e os outros de pé há mais tempo → esse serviço específico
  caiu e reiniciou (ou teve `OOMKilled`/crash — vale checar `docker logs <nome> --tail 50`).

## Sessões do WAHA (podem estar "Up" mas desconectadas do WhatsApp)

Contêiner de pé não garante sessão autenticada. Confirma via API, usando a key de dentro do
próprio container (não do `.env` do host, que não bate 1:1 com o nome da variável):

```
docker exec waha-center sh -c 'curl -s -H "X-Api-Key: $WAHA_API_KEY" http://localhost:3000/api/sessions'
docker exec waha-genius sh -c 'curl -s -H "X-Api-Key: $WAHA_API_KEY" http://localhost:3000/api/sessions'
```

Procura `"status":"WORKING"`. Se vier `"STOPPED"` ou outro status, ou os logs mostrarem
`Session has been authenticated!` recente (reconectou sozinho) — isso é normal depois de um
restart, não precisa escanear QR code de novo na maioria dos casos.

## Scheduler: ver também [[scheduler-catchup]]

Se o container do scheduler tiver caído, além de confirmar que ele voltou, checa se algum
agendamento (`whatsapp:leads:detect` das 18h) foi perdido no meio tempo — segue o roteiro da
skill `scheduler-catchup`.
