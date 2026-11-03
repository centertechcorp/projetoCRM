#!/bin/sh
# Roda em app, queue e scheduler. Cacheia config/rotas/views com o .env DESTE container
# (por isso é feito na subida e não no build) e depois entrega pro entrypoint da imagem.
set -e

php artisan optimize || true

exec docker-php-entrypoint "$@"
