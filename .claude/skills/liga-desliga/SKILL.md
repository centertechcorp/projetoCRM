---
name: liga-desliga
description: Liga ou desliga todo o ambiente Docker do projeto (app, queue, scheduler, banco, WAHA center e genius) de uma vez. Use quando o usuário disser só "liga", "desliga", "desliga tudo", "desliga geral", "sobe tudo", ou peça pra ligar/desligar o ambiente inteiro, sem precisar de mais explicação.
---

# Ligar/desligar o ambiente inteiro

Dois `docker-compose.yml` separados — o principal e o do WAHA — então toda ação precisa
dos dois comandos, um em cada pasta.

## Desligar ("desliga", "desliga tudo", "desliga geral")

```
cd /home/mix/centercorp && docker compose stop
cd /home/mix/centercorp/waha && docker compose stop
docker ps --format "table {{.Names}}\t{{.Status}}"
```

Usar **`stop`**, nunca `down`: `stop` só para os containers, não remove nada — dados do
banco, sessões autenticadas do WAHA, tudo fica intacto pra religar depois exatamente como
estava. `down` removeria os containers (mesmo sem apagar volumes, é desnecessário e mais
lento pra religar).

Confirma no fim que `docker ps` voltou vazio.

## Ligar ("liga", "liga tudo", "sobe tudo")

```
cd /home/mix/centercorp && docker compose up -d
cd /home/mix/centercorp/waha && docker compose up -d
docker ps --format "table {{.Names}}\t{{.Status}}"
```

Depois de subir, dá uns segundos pros containers ficarem saudáveis (o `pgsql` tem
healthcheck) e pras sessões do WAHA reconectarem sozinhas. Confirma:

1. Os 6 containers com status "Up": `centercorp-laravel.test-1`, `centercorp-queue-1`,
   `centercorp-scheduler-1`, `centercorp-pgsql-1`, `waha-center`, `waha-genius`.
2. Sessões do WAHA voltaram `WORKING` (não precisa de novo QR code na maioria das vezes):
   ```
   docker exec waha-center sh -c 'curl -s -H "X-Api-Key: $WAHA_API_KEY" http://localhost:3000/api/sessions'
   docker exec waha-genius sh -c 'curl -s -H "X-Api-Key: $WAHA_API_KEY" http://localhost:3000/api/sessions'
   ```
   Se vier `"STOPPED"` em algum, dar mais uns segundos e checar de novo antes de avisar que
   tem problema.

## Não confundir com [[ambiente-status]]

Essa skill (`liga-desliga`) é só pra quando o usuário já decidiu a ação (ligar ou desligar).
Pra só checar se algo caiu sem querer, sem intenção de ligar/desligar nada, usar
`ambiente-status`.
