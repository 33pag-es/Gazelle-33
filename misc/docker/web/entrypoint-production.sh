#!/bin/bash

set -euo pipefail

MYSQL_HOST=${MYSQL_HOST:-mysql}
MANTICORE_HOST=${MANTICORE_HOST:-manticore}
MANTICORE_PORT=${MANTICORE_PORT:-9306}

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
wait_for "$MANTICORE_HOST" "$MANTICORE_PORT"

mkdir -p /var/lib/gazelle /var/log/php /var/log/nginx /var/www/cache/twig
if [ ! -d /var/lib/gazelle/torrent ] \
    || [ ! -d /var/lib/gazelle/general ] \
    || [ ! -d /var/lib/gazelle/riplog ] \
    || [ ! -d /var/lib/gazelle/riploghtml ]; then
    echo "Initializing file storage..."
    perl /var/www/bin/generate-storage-dirs /var/lib/gazelle/general 1 100
    perl /var/www/bin/generate-storage-dirs /var/lib/gazelle/riplog 2 100
    perl /var/www/bin/generate-storage-dirs /var/lib/gazelle/riploghtml 2 100
    perl /var/www/bin/generate-storage-dirs /var/lib/gazelle/torrent 2 100
fi
chown -R gazelle:gazelle /var/lib/gazelle /var/log/php /var/www/cache/twig

nginx -t
crontab /var/www/misc/docker/web/crontab

echo "Start services..."
service cron start
service nginx start
service "php${PHP_VER}-fpm" start

touch /var/log/nginx/access.log
tail -f /var/log/nginx/access.log
