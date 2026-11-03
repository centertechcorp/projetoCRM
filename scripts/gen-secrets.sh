#!/usr/bin/env bash
# Gera os segredos do .env.production. Só IMPRIME na tela — não grava nada em disco.
# Copie as linhas para o .env.production no servidor e não envie por canal inseguro.
set -euo pipefail

r() { openssl rand -hex "$1"; }

cat <<EOF
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=$(r 16)
DB_ROOT_PASSWORD=$(r 16)
WAHA_CENTER_API_KEY=$(r 24)
WAHA_GENIUS_API_KEY=$(r 24)
WAHA_WEBHOOK_HMAC_KEY=$(r 32)
WAHA_CENTER_DASHBOARD_PASSWORD=$(r 12)
WAHA_GENIUS_DASHBOARD_PASSWORD=$(r 12)
EOF
