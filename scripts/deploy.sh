#!/usr/bin/env bash
# Deploy de produção (rodar NO servidor, dentro da pasta do projeto).
#   ./scripts/deploy.sh
# Faz: atualiza o código, builda a imagem, tira um backup do banco, roda as migrations
# e sobe/reinicia os containers. Nunca apaga volumes (banco, sessões do WhatsApp, mídia).
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env.production ]; then
    echo "Falta o .env.production (copie de .env.production.example e preencha)." >&2
    exit 1
fi

COMPOSE="docker compose --env-file .env.production -f docker-compose.prod.yml"

git pull --ff-only

$COMPOSE build

# Banco primeiro, porque o backup e as migrations precisam dele.
$COMPOSE up -d pgsql
until $COMPOSE exec -T pgsql sh -c 'pg_isready -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'; do
    sleep 2
done

# Backup antes de mexer no esquema (guardar uma cópia FORA do servidor também).
mkdir -p backups
STAMP="$(date +%Y%m%d_%H%M%S)"
$COMPOSE exec -T pgsql sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges -Fc' > "backups/pre-deploy_${STAMP}.dump"
echo "Backup salvo em backups/pre-deploy_${STAMP}.dump"

$COMPOSE run --rm app php artisan migrate --force

$COMPOSE up -d

# Faz o queue worker em execução recarregar o código novo.
$COMPOSE exec -T queue php artisan queue:restart || true

$COMPOSE ps
