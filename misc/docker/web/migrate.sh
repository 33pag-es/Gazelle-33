#!/bin/bash

set -euo pipefail

MYSQL_HOST=${MYSQL_HOST:-mysql}
PG_HOST=${PG_HOST:-pg}

if [ ! -f /var/www/lib/override.config.php ]; then
    echo "Missing /var/www/lib/override.config.php; mount the production configuration." >&2
    exit 1
fi

wait_for()
{
    local host=$1
    local port=$2
    until nc -z "$host" "$port"; do
        echo "Waiting for ${host}:${port}" >&2
        sleep 2
    done
}

wait_for "$MYSQL_HOST" 3306
wait_for "$PG_HOST" 5432

PHINXBIN=/var/www/vendor/bin/phinx

echo "Phase 1 MySQL migrations..."
FKEY_MY_DATABASE=1 LOCK_MY_DATABASE=1 "$PHINXBIN" migrate -e gazelle

echo "PostgreSQL migrations..."
"$PHINXBIN" migrate -c /var/www/misc/phinx-pg.php

echo "Phase 2 MySQL migrations..."
"$PHINXBIN" migrate -c /var/www/misc/my2-phinx.php
