#!/usr/bin/env bash

set -euo pipefail

CONF_TEMPLATE=/etc/manticoresearch/manticore.conf
CONF=/var/lib/manticore/manticore.conf
MYSQL_HOST=${MYSQL_HOST:-mysql}
MYSQL_USER=${MYSQL_USER:-gazelle}
MYSQL_PASSWORD=${MYSQL_PASSWORD:?MYSQL_PASSWORD must be set}
MYSQL_DATABASE=${MYSQL_DATABASE:-gazelle}

escape_replacement()
{
    printf '%s' "$1" | sed 's/[\\&|]/\\&/g'
}

MYSQL_HOST_ESC=$(escape_replacement "$MYSQL_HOST")
MYSQL_USER_ESC=$(escape_replacement "$MYSQL_USER")
MYSQL_PASSWORD_ESC=$(escape_replacement "$MYSQL_PASSWORD")
MYSQL_DATABASE_ESC=$(escape_replacement "$MYSQL_DATABASE")

mysql_wait()
{
    until mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e 'SELECT 1' >/dev/null 2>&1; do
        sleep 1
        echo "Waiting for MySQL" >&2
    done
}

schema_wait()
{
    until [ "$(mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" \
            -Nse "SELECT COUNT(*) FROM information_schema.tables \
                WHERE table_schema = '$MYSQL_DATABASE' \
                AND table_name IN ('sphinx_tg', 'sphinx_t', 'sphinx_a', 'sphinx_delta')" 2>/dev/null \
            | tr -dc '0-9')" = '4' ]; do
        sleep 1
        echo "Waiting for Gazelle search schema" >&2
    done
}

mkdir -p /var/lib/manticore/data /var/log/manticore /var/run/manticore
chown -R manticore:manticore /var/lib/manticore /var/log/manticore /var/run/manticore

mysql_wait
schema_wait

sed \
    -e "s|^\( *sql_host = \).*|\1${MYSQL_HOST_ESC}|" \
    -e "s|^\( *sql_user = \).*|\1${MYSQL_USER_ESC}|" \
    -e "s|^\( *sql_pass = \).*|\1${MYSQL_PASSWORD_ESC}|" \
    -e "s|^\( *sql_db = \).*|\1${MYSQL_DATABASE_ESC}|" \
    "$CONF_TEMPLATE" > "$CONF"
chown manticore:manticore "$CONF"

gosu manticore indexer -c "$CONF" --all

cron -f &
exec gosu manticore searchd --nodetach --config "$CONF"
