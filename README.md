# Gazelle

Gazelle is a web framework geared towards private BitTorrent trackers.
Although naturally focusing on music, it can be modified for most
needs. Gazelle is written in PHP, Twig and JavaScript. It traditionally
uses Mysql and Manticore Search (SphinxQL), but work is under way to
replace those with Postgresql.

## Gazelle Runtime Dependencies
* [PHP 8.5.9+](https://www.php.net/) (required)
* [PostgreSQL 17](https://www.postgresql.org/) (required)
* [MySQL 8.4](https://www.mysql.com/) (required)
* [nginx](http://wiki.nginx.org/Main) (required)
* [nodeJS 20+](https://nodejs.org/en/) (required)
* [memcached](http://memcached.org/) (required)
* [Manticore Search 29.9.0+](https://manticoresearch.com/) (required, SphinxQL-compatible)
* [ocelot](https://github.com/OPSnet/Ocelot) (optional)

## Logchecker
To fully utilize the Logchecker, you must install the following
depedencies through `pip`:
* chardet
* eac-logchecker
* xld-logchecker

## Installation

We provide installation notes [here](docs/INSTALL.txt). These notes are provided
as a best effort, and are not guaranteed to be fully up-to-date or accurate.
Looking at the Dockerfile and docker-compose.yml files and following the order
should clear up most questions.

Due to the nature of torrenting, we HIGHLY recommend not trying to run this in
production if you are not prepared or knowledgeable in setting up servers,
proxies, and tuning TCP configs to obtain proper performance and privacy.

## Production
<!-- COMIC-ONLY: production stack and image. -->
`Dockerfile.production` builds a runtime-only image. It contains PHP,
nginx, cron, Composer dependencies, and compiled stylesheets; it does not
contain Node, Cypress, Chromium, Xdebug, Python, shellcheck, or a source
bind mount.

`docker-compose.prod.yml` runs migrations once, then starts web,
Manticore, MySQL, PostgreSQL, and memcached. Only the web port is
published. Database and search ports stay on the private Compose network.

```bash
cp .env.example .env
cp misc/example.production.config.php lib/override.config.php
# Edit both files. The database/search hosts are the Compose service names:
# mysql, pg, manticore, memcached.
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f migrate
```

`MYSQL_PHINX_USER`/`MYSQL_PHINX_PASSWORD` create the DDL-capable migration
account. `MYSQL_RO_USER`/`MYSQL_RO_PASSWORD` create the read-only account.
Both must match the corresponding constants in `override.config.php`.

The production configuration is mounted read-only; the build does not
include `lib/override.config.php`.

Back up `gazelle-data`, `mysql-data`, `pg-data`, and `manticore-data`.
The `migrate` service exits successfully after all three migration phases;
`web` waits for it. It runs again on the next `up`, so take a database
backup before changing the image.

### Reverse proxy and client IP

Terminate TLS at a reverse proxy on the host.
`misc/caddy/Caddyfile.example` is a complete Caddy configuration for the
site and the tracker announce endpoint; Caddy obtains the certificates.

- `web` is published on `WEB_BIND:WEB_PORT`, default `127.0.0.1:8080`.
  Keep it off 80/443, which the proxy listens on; Docker cannot bind a port
  the proxy already holds. Docker publishes ports past host firewalls such
  as ufw, so do not bind it publicly.
- The Compose network is pinned (`GAZELLE_SUBNET`, `GAZELLE_GATEWAY`), so
  the host proxy reaches the containers from `172.30.33.254`. Set
  `ALLOWED_PROXY` in `override.config.php` to that address, as in
  `misc/example.production.config.php`. Without it every user appears to
  come from the gateway, and login throttling and IP bans hit everyone.
- The proxy must replace `X-Forwarded-For` with the client address, not
  append to it. Gazelle accepts the header only when it is one public IP
  from `ALLOWED_PROXY`; Ocelot uses its first entry. Caddy's default
  `reverse_proxy` replaces it. With nginx, use
  `proxy_set_header X-Forwarded-For $remote_addr;`, not
  `$proxy_add_x_forwarded_for`.
- Expose only `/<passkey>/announce` and `/<passkey>/scrape` on the tracker
  host; Ocelot's `update` and `report` actions are for internal calls.
- If Cloudflare proxies the site, use `misc/caddy/Caddyfile.cloudflare.example`
  instead. It trusts `CF-Connecting-IP` only from Cloudflare's published
  ranges and still sends one address upstream, so `ALLOWED_PROXY` does not
  change. Keep the tracker hostname DNS-only. Because that record resolves
  to the server, the origin address is public: Cloudflare hides the site's
  traffic but cannot stop direct traffic to the server unless the tracker
  runs on a separate IP.

### Ocelot tracker overlay

Ocelot is not started by the base production stack. To co-locate it with
Gazelle and MySQL:

```bash
cp ../Ocelot/.env.example ../Ocelot/.env
# Set the Ocelot DB credentials and 32-character tracker secrets, then keep
# the matching values in .env and lib/override.config.php.
docker compose -f docker-compose.prod.yml -f ../Ocelot/docker-compose.prod.yml up -d --build
```

The overlay builds from the sibling Ocelot checkout, loads
`../Ocelot/.env` through `env_file`, and publishes Ocelot on
`TRACKER_BIND:TRACKER_PORT` (default `127.0.0.1:34000`). Set `OCELOT_IMAGE`
to use a prebuilt image. The Gazelle production MySQL init creates the
optional Ocelot account with per-table grants matching Ocelot's queries,
including `DROP` on `xbt_files_users` for its startup `TRUNCATE`; it cannot
modify `users_main` or other site tables. In
`lib/override.config.php`, use `TRACKER_HOST=ocelot` and
`TRACKER_PORT=34000` for Gazelle's internal calls; set
`TRACKER_PUBLIC_HOST` to the external announce URL. Ocelot trusts
`X-Forwarded-For`, so keep it on loopback behind the announce TLS proxy. If
you publish it directly (`TRACKER_BIND=0.0.0.0`), set `OCELOT_IGNORE_XFF=1`
in `../Ocelot/.env`. `TRACKER_SECRET` and
`TRACKER_REPORTKEY` must match Ocelot’s `site_password` and
`report_password` and the values in `lib/override.config.php`.

## Development
<!-- COMIC-ONLY: fork-specific host and development policy. -->
The host machine is a thin client. Do not install PHP, Composer, Node,
npm, Cypress, MySQL, PostgreSQL, or Manticore on the host. Every command in
this section runs inside the `web` container; `docker compose exec -T web`
is the canonical prefix. Makefile targets are container-only.

The fork's customization and upstream-merge policy is in `AGENTS.md`.
The comics-only target model and retrofit order are in `docs/COMICS.md`.

Docker is used to develop Gazelle. See https://docs.docker.com/engine/install/
for more information on getting Docker set up locally.

### Gazelle
In the root folder of the Gazelle repository, run the following command:

`docker compose up -d`

This will pull and build the needed images to run Gazelle on Debian.
A volume is mounted from the base of the git repository at
`/var/www` in the container. Changes to the source code are
immediately served without rebuilding or restarting.

If you receive the error "http: invalid Host header", run the following

`DOCKER_BUILDKIT=0 docker compose up -d`

You can access the site by viewing `http://localhost:7001/`

Follow the "Register" link on the homepage to create the first account.
The first account created is assigned the highest operational role
(Sysop) automatically.

The Sysop account might not have all the permissions that have
been added recently. Navigate to the /tools.php?action=userclass
page and tick everything for the Sysop class.

Once the Gazelle container has been built successfully, it may be
stopped and subsequently restarted without requiring connectivity
with the outside world. This also means that composer and npm are
not automatically updated during a container restart and is a manual
chore to perform periodically.

### Ocelot
The [ocelot](https://github.com/OPSnet/Ocelot) repository is used to build the
Ocelot image. To keep things simple, check out the source in a sibling
directory to Gazelle.

```bash
$ git clone https://github.com/OPSnet/ocelot
$ cd ocelot
$ docker build . -t ocelot
```

Ocelot can be launched by specifying an additional configuration file:

`docker compose -f docker-compose.yml -f misc/docker-compose.ocelot.yml up -d`

### Ports
The following ports are forwarded:
* 80 -> 7001 (web)
* 3306 -> 36000 (mysql)
* 5432 -> 54321 (postgresql)
* 34000 -> 34000 (ocelot if present)

## Going further
If you want to poke around inside the web container, open a shell:

`docker compose exec web bash`

To keep an eye on PHP errors during development:

`docker exec -it $WEBCONT tail -n 20 -f /var/log/nginx/error.log`

To create a Phinx migration:

`docker compose exec -T web vendor/bin/phinx create MyNewMigration`

Edit the resulting file and then apply it:

`docker compose exec -T web vendor/bin/phinx migrate`

For PostgreSQL tables, append `-c ./misc/phinx-pg.php` to the phinx
commands. The following bash function makes it much easier to work with
phinx:

```
function pg-phinx { docker compose exec web vendor/bin/phinx $@ -c misc/phinx-pg.php; }

pg-phinx status
pg-phinx create MyNewMigration
pg-phinx migrate
pg-phinx rollback
```


To access the database, look at `misc/docker/mysql-home/.my.cnf`
The credentials should match those used in the `docker-compose.yml` file.

And then:

`docker compose exec mysql mysql`

In the same vein, you can use `mysqldump` to perform a backup.

To view the Manticore tables:

`docker compose exec manticore mysql -h 127.0.0.1 -P 9306`

#### Boris
You can run Boris directly:

`docker compose exec web bin/boris`

#### Gitlab CI testrunner
`misc/gitlab.Dockerfile` can be used to create a Docker container suitable for
running the test suite in a Gitlab CI runner. The included `.gitlab-ci.yml`
config runs unit tests with phpunit and afterwards end-to-end tests with
cypress.

To build the container, get the sql files from the OPSnet/gazelle-e2e-testing-docker
repo and place them alongside the `gitlab.Dockerfile`. Then run

    docker build -f misc/gitlab.Dockerfile -t gazelle-e2e-testing:latest --compress .

in the gazelle repo's root directory (this one).

Similarly, the phpstan container can be built with

    docker build -t gazelle-phpstan:latest -f misc/phpstan.Dockerfile --compress misc/docker

## Contact and Discussion
Feel free to join #develop on irc.orpheus.network to discuss any
questions concerning Gazelle (or any of the repos published by
Orpheus).

## Open source
Run `bin/setup-git-hooks` once, to install a git precommit hook that will run linting
checks on PHP, JS, Twig and CSS files at each commit.

Create issues at https://github.com/OPSnet

Patches welcome! AI not welcome, this application is crafted by hand and each
line is understood.
