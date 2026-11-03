#!/usr/bin/env bash
# Teste de fumaça pós-deploy. Não grava mensagem nem lead (usa um evento que o job ignora).
#   ./scripts/smoke-test.sh https://crm.seudominio.com.br  <WAHA_WEBHOOK_HMAC_KEY>
set -uo pipefail

URL="${1:?Uso: smoke-test.sh URL HMAC_KEY}"
KEY="${2:?Uso: smoke-test.sh URL HMAC_KEY}"
URL="${URL%/}"
fail=0

check() { # descrição, esperado, obtido
    if [ "$2" = "$3" ]; then echo "  ok   $1 ($3)"; else echo "  FALHA $1: esperado $2, veio $3"; fail=1; fi
}

BODY='{"event":"smoke.test","session":"CENTER","payload":{}}'
SIG="$(printf '%s' "$BODY" | openssl dgst -sha512 -hmac "$KEY" | awk '{print $NF}')"
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$@"; }

check "saúde (/up)" 200 "$(code "$URL/up")"
check "tela de login" 200 "$(code "$URL/login")"
check "painel sem login redireciona" 302 "$(code "$URL/painel")"
check "webhook SEM assinatura é recusado" 401 "$(code -X POST -H 'Content-Type: application/json' -d "$BODY" "$URL/api/whatsapp/waha/center")"
check "webhook com assinatura errada é recusado" 401 "$(code -X POST -H 'Content-Type: application/json' -H 'X-Webhook-Hmac: errada' -d "$BODY" "$URL/api/whatsapp/waha/center")"
check "webhook assinado é aceito" 200 "$(code -X POST -H 'Content-Type: application/json' -H "X-Webhook-Hmac: $SIG" -d "$BODY" "$URL/api/whatsapp/waha/center")"
check "rota de teste do WAHA não existe" 404 "$(code -X POST "$URL/api/whatsapp/waha-test/center")"
case "$URL" in https://*) check "HTTP redireciona para HTTPS" 308 "$(code "http://${URL#https://}/up")";; esac

[ "$fail" = 0 ] && echo "Tudo certo." || { echo "Há falhas acima."; exit 1; }
