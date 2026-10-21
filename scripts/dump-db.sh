#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

CONTAINER="centercorp-pgsql-1"
OUT_DIR="./backups"
STAMP="$(date +%Y%m%d_%H%M%S)"

mkdir -p "$OUT_DIR"

docker compose up -d pgsql >/dev/null
echo "Aguardando o Postgres ficar pronto..."
until docker exec "$CONTAINER" pg_isready -q -U "${DB_USERNAME:-sail}"; do
    sleep 1
done

OUT_FILE="$OUT_DIR/centercorp_${STAMP}.dump"

docker exec "$CONTAINER" sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges -Fc' > "$OUT_FILE"

echo "Dump gerado em: $OUT_FILE"
