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
Production runs on two servers joined by a WireGuard tunnel:

| | Site server (this repository) | Tracker server (`../Ocelot`) |
|---|---|---|
| Runs | Gazelle, MySQL, PostgreSQL, Manticore, memcached, Caddy | Ocelot, Caddy |
| Public DNS | site hostname, proxied by Cloudflare or a plain A record | tracker hostname, A record (DNS-only) |
| Tunnel address | `10.33.0.1` (`GAZELLE_TUNNEL_IP`) | `10.33.0.2` (`TRACKER_TUNNEL_IP`) |

Over the tunnel only: Ocelot reads and writes MySQL (`10.33.0.1:3306`),
Gazelle calls Ocelot (`10.33.0.2:34000`), and Ocelot calls Gazelle's
freeleech-token callback (`10.33.0.1:8080`). Put both servers in the same
region; Ocelot's MySQL latency is its critical path. Everything is
IPv4-only.

`Dockerfile.production` builds a runtime-only image. It contains PHP,
nginx, cron, Composer dependencies, and compiled stylesheets; it does not
contain Node, Cypress, Chromium, Xdebug, Python, shellcheck, or a source
bind mount.

### Site server

1. WireGuard: copy `misc/wireguard/wg0.conf.example` to
   `/etc/wireguard/wg0.conf`, fill it in, and
   `systemctl enable --now wg-quick@wg0`.
2. Start Docker after the tunnel (see “Boot order” below).
3. Configure and start the stack:

```bash
cp .env.example .env
cp misc/example.production.config.php lib/override.config.php
# Edit both files. The database/search hosts are the Compose service names:
# mysql, pg, manticore, memcached. TRACKER_HOST is the tracker's tunnel IP.
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f migrate
```

`docker-compose.prod.yml` runs migrations once, then starts web,
Manticore, MySQL, PostgreSQL, and memcached. Web is published on loopback
for Caddy. MySQL and a second web port are published only on
`GAZELLE_TUNNEL_IP`; PostgreSQL, Manticore and memcached are not published.

`MYSQL_PHINX_USER`/`MYSQL_PHINX_PASSWORD` create the DDL-capable migration
account. `MYSQL_RO_USER`/`MYSQL_RO_PASSWORD` create the read-only account.
Both must match the corresponding constants in `override.config.php`.
MySQL's first start also creates the Ocelot account
(`OCELOT_MYSQL_USER`/`OCELOT_MYSQL_PASSWORD`). It can log in only from
`TRACKER_TUNNEL_IP` and has per-table grants matching Ocelot's queries,
including `DROP` on `xbt_files_users` for its startup `TRUNCATE`; it cannot
modify `users_main` or other site tables. The init script runs only on an
empty data volume.

`TRACKER_SECRET` and `TRACKER_REPORTKEY` in `override.config.php` must match
the tracker server's `.env`.

The production configuration is mounted read-only; the build does not
include `lib/override.config.php`.

Back up `gazelle-data`, `mysql-data`, `pg-data`, and `manticore-data`.
The `migrate` service exits successfully after all three migration phases;
`web` waits for it. It runs again on the next `up`, so take a database
backup before changing the image.

### Tracker server

See `../Ocelot/README.md`: WireGuard (`docker/wg0.conf.example`), `.env`,
`docker-compose.prod.yml`, and Caddy (`docker/Caddyfile.example`).

### Boot order

On both servers Docker publishes ports on the tunnel address. If Docker
starts before `wg0` exists, those containers fail to start and are not
retried. Order Docker after WireGuard:

```bash
mkdir -p /etc/systemd/system/docker.service.d
printf '[Unit]\nWants=wg-quick@wg0.service\nAfter=wg-quick@wg0.service\n' \
    > /etc/systemd/system/docker.service.d/wireguard.conf
systemctl daemon-reload
```

The tunnel address exists as soon as `wg0` is up, even if the other server
is down.

### Firewall

Docker-published ports bypass ufw; that is why the stacks bind only to
loopback and tunnel addresses. Firewall the host services:

- Site server: SSH; `51820/udp` from the tracker's public IP; `443/tcp`
  from Cloudflare's ranges only (https://www.cloudflare.com/ips/), or from
  anywhere, plus `80/tcp` for certificates, without Cloudflare.
- Tracker server: SSH; `80/tcp` and `443/tcp` from anywhere; `51820/udp`
  from the site's public IP.

### Reverse proxy and client IP

Terminate TLS at Caddy on the site server. Use
`misc/caddy/Caddyfile.cloudflare.example` when Cloudflare proxies the site,
otherwise `misc/caddy/Caddyfile.example`.

- `web` is published on `WEB_BIND:WEB_PORT`, default `127.0.0.1:8080`.
  Keep it off 80/443, which Caddy listens on; Docker cannot bind a port
  Caddy already holds.
- The Compose network is pinned (`GAZELLE_SUBNET`, `GAZELLE_GATEWAY`), so
  Caddy reaches the containers from `172.30.33.254`. Set `ALLOWED_PROXY` in
  `override.config.php` to that address, as in
  `misc/example.production.config.php`. Without it every user appears to
  come from the gateway, and login throttling and IP bans hit everyone.
- The proxy must replace `X-Forwarded-For` with the client address, not
  append to it: Gazelle accepts the header only when it is one public IP
  from `ALLOWED_PROXY`. Caddy's default `reverse_proxy` replaces it. With
  nginx, use `proxy_set_header X-Forwarded-For $remote_addr;`, not
  `$proxy_add_x_forwarded_for`.
- IPv4 only: publish no AAAA record. Both examples refuse IPv6 clients.
- With Cloudflare: proxy the site hostname, SSL/TLS “Full (strict)” with a
  Cloudflare Origin CA certificate, IPv6 Compatibility off. Caddy trusts
  `CF-Connecting-IP` only from Cloudflare's ranges and still sends one
  address upstream, so `ALLOWED_PROXY` does not change. Cloudflare protects
  the site only while this server's IP stays unknown: keep it out of DNS,
  and send email through a mail provider rather than from this server
  (mail headers include the sending server's IP).

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
