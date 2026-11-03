#!/usr/bin/env bash
# Backup do MariaDB do ambiente de desenvolvimento (espelha scripts/dump-db.sh, que é do Postgres).
# Em produção o backup pré-deploy é feito pelo scripts/deploy.sh.
set -euo pipefail

cd "$(dirname "$0")/.."

CONTAINER="${MARIADB_CONTAINER:-centercorp-mariadb-1}"
DATABASE="${MARIADB_DATABASE:-testing}"
OUT_DIR="./backups"
STAMP="$(date +%Y%m%d_%H%M%S)"

mkdir -p "$OUT_DIR"

docker compose up -d mariadb >/dev/null
echo "Aguardando o MariaDB ficar pronto..."
until docker exec "$CONTAINER" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
    sleep 1
done

OUT_FILE="$OUT_DIR/centercorp_mariadb_${STAMP}.sql"

docker exec "$CONTAINER" sh -c "mariadb-dump -uroot -p\"\$MARIADB_ROOT_PASSWORD\" --single-transaction --routines --triggers $DATABASE" > "$OUT_FILE"

echo "Dump gerado em: $OUT_FILE"
