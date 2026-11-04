#!/bin/sh
# Render starts the container on every cold start, so everything here is idempotent.
set -eu

php artisan migrate --force

# Only acts on an empty database: /setup answers on localhost alone, so a fresh
# deployment would otherwise have no administrator to sign in as.
php artisan crm:first-run

# Four demo accounts whose password is published in a public repository. A fresh
# database never has them, but a database copied up from a local install does.
php artisan crm:disable-demo-logins

# No route:cache: /setup, /account and /crm/branding are closure routes, which
# cannot be serialised.
php artisan config:cache
php artisan view:cache

exec frankenphp php-server --listen=":${PORT:-10000}" --root=/app/public
