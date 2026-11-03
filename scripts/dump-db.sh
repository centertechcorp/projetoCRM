#!/usr/bin/env bash
# Backup do banco do ambiente de desenvolvimento (MariaDB). Grava em ./backups (ignorado pelo Git).
# Para o Postgres antigo: DUMP_DRIVER=pgsql ./scripts/dump-db.sh  (precisa do container pgsql ligado)
set -euo pipefail

cd "$(dirname "$0")/.."

OUT_DIR="./backups"
STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$OUT_DIR"

if [ "${DUMP_DRIVER:-mariadb}" = "pgsql" ]; then
    CONTAINER="centercorp-pgsql-1"
    docker compose --profile pgsql up -d pgsql >/dev/null
    until docker exec "$CONTAINER" pg_isready -q -U "${DB_USERNAME:-postgres}"; do sleep 1; done
    OUT_FILE="$OUT_DIR/centercorp_${STAMP}.dump"
    docker exec "$CONTAINER" sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges -Fc' > "$OUT_FILE"
else
    CONTAINER="${MARIADB_CONTAINER:-centercorp-mariadb-1}"
    docker compose up -d mariadb >/dev/null
    echo "Aguardando o MariaDB ficar pronto..."
    until docker exec "$CONTAINER" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 1; done
    OUT_FILE="$OUT_DIR/centercorp_mariadb_${STAMP}.sql"
    docker exec "$CONTAINER" sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' > "$OUT_FILE"
fi

echo "Dump gerado em: $OUT_FILE"
