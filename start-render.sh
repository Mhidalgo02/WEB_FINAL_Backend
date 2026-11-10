#!/bin/sh
set -eu

PORT="${PORT:-10000}"
case "$PORT" in ''|*[!0-9]*) echo 'PORT must be numeric.' >&2; exit 1;; esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then echo 'PORT is out of range.' >&2; exit 1; fi

for setting in DB_HOST DB_PORT DB_NAME DB_USERNAME DB_PASSWORD APP_KEY DB_SSL_CA; do
    # Read only the named variable. Do not log secret values.
    value="$(printenv "$setting" || true)"
    if [ -z "$value" ]; then echo "Missing Render environment variable: $setting" >&2; exit 1; fi
done
if [ "${#APP_KEY}" -lt 32 ]; then echo 'APP_KEY needs at least 32 characters.' >&2; exit 1; fi
if [ ! -s "$DB_SSL_CA" ]; then echo 'Aiven CA file is missing; add ca.pem as a Render secret file.' >&2; exit 1; fi

# Render mounts the persistent disk before this script runs.
for directory in cache logs session private_uploads private_payments; do
    mkdir -p "runtime/$directory"
done
chown -R www-data:www-data runtime
chmod 700 runtime/private_uploads runtime/private_payments runtime/session

printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
sed -i "s/__PORT__/$PORT/g" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground

