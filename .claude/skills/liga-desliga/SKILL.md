---
name: liga-desliga
description: Liga ou desliga o ambiente Docker LOCAL do projeto (app, queue, scheduler e banco MariaDB) de uma vez. O WhatsApp (WAHA CENTER e GENIUS) NÃO roda mais aqui — mudou para o servidor do Chico. Use quando o usuário disser só "liga", "desliga", "desliga tudo", "desliga geral", "sobe tudo", ou peça pra ligar/desligar o ambiente inteiro, sem precisar de mais explicação.
---

# Ligar/desligar o ambiente local

Desde 13/11/2026 os dois WAHA (CENTER e GENIUS) rodam no servidor do Chico e é lá que o CRM
de verdade grava as mensagens. O ambiente local é só uma cópia de desenvolvimento (dados até
10/11), então **o WAHA local não é mais ligado**. A pasta `waha/` continua no repositório, mas
subir os WAHA daqui conecta o mesmo número em dois lugares e duplica as mensagens.

## Desligar ("desliga", "desliga tudo", "desliga geral")

```
cd /home/mix/centercorp && docker compose stop
docker ps --format "table {{.Names}}\t{{.Status}}"
```

Se algum WAHA local ainda estiver de pé (`waha-center`, `waha-genius`), parar também, de dentro
da pasta `waha`:

```
cd /home/mix/centercorp/waha && docker compose stop
```

Usar **`stop`**, nunca `down`: `stop` só para os containers, não remove nada — dados do
banco ficam intactos pra religar depois exatamente como estava. `down` removeria os containers
(mesmo sem apagar volumes, é desnecessário e mais lento pra religar).

Confirma no fim que `docker ps` voltou vazio.

## Ligar ("liga", "liga tudo", "sobe tudo")

```
cd /home/mix/centercorp && docker compose up -d
docker ps --format "table {{.Names}}\t{{.Status}}"
```

**Não subir a pasta `waha/`** (nem `docker start waha-center` / `waha-genius`): o WhatsApp das
duas lojas está conectado no servidor. Se o usuário pedir explicitamente pra religar o WAHA
local, avisar antes que isso duplica o número e só seguir com a confirmação dele.

Depois de subir, dá uns segundos pro banco ficar saudável (o `mariadb` tem healthcheck).
Confirma os 4 containers com status "Up": `centercorp-laravel.test-1`, `centercorp-queue-1`,
`centercorp-scheduler-1`, `centercorp-mariadb-1` (este último "healthy").

## Onde está o WhatsApp agora

- O painel dos WAHA do servidor só abre por túnel SSH:
  `ssh -p 8557 -L 3011:127.0.0.1:3001 -L 3012:127.0.0.1:3002 fsntico@179.108.183.191`
  → `http://localhost:3011` (CENTER) e `http://localhost:3012` (GENIUS).
- O CRM do servidor (porta 9898 de lá) abre por outro túnel, por exemplo
  `-L 9900:127.0.0.1:9898` → `http://localhost:9900`.

## Não confundir com [[ambiente-status]]

Essa skill (`liga-desliga`) é só pra quando o usuário já decidiu a ação (ligar ou desligar).
Pra só checar se algo caiu sem querer, sem intenção de ligar/desligar nada, usar
`ambiente-status`.
