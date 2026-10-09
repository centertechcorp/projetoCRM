#!/bin/sh
# Backup dos volumes de sessão do WAHA (o que guarda o login do WhatsApp — sem isso, perder
# o volume significa escanear o QR de novo em cada loja). Roda no host, fora do Laravel.
#
# Uso: ./backup-sessions.sh [pasta-destino]
# Agendar (cron do host, não do WSL): ex. todo dia às 3h.

set -eu

DEST="${1:-$(dirname "$0")/backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$DEST"

for VOLUME in waha-center-sessions waha-genius-sessions; do
    echo "Backup de $VOLUME..."
    docker run --rm \
        -v "${VOLUME}:/data:ro" \
        -v "$(cd "$DEST" && pwd):/backup" \
        alpine \
        tar czf "/backup/${VOLUME}-${STAMP}.tar.gz" -C /data .
done

echo "Pronto, salvo em $DEST"
