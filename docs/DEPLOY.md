# Colocar o CRM no ar (servidor com MariaDB)

Tudo roda com Docker: CRM (app + fila + agendador), os dois WAHA (CENTER e GENIUS) e, se o servidor
não tiver um, o banco. O banco do CRM pode ser o MariaDB do próprio servidor (recomendado aqui) ou um
do compose.

## 1. O que o servidor precisa ter (conferir antes)

| Item | Detalhe |
|---|---|
| Docker + Compose v2 | `docker compose version` |
| Memória | ~4 GB livres (cada WAHA usa 1,1 a 1,5 GB) |
| Portas 80 e 443 | livres para o proxy HTTPS (Caddy) |
| Domínio | `crm.seudominio.com.br` apontando para o IP do servidor (certificado HTTPS é automático) |
| MariaDB | versão **10.6 ou mais nova** |
| Banco e usuário | banco vazio `crm` (utf8mb4 / utf8mb4_unicode_ci) e um usuário com todos os privilégios **só nele** |

Conferir no MariaDB (o que o dev precisa responder):

```sql
SELECT VERSION();
SELECT @@sql_mode, @@time_zone, @@system_time_zone;
SHOW VARIABLES LIKE 'max_allowed_packet';   -- ideal: 64M ou mais
```

- `sql_mode` com `STRICT_TRANS_TABLES` é o esperado (os testes rodam assim).
- O CRM grava horários no fuso do app (`America/Sao_Paulo`); quem abrir o banco direto verá horário de Brasília.

## 2. Primeira instalação

```bash
git clone https://github.com/centertechcorp/projetoCRM.git && cd projetoCRM
cp .env.production.example .env.production
./scripts/gen-secrets.sh        # imprime os segredos; cole no .env.production
```

No `.env.production` preencher: `APP_URL`, `SERVER_NAME` (o domínio), `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`
(do MariaDB do dev) e os segredos gerados. Depois:

```bash
./scripts/deploy.sh
```

O script escolhe o banco pelo `DB_CONNECTION`/`DB_HOST`, faz backup (no MariaDB externo ele pede a
confirmação de que o dev fez o backup), roda as migrations e sobe tudo. Nunca apaga volumes.

Criar o primeiro usuário dono e as lojas/contas do WhatsApp (se o banco começar vazio) — ou restaurar os
dados do ambiente atual (passo 4).

## 3. Testar

```bash
./scripts/smoke-test.sh https://crm.seudominio.com.br <WAHA_WEBHOOK_HMAC_KEY>
```

Tudo precisa dar "ok". Depois abrir o CRM no navegador e entrar.

## 4. Levar os dados do ambiente atual (Postgres → MariaDB)

Feito uma única vez, de um computador que enxerga o Postgres antigo (`docker compose --profile pgsql up -d pgsql`)
e o MariaDB de destino. Hoje o ambiente local já roda em MariaDB; o servidor precisa receber os dados
ou pela cópia abaixo ou restaurando um dump do MariaDB local (`./scripts/dump-db.sh`).
O MariaDB de destino precisa estar **vazio e já migrado**.

```bash
DUMP_DRIVER=pgsql ./scripts/dump-db.sh                 # backup do Postgres antigo (guardar)
# no .env: MARIADB_TARGET_HOST / _PORT / _DATABASE / _USERNAME / _PASSWORD
php artisan migrate --database=mariadb_target --force
php artisan db:copy-pg-to-mariadb --dry-run             # só conta, não escreve
php artisan db:copy-pg-to-mariadb                       # copia e confere (ou desfaz tudo se falhar)
```

Só o Postgres é lido; o comando recusa destino com dados e confere as contagens no fim.
Ensaiado com os dados reais: todas as tabelas bateram e o Painel ficou igual.

## 5. WhatsApp (WAHA) no servidor novo

As sessões do WhatsApp **não vão junto**: é preciso escanear o QR de novo em cada número.

1. O painel do WAHA só escuta no próprio servidor. Do seu computador: `ssh -L 3001:127.0.0.1:3001 -L 3002:127.0.0.1:3002 usuario@servidor`.
2. Abrir `http://localhost:3001` (CENTER) e `http://localhost:3002` (GENIUS); login `admin` + a senha do `.env.production`.
3. Iniciar a sessão e escanear o QR com o celular de cada loja (WhatsApp → Aparelhos conectados).
4. Desligar o WAHA do computador antigo para não haver dois conectados ao mesmo número.

O webhook já aponta para `http://app/api/whatsapp/waha/{center|genius}` dentro da rede do Docker — não precisa mudar nada.

## 6. Voltar atrás (se algo der errado)

- O ambiente antigo (computador) segue intacto até você decidir desligá-lo.
- Backups do banco ficam em `backups/` no servidor (`pre-deploy_*.sql`/`.dump`) — guardar uma cópia fora dele.
- Para reverter o código: `git checkout <commit anterior> && ./scripts/deploy.sh`.

## 7. Diferenças conhecidas entre Postgres e MariaDB

- Busca no Painel ignora acento no MariaDB ("jose" acha "José"); no Postgres não ignorava.
- `body` das mensagens é `longText` (cabe mais que 64 KB; já apareceu uma foto em base64 no texto).
- Lead aberto único por cliente+loja é garantido por uma coluna calculada `open_key` no MariaDB.
