#!/bin/bash

set -euo pipefail

run_service()
{
    service "$1" start || exit 1
}

escape_replacement()
{
    printf '%s' "$1" | sed 's/[\\&|]/\\&/g'
}

MYSQL_USER_ESC=$(escape_replacement "${MYSQL_USER:-gazelle}")
MYSQL_PASSWORD_ESC=$(escape_replacement "${MYSQL_PASSWORD:-}")
MYSQL_DATABASE_ESC=$(escape_replacement "${MYSQL_DATABASE:-gazelle}")

# initialize Manticore
cp "${CI_PROJECT_DIR}/misc/docker/manticore/manticore.conf" /etc/manticoresearch/manticore.conf
sed -i "s|\(sql_host = \).*|\1${MYSQL_HOST:-mysql}|g" /etc/manticoresearch/manticore.conf
sed -i "s|\(sql_user = \).*|\1${MYSQL_USER_ESC}|g" /etc/manticoresearch/manticore.conf
sed -i "s|\(sql_pass = \).*|\1${MYSQL_PASSWORD_ESC}|g" /etc/manticoresearch/manticore.conf
sed -i "s|\(sql_db = \).*|\1${MYSQL_DATABASE_ESC}|g" /etc/manticoresearch/manticore.conf
sed -i "s|/var/lib/manticore/data/|${CI_PROJECT_DIR}/.manticore/|g" /etc/manticoresearch/manticore.conf
sed -i "s|listen = |\0127.0.0.1:|g" /etc/manticoresearch/manticore.conf
mkdir -p "${CI_PROJECT_DIR}/.manticore" /var/log/manticore /var/run/manticore
chown -R manticore:manticore "${CI_PROJECT_DIR}/.manticore" /var/log/manticore /var/run/manticore
setpriv --reuid manticore -- /usr/bin/indexer -c /etc/manticoresearch/manticore.conf --all
setpriv --reuid manticore -- /usr/bin/searchd --nodetach --config /etc/manticoresearch/manticore.conf \
    > /tmp/manticore.log 2>&1 &
until mysql -h 127.0.0.1 -P 9306 -e 'SELECT 1' >/dev/null 2>&1; do
    sleep 1
done

echo "Start services..."

run_service nginx
run_service "php${PHP_VER}-fpm"
