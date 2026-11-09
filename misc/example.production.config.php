<?php

// Copy these variables to your override.config.php in a production environment.
// Change as required.

define('SITE_NAME', 'Gazelle');
define('SITE_HOST', 'localhost');
define('SITE_LAUNCH_YEAR', 2018);

define('SOURCE_FLAG', 'GZL');

define('TMPDIR', '/tmp');

define('STORAGE_PATH_TORRENT',    '/var/lib/gazelle/torrent');
define('STORAGE_PATH_RIPLOG',     '/var/lib/gazelle/riplog');
define('STORAGE_PATH_RIPLOGHTML', '/var/lib/gazelle/riploghtml');

// Compose service name for Gazelle's internal calls to Ocelot.
define('TRACKER_HOST', 'ocelot');
define('TRACKER_NAME', 'tracker.example.com');
define('TRACKER_PORT', 34000);
define('TRACKER_PUBLIC_HOST', 'https://tracker.example.com');

// COMIC-ONLY: trust X-Forwarded-For only from the host reverse proxy, which
// reaches the containers from GAZELLE_GATEWAY in .env. This replaces the
// upstream Opera Turbo ranges.
define('ALLOWED_PROXY', ['172.30.33.254']);

define('SQLHOST', '10.0.0.1');
define('IRC_HOSTNAME', 'irc.example.com');

// If your database is on the same host as the PHP interpreter
define('MYSQL_SOCK', '/var/run/mysqld/mysqld.sock');

define('MYSQL_DB',      'changeme');
define('MYSQL_RW_USER', 'changeme');
define('MYSQL_RW_PASS', 'changeme');

define('MYSQL_RO_USER', 'changeme');
define('MYSQL_RO_PASS', 'changeme');

define('MYSQL_PHINX_USER', 'changeme');
define('MYSQL_PHINX_PASS', 'changeme');

define('PG_HOST',    'localhost');
define('PG_DB',      'changeme');
define('PG_RW_USER', 'changeme');
define('PG_RW_PASS', 'changeme');
define('PG_RO_USER', 'changeme');
define('PG_RO_PASS', 'changeme');

define('SPHINX_HOST', 'manticore.example.com');
define('SPHINXQL_HOST', SPHINX_HOST);

// If Manticore is running on the same host as the PHP interpreter
define('SPHINXQL_SOCK', '/var/run/manticore/manticore.sock');

define('TRACKER_SECRET',    '0123456789abcdef0123456789abcdef');
define('TRACKER_REPORTKEY', '0123456789abcdef0123456789abcdef');

define('PASSWORD_PEPPER', 'changeme');
define('ENCKEY',          'changeme');
define('AUTHKEY',         'changeme');
define('RSS_HASH',        'changeme');
define('SEEDBOX_SALT',    'changeme');
define('AVATAR_SALT',     'changeme');

define('PARANOIA_PREVIEW_USER', 1);

define('IMAGE_HOST_BANNED', ['badhost.example.com']);
define('IMAGE_HOST_RECOMMENDED', ['goodhost.example.com']);
