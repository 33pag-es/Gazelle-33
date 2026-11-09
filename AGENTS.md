# AGENTS.md

This repository is a **comics-only Gazelle fork**: `33pag-es/Gazelle-33`.
It is a customization of [OPSnet/Gazelle](https://github.com/OPSnet/Gazelle),
not a rewrite. Music and movie support is retired from the product, but
upstream music code remains in the tree, dormant, so upstream patches and
security fixes can still be merged.

Read this file before changing anything. Read `docs/COMICS.md` for the
target data model and the ordered retrofit phases.

## 1. Host policy: Docker only

The host machine is a thin client. Never install or invoke PHP, Composer,
Node, npm, Cypress, MySQL, PostgreSQL, or Manticore on the host.

Every command runs inside the `web` container:

```bash
docker compose up -d
docker compose exec -T web <command>
```

If a Makefile target invokes `php`, `vendor/bin/*`, `npx`, `composer`, or
`bin/getconf` on the host, fix the target. Do not install the missing
tool.

Canonical commands:

```bash
# syntax check one file
docker compose exec -T web php -l app/Comic/Upload.php

# one test
docker compose exec -T web vendor/bin/phpunit -c misc/phpunit.xml tests/phpunit/Comic/SeriesTest.php

# full suite
make test

# comic suite
make test-comic

# upstream divergence
make upstream-diff

# static analysis
docker compose exec -T web vendor/bin/phpstan analyse --memory-limit=1024M --configuration=misc/phpstan.neon

# code style
docker compose exec -T web vendor/bin/phpcs -p

# twig syntax
docker compose exec -T web sh -c 'bin/twig-parse $(find templates -type f)'

# migrations (MySQL phase 1, PostgreSQL, MySQL phase 2)
docker compose exec -T web sh -c 'FKEY_MY_DATABASE=1 LOCK_MY_DATABASE=1 vendor/bin/phinx migrate -e gazelle'
docker compose exec -T web vendor/bin/phinx migrate -c misc/phinx-pg.php
docker compose exec -T web vendor/bin/phinx migrate -c misc/my2-phinx.php

# configuration value
docker compose exec -T web bin/getconf SITE_NAME

# database shells
make mysql
make psql

# Manticore reindex
docker compose exec -T manticore indexer -c /var/lib/manticore/manticore.conf --rotate --all
```

The first `docker compose up -d` bootstraps dependencies and runs all
three migration phases. Migrations run again on every `web` container
start.

Production uses `Dockerfile.production` and `docker-compose.prod.yml`.
The image has no Node, Cypress, Chromium, Xdebug, Python, shellcheck, or
source bind mount. The Compose file runs a one-shot `migrate` service,
publishes only the web port, and keeps MySQL/PostgreSQL/Manticore private.
Set `.env` from `misc/example.production.env`, fill
`lib/override.config.php`, and terminate TLS at the host reverse proxy.
Never use the development Compose file in production.

## 2. Upstream merge contract

- `origin` = `https://github.com/33pag-es/Gazelle-33`
- `upstream` = `https://github.com/OPSnet/Gazelle`

Rules:

1. Never rewrite published history. Merge upstream with merge commits.
2. Before starting work: `git fetch upstream`.
3. To absorb upstream: `git merge upstream/master`. Resolve shared-file
   conflicts in favour of upstream, then reapply the small comic guards.
4. Prefer new files over edits. New comic code belongs in `app/Comic/`,
   `templates/comic/`, and `sections/comic/` where possible.
5. Mark every edit to an upstream file:
   - PHP: `// COMIC-ONLY`
   - Twig: `{# COMIC-ONLY #}`
6. Never edit an existing migration. Add a new timestamped migration in
   `misc/my-migrations/`, `misc/pg-migrations/`, or
   `misc/my2-migrations/`.
7. Never drop, rename, or rewrite upstream tables, columns, classes, or
   constants to remove music. Leave them dormant. Remove product surface
   with config constants, route guards, and template branches.
8. Never reformat an upstream file, sort imports repo-wide, or rename an
   upstream symbol as a drive-by cleanup.
9. Every intentionally modified upstream file is listed in
   `docs/COMICS.md` § Upstream touchpoints. Add the row before committing.
10. If a shared-file diff exceeds roughly 30 lines, move the logic into
    `app/Comic/` and reduce the upstream diff.
11. Before merging upstream, inspect `git diff --stat upstream/master...HEAD`.
    After merging, run the comic tests and PHPStan. Never merge with a
    red tree.

## 3. Architecture

Request flow:

```
public/index.php
  -> gazelle.php
  -> lib/bootstrap.php
  -> RequestContext
  -> Router
  -> sections/<module>/index.php
  -> Twig template
```

- `app/` is PSR-4 `Gazelle\`.
- `classes/` is the legacy classmap (`Text`, `Sphinxql*`).
- `lib/` holds `config.php` and procedural helpers.
- `sections/` are executable page scripts, not classes. They are
  `require`d inside `Gazelle\LegacyRoute::process()` and receive
  `$Viewer`, `$Twig`, `$Cache`, and `$Debug` as locals.
- Templates live in `templates/`; every template must be referenced in
  code.
- `public/` is the web root; nginx sends unknown `/<module>.php` to
  `public/index.php` via `@missing`.

Data:

- MySQL is authoritative for torrents, groups, users, tags, and collages.
- PostgreSQL owns newer tables and reads MySQL through the `relay`
  foreign schema.
- MySQL: `DB::DB()` / `self::$db`, `?` placeholders,
  `prepared_query()`, `rowAssoc()`, `to_array()`, `collect()`.
- PostgreSQL: `$this->pg()` / `pgro()`, `$1` placeholders, `query()`,
  `rowex()`, `allex()`.
- Models: `BaseObject` (MySQL), `BasePgObject` (PostgreSQL),
  `BaseAttrObject` (flag tables). Use `Manager\*` factories; do not write
  raw SQL in page scripts.
- Cache: `self::$cache`. After changing group or torrent metadata, call
  `flush()`, and `refresh()` where the class defines it.

Search:

- Manticore table `torrents` (plus `delta`), config at
  `misc/docker/manticore/manticore.conf`.
- Staging tables `sphinx_tg`, `sphinx_t`, `sphinx_a` are populated by the
  indexer; `sphinx_delta` is written by `TGroup::refresh()`.
- After changing indexed columns, update the Manticore source and rebuild:
  `docker compose exec -T manticore indexer -c /var/lib/manticore/manticore.conf --rotate --all`.
- If the index is stale during development, run the same command with
  `--rotate delta` for the delta index.
- Manticore uses `table ... { type = plain }`; the delta table declares
  `killlist_target = torrents:kl`. The Sphinx `ngram_chars`/`ngram_len`
  settings are omitted because they overlap the charset table; CJK text is
  tokenized by the charset table instead of character n-grams.

Tracker:

- Ocelot is the external C++17 BitTorrent tracker (`OPSnet/Ocelot`),
  built with CMake; it is not built by this repository. In the `33pages`
  workspace the sibling source clone is `../Ocelot`. Config:
  `misc/ocelot/ocelot.conf`; optional compose override:
  `misc/docker-compose.ocelot.yml`.
- Ocelot reads MySQL `gazelle` directly and is category-blind. Its runtime
  schema is `torrents`, `torrents_leech_stats`, `users_freeleeches`,
  `users_leech_stats`, `users_main`, `xbt_client_whitelist`,
  `xbt_files_users`, and `xbt_snatched`. Never rename or drop those
  tables or their columns. It tracks TCP/IPv4 peers.
- `app/Tracker.php` pushes `info_hash`, torrent id, and freeleech state.
  No comic-specific tracker change is required.

## 4. Comics contract

Product: a private tracker for comic books only. One category: Comics.
Series-level groups. One torrent is one issue or one issue pack.

Target config (implemented in the retrofit phases; do not change it in a
documentation-only commit):

```php
SITE_FLAVOR            = 'comics'
CATEGORY_ENABLED       = ['Comics']
CATEGORY_DEFAULT       = 'Comics'
CATEGORY_COMIC         = 7
COMIC_FORMAT           = ['CBZ', 'CBR', 'PDF', 'CB7']
COMIC_PRIMARY_EXT_REGEXP = '(?:cb[rz7]|pdf|jpe?g|png|gif)'
```

Upstream values stay present and correct:
`CATEGORY` keeps all seven categories, `CATEGORY_MUSIC = 1`,
`CATEGORY_EBOOK = 3`, `CATEGORY_ICON` keeps all icons,
`CATEGORY_GROUPED` additionally includes `Comics`, and
`PRIMARY_MEDIA` / `FORMAT` / `MEDIA` / `ENCODING` remain the upstream
music values. Comic code selects `COMIC_*` constants explicitly; never
reuse `FORMAT` for CBZ/CBR/PDF and never repurpose `PRIMARY_MEDIA`.
Comics remains DB id 7; only `CATEGORY_ENABLED` controls visibility.
Never hardcode `'Comics'` or `7` in shared code; use the constants.

Schema is additive; music columns stay:

- `torrents_group.PublisherID` -> `publisher.PublisherID`
- `publisher(PublisherID, Name, Created)`
- `creator(CreatorID, Name, Created)`
- `series_creator(GroupID, CreatorID, Sequence)`
- `torrents.IssueStart`, `IssueEnd`, `IssueLabel`, `Scanner`, `Pack`,
  `Checked`, `CheckedBy`, `CheckedAt`
- `deleted_torrents` mirrors the comic torrent columns

Semantics:

- Group title = series title; `Year` = series start year.
- Issue identity: `IssueLabel` if present; otherwise `IssueStart`, plus
  `IssueEnd` when the torrent is a pack.
- `Pack = (IssueEnd IS NOT NULL AND IssueEnd > IssueStart)`.
- `Checked` is staff-only. Toggling requires `torrents_edit`; write an
  audit entry with `Torrent::logger()->torrent()`.
- Torrent label: `issue (scanner) [format]`, with `/ Pack` when pack.
- Torrent filename: `series - issue (scanner) [format]`.
- Music fields (`Media`, `Encoding`, `Remaster*`, `Scene`, `HasLog`,
  `HasCue`, `LogScore`) remain nullable and unused. Do not reuse them for
  comic semantics.

## 5. Where to change what

| Need | Add or edit | Rule |
|---|---|---|
| Category/config | `lib/config.php`, `lib/override.config.php` | `defined() or define()`; no magic ids |
| Schema | new `misc/my-migrations/*`, `misc/pg-migrations/*` | additive only |
| Comic domain | `app/Comic/*` | new classes; no upstream signature changes |
| Upload form | `templates/upload/comic.twig`, `Upload::comic()` | keep music templates intact |
| Upload handler | `sections/upload/upload_handle.php` | `if ($categoryName === 'Comics')` branch; delegate to `app/Comic/Upload.php` |
| Series page | `sections/torrents/details.php`, `templates/comic/*` | branch on `$tgroup->categoryName() === 'Comics'` |
| Torrent row/label | `app/TorrentAbstract.php`, `templates/comic/torrent-row.twig` | comic branch; music branch untouched |
| Checked | new `sections/torrents/check.php` | POST + `authorize()` + `torrents_edit` |
| Browse | `app/Search/Torrent.php`, `templates/torrent/browse-header.twig` | additive fields; hide music filters via `SITE_FLAVOR` |
| Search | `misc/docker/manticore/manticore.conf` | add fields/attributes; keep music fields |
| API/JSON | `app/API/*`, `app/Json/*` | add comic fields; keep music keys |
| Hide music | nav/RSS/sidebar templates, `sections/artist/*`, `sections/better/*`, requests, contests | gate with `SITE_FLAVOR`; do not delete |

## 6. Coding standards

- `declare(strict_types=1);` in every new PHP file.
- 4-space indentation, LF endings, UTF-8, no trailing `?>`.
- K&R braces; braces even for single statements; `elseif`, not `else if`.
- Short array syntax; trailing commas allowed.
- PSR-4 for `app/`; legacy classmap for `classes/`.
- Prepared statements only: `?` for MySQL, `$1` for PostgreSQL. Never
  interpolate input into SQL.
- Use managers and domain objects; do not duplicate business logic in
  `sections/`.
- POST handlers call `authorize()`; errors use `json_error()` /
  `json_print()` or `Error400/403/404::error()`.
- Twig autoescapes by default. Do not add `|raw` unless the value is
  already escaped.
- Run `bin/twig-parse` on changed templates. A template that is not
  referenced in code fails the pre-commit hook.
- Keep diffs small. No repo-wide formatting, import sorting, or renaming.

## 7. Tests

- Config: `misc/phpunit.xml`; bootstrap `misc/phpunit-autoload.php`.
- Tests hit the live Docker MySQL/PostgreSQL/memcached. There is no
  per-test database isolation.
- Create data with `GazelleUnitTest\Helper` factories; use unique names
  from `randomString()`; remove rows in `tearDown()`.
- Tests extend `PHPUnit\Framework\TestCase` directly; there is no base
  TestCase.
- Use `#[Group('no-ci')]` for tracker, git, or write tests that cannot run
  in CI.
- Add comic tests under `tests/phpunit/Comic/`. Do not delete upstream
  tests. If a music-only test cannot pass in the comics flavor, add
  `#[Group('music')]` and exclude that group in the comic CI job.
- Run the narrowest test that exercises the change, then PHPStan.

## 8. Checklist for a comic change

1. Add a new MySQL and/or PostgreSQL migration; never edit an old one.
2. Add the config default in `lib/config.php` and the local value in
   `misc/example.local.config.php`; real values go in
   `lib/override.config.php` (gitignored).
3. Put reusable logic in `app/Comic/`; do not change upstream method
   signatures. If a signature must change, add a new method instead.
4. Put new templates in `templates/comic/` and reference them from
   `sections/`.
5. Guard every upstream-file edit with `// COMIC-ONLY` or
   `{# COMIC-ONLY #}`.
6. Update `docs/COMICS.md` § Upstream touchpoints and `docs/07-API.md` if
   an API field changed.
7. Run the targeted test, `phpstan`, and `phpcs` in the `web` container.
8. `git diff --stat upstream/master...HEAD` — if a shared file grew,
   reduce it.

## 9. Known pitfalls

- **Category source of truth is split**: PHP `CATEGORY` (0-based),
   MySQL `category` (1-based ids), PostgreSQL `category` copy, and
   hard-coded numeric ids in SQL. Comics stays at id 7. Visibility is
   controlled by `CATEGORY_ENABLED`, not by renumbering. Any new comic
   code must use `CATEGORY_COMIC`, never a literal.
- **`TGroup::categoryName()`** is `CATEGORY[$id - 1]`. If the category
   array and DB ids drift, every category display breaks.
- **`relay` does not auto-discover new MySQL columns.** Query new comic
   columns through MySQL (`self::$db`), or add a PostgreSQL migration
   that recreates/alters the foreign table. Do not assume
   `relay.torrents_group` has `PublisherID` until it is added.
- **Manticore staging tables** (`sphinx_tg`, `sphinx_t`, `sphinx_a`,
   `sphinx_delta`) are part of the schema. Add comic columns to the
   staging tables and `manticore.conf` together.
- **Tests mutate the dev database.** Clean up in `tearDown()`; never point
   the suite at a database you care about.
- **`make lint-twig`** previously expanded `$(find ...)` as a Make
   variable. The guardrails Makefile uses a quoted `sh -c` command; keep
   it.
- **Host tools**: the repository README may show host commands. The host
   policy in §1 overrides them.
- **Upstream README** says “AI not welcome.” This fork's owner has
   explicitly authorized agent work. Do not send agent-generated changes
   upstream without human review.
