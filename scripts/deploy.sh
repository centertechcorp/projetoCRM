#!/usr/bin/env bash
# Deploy de produção (rodar NO servidor, dentro da pasta do projeto).
#   ./scripts/deploy.sh
# Faz: atualiza o código, builda a imagem, tira um backup do banco, roda as migrations
# e sobe/reinicia os containers. Nunca apaga volumes (banco, sessões do WhatsApp, mídia).
#
# O banco vem do .env.production:
#   DB_CONNECTION=pgsql                      → sobe o PostgreSQL do compose
#   DB_CONNECTION=mariadb + DB_HOST=mariadb  → sobe o MariaDB do compose
#   DB_CONNECTION=mariadb + outro DB_HOST    → MariaDB externo (o do servidor); nenhum banco sobe aqui
# O WAHA vem do .env.production:
#   WAHA_MODE=bundled (padrão) → sobe os dois WAHA (CENTER e GENIUS) junto
#   WAHA_MODE=external         → usa um WAHA que já existe (WAHA_*_BASE_URL / _API_KEY / _SESSION)
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env.production ]; then
    echo "Falta o .env.production (copie de .env.production.example e preencha)." >&2
    exit 1
fi

env_value() { grep -E "^$1=" .env.production | tail -n1 | cut -d= -f2- | tr -d '"' || true; }

DB_CONNECTION="$(env_value DB_CONNECTION)"
DB_HOST="$(env_value DB_HOST)"

case "${DB_CONNECTION}:${DB_HOST}" in
    pgsql:*)      export COMPOSE_PROFILES=pgsql;   LOCAL_DB=pgsql ;;
    mariadb:mariadb) export COMPOSE_PROFILES=mariadb; LOCAL_DB=mariadb ;;
    mariadb:*)    export COMPOSE_PROFILES="";      LOCAL_DB="" ;;
    *) echo "DB_CONNECTION inválido no .env.production: '${DB_CONNECTION}' (use pgsql ou mariadb)." >&2; exit 1 ;;
esac

WAHA_MODE="$(env_value WAHA_MODE)"
WAHA_MODE="${WAHA_MODE:-bundled}"
case "${WAHA_MODE}" in
    bundled)  COMPOSE_PROFILES="${COMPOSE_PROFILES:+${COMPOSE_PROFILES},}waha"; export COMPOSE_PROFILES ;;
    external) ;;
    *) echo "WAHA_MODE inválido no .env.production: '${WAHA_MODE}' (use bundled ou external)." >&2; exit 1 ;;
esac

if [ "${DB_CONNECTION}" = "mariadb" ] && [ "${DB_HOST}" = "TROQUE" ]; then
    echo "Preencha DB_HOST no .env.production (host do MariaDB)." >&2
    exit 1
fi

COMPOSE="docker compose --env-file .env.production -f docker-compose.prod.yml"

git pull --ff-only

$COMPOSE build

mkdir -p backups
STAMP="$(date +%Y%m%d_%H%M%S)"

if [ "${LOCAL_DB}" = "pgsql" ]; then
    # Banco primeiro, porque o backup e as migrations precisam dele.
    $COMPOSE up -d pgsql
    until $COMPOSE exec -T pgsql sh -c 'pg_isready -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'; do sleep 2; done
    # Backup antes de mexer no esquema (guardar uma cópia FORA do servidor também).
    $COMPOSE exec -T pgsql sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges -Fc' > "backups/pre-deploy_${STAMP}.dump"
    echo "Backup salvo em backups/pre-deploy_${STAMP}.dump"
elif [ "${LOCAL_DB}" = "mariadb" ]; then
    $COMPOSE up -d mariadb
    until $COMPOSE exec -T mariadb healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 2; done
    $COMPOSE exec -T mariadb sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' > "backups/pre-deploy_${STAMP}.sql"
    echo "Backup salvo em backups/pre-deploy_${STAMP}.sql"
else
    echo "MariaDB externo: faça o backup dele com o dev ANTES de continuar (este script não tem acesso)."
    read -r -p "Backup do banco externo feito? Digite 'sim' para continuar: " ok
    [ "${ok}" = "sim" ] || { echo "Deploy cancelado."; exit 1; }
fi

$COMPOSE run --rm app php artisan migrate --force

$COMPOSE up -d

# Faz o queue worker em execução recarregar o código novo.
$COMPOSE exec -T queue php artisan queue:restart || true

$COMPOSE ps

if [ "${WAHA_MODE}" = "external" ]; then
    echo
    echo "WAHA externo: confira a conexão e o webhook (só leitura) com:"
    echo "  $COMPOSE exec -T app php artisan whatsapp:waha:check"
fi
