# Comics retrofit contract

This document is the binding target for the comics-only Gazelle fork. It
is written for an engineer who has only the repository and this file.
Implement phases in order; each phase must leave the site bootable and the
existing test suite passing.

## Product contract

- One content category: **Comics**.
- No music, movie, application, e-book, audiobook, comedy, or e-learning
  category is exposed.
- A group is a **series**. A torrent is a **single issue** or an **issue
  pack**.
- Users browse series, open a series page, and download issue torrents.
- Staff can mark a torrent **CHECKED** or **UNCHECKED**.
- Music-only Gazelle subsystems stay in the tree but are unreachable from
  the product surface, so upstream merges remain possible.

## Data model

All schema changes are additive. Music tables and columns stay.

### New tables (MySQL)

```sql
CREATE TABLE publisher (
    PublisherID INT AUTO_INCREMENT PRIMARY KEY,
    Name        VARCHAR(255) NOT NULL,
    Created     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY publisher_name_uidx (Name)
);

CREATE TABLE creator (
    CreatorID INT AUTO_INCREMENT PRIMARY KEY,
    Name      VARCHAR(255) NOT NULL,
    Created   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY creator_name_uidx (Name)
);

CREATE TABLE series_creator (
    GroupID    INT NOT NULL,
    CreatorID  INT NOT NULL,
    Sequence   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (GroupID, CreatorID),
    KEY series_creator_creator_idx (CreatorID),
    CONSTRAINT series_creator_group_fk FOREIGN KEY (GroupID) REFERENCES torrents_group (ID) ON DELETE CASCADE,
    CONSTRAINT series_creator_creator_fk FOREIGN KEY (CreatorID) REFERENCES creator (CreatorID) ON DELETE CASCADE
);
```

Exact Phinx column names must be `PublisherID`, `Name`, `Created`,
`CreatorID`, `GroupID`, `Sequence`. Table names are lower-case singular,
matching the existing MySQL style.

### Changed tables (MySQL)

`torrents_group`:

```sql
ALTER TABLE torrents_group
    ADD COLUMN PublisherID INT NULL AFTER Year,
    ADD KEY torrents_group_publisher_idx (PublisherID),
    ADD CONSTRAINT torrents_group_publisher_fk FOREIGN KEY (PublisherID) REFERENCES publisher (PublisherID);
```

`torrents`:

```sql
ALTER TABLE torrents
    ADD COLUMN IssueStart INT NULL AFTER Format,
    ADD COLUMN IssueEnd   INT NULL AFTER IssueStart,
    ADD COLUMN IssueLabel VARCHAR(32) NULL AFTER IssueEnd,
    ADD COLUMN Scanner    VARCHAR(100) NULL AFTER IssueLabel,
    ADD COLUMN Pack       TINYINT(1) NOT NULL DEFAULT 0 AFTER Scanner,
    ADD COLUMN Checked    TINYINT(1) NOT NULL DEFAULT 0 AFTER Pack,
    ADD COLUMN CheckedBy  INT NULL AFTER Checked,
    ADD COLUMN CheckedAt  DATETIME NULL AFTER CheckedBy,
    ADD KEY torrents_issue_idx (IssueStart, IssueEnd),
    ADD KEY torrents_scanner_idx (Scanner),
    ADD KEY torrents_checked_idx (Checked);
```

`deleted_torrents` mirrors `IssueStart`, `IssueEnd`, `IssueLabel`,
`Scanner`, `Pack`, `Checked`, `CheckedBy`, `CheckedAt` with the same
types and defaults.

`deleted_torrents_group` also gains `PublisherID` (same type, after
`Year`). `DB::softDelete()` refuses to remove a group when the source
and the deleted mirror have different columns.

`category` (data migration, no renumber):

1. Add `comic` to the MySQL `category.upload` enum while keeping the
   existing values:
   `ALTER TABLE category MODIFY upload ENUM('audiobook','simple','music','comic') NOT NULL DEFAULT 'simple';`
2. Update the Comics row (id 7): `upload = 'comic'`, `is_grouped = 1`.
3. Keep every category row and every id. Do not update
   `torrents_group.CategoryID`; do not delete report rows.
4. The system `Global` row stays id 0 with `upload = 'simple'`.

PostgreSQL mirror:

1. Add `'comic'` to `category_upload_t` with
   `ALTER TYPE category_upload_t ADD VALUE IF NOT EXISTS 'comic'`.
   Run this in its own migration; PostgreSQL does not allow using the new
   enum value in the same transaction.
2. In a following migration, update the PostgreSQL `category` row 7 to
   `upload = 'comic', is_grouped = true`; keep all rows.
3. Do not remap `request.id_category`; requests remain category 7 and
   stay dormant.

New Sphinx staging columns (MySQL, via a new migration):

- `sphinx_tg`: `publisher VARCHAR(255) NULL`, `creators VARCHAR(2048) NULL`.
- `sphinx_t`: `issue_start INT NULL`, `issue_end INT NULL`,
  `scanner VARCHAR(100) NULL`, `pack TINYINT(1) NOT NULL DEFAULT 0`,
  `checked TINYINT(1) NOT NULL DEFAULT 0`.
- `sphinx_delta`: mirror the same columns.
- `sphinx_a` remains the artist/creator joined-field table; use it for
  creators, or add `sphinx_c` if keeping the two namespaces separate.
  The approved choice is to reuse `sphinx_a` for creator names so the
  existing joined-field machinery is unchanged.

### PostgreSQL note

New comic metadata is read from MySQL. Do not add `PublisherID` or comic
torrent columns to `relay.*` foreign tables unless a PostgreSQL query
needs them. If one does, add a PostgreSQL migration that drops and
re-imports the foreign table (or `ALTER FOREIGN TABLE ... ADD COLUMN`)
before querying it.

## Config

`lib/config.php` gains additive defaults; upstream constants stay:

```php
defined('SITE_FLAVOR')             or define('SITE_FLAVOR', 'comics');
defined('CATEGORY_ENABLED')        or define('CATEGORY_ENABLED', ['Comics']);
defined('CATEGORY_DEFAULT')        or define('CATEGORY_DEFAULT', 'Comics');
defined('CATEGORY_COMIC')          or define('CATEGORY_COMIC', 7);
defined('COMIC_FORMAT')            or define('COMIC_FORMAT', ['CBZ', 'CBR', 'PDF', 'CB7']);
defined('COMIC_PRIMARY_EXT_REGEXP') or define('COMIC_PRIMARY_EXT_REGEXP', '(?:cb[rz7]|pdf|jpe?g|png|gif)');
```

`CATEGORY_GROUPED` changes from
`array_intersect(['Music'], CATEGORY)` to
`array_intersect(CATEGORY, ['Music', 'Comics'])`. The first array must be
`CATEGORY` so the surviving keys stay `categoryId() - 1`; swapping the
arguments yields a `Comics` entry at key 1 and `categoryGrouped()` stays
false for category 7.

Do **not** change `CATEGORY`, `CATEGORY_ICON`, `CATEGORY_MUSIC`,
`CATEGORY_EBOOK`, `PRIMARY_MEDIA`, `PRIMARY_EXT_REGEXP`, `FORMAT`,
`MEDIA`, or `ENCODING`; they remain upstream values for dormant music
code and existing tests.

`lib/override.config.php` (gitignored) may set `SITE_FLAVOR`,
`CATEGORY_ENABLED`, `CATEGORY_DEFAULT`, `CATEGORY_COMIC`,
`COMIC_FORMAT`, and `COMIC_PRIMARY_EXT_REGEXP`. Put the same values in
`misc/example.local.config.php` so the Docker bootstrap generates a comic
profile. Never commit secrets.

## Upload

Form: `templates/upload/comic.twig`, rendered by `Upload::comic()`.
The form posts to the existing `upload.php` handler with `type=6`
(the 0-based index of Comics in the upstream `CATEGORY` array; DB id 7).
The handler derives `$categoryName = CATEGORY[(int)$_POST['type']]`.

Fields:

| Field | POST key | Required | Validation | Storage |
|---|---|---|---|---|
| Series title | `title` | yes | 2-200 chars | `torrents_group.Name` |
| Publisher | `publisher` | yes | 2-255 chars; autocomplete existing or create | `publisher.Name`, `torrents_group.PublisherID` |
| Creators | `creators[]` | yes, at least 1 | 1-255 chars each; dedupe; max 20 | `creator.Name`, `series_creator` |
| Year | `year` | yes | 1900..current year+1 | `torrents_group.Year` |
| Issue start | `issue_start` | yes unless `issue_label` | integer 0..100000 | `torrents.IssueStart` |
| Issue end | `issue_end` | no | integer >= `issue_start`, or empty | `torrents.IssueEnd` |
| Issue label | `issue_label` | no | 1-32 chars | `torrents.IssueLabel` |
| Scanner | `scanner` | no | 1-100 chars | `torrents.Scanner` |
| Format | `format` | yes | one of `FORMAT` | `torrents.Format` |
| Tags | `tags` | yes | existing tag rules, 2-200 chars | `torrents_tags` |
| Cover | `image` | no | `Image::fromUntrusted()` | `torrents_group.WikiImage` |
| Description | `desc` | yes | 10-1,000,000 chars | `torrents_group.WikiBody` |
| Torrent file | `file_input` | yes | `.torrent`, V1, not encrypted | `File\Torrent` |
| Existing series | `groupid` | no | existing group id | `torrents.GroupID` |

Derived values:

- `Pack = (issue_end is present and issue_end > issue_start)`.
- `Checked = 0`, `CheckedBy = NULL`, `CheckedAt = NULL`.
- `Media = ''`, `Encoding = ''`, `Scene = 0`, `HasLog = 0`,
  `HasCue = 0`, `HasLogDB = 0`, `LogScore = 0`, `LogChecksum = 0`,
  `Remaster* = NULL`.
- `folderName` is not required for comics.
- `FileChecker` uses the existing `Comics` extension allowlist:
  `cbr, cbz, gif, jpeg, jpg, pdf, png`. Add `cb7` to that list as part of
  the comic phase.

Handler shape:

- In `sections/upload/upload_handle.php`, after `$categoryName` is known,
  set `$isComicUpload = ($categoryName === 'Comics')`.
- Keep the existing torrent-file decode, infohash duplicate check,
  file-list validation, transaction, tracker registration, notification
  ticket, and bonus logic.
- Skip the music-only validation and extra-format/log branches when
  `$isComicUpload`.
- Parse comic metadata with `app/Comic/Upload::properties($_POST)`.
- Create the group with `Manager\TGroup::create(categoryId: CATEGORY_COMIC,
  name: $title, description: $description, year: $year,
  releaseType: null, image: $image, showcase: false)`.
- After the group exists, call `Comic\Series::setPublisher($tgroup,
  $publisherName)` and `Comic\Series::setCreators($tgroup, $creatorNames)`.
- Create the torrent with the existing `Manager\Torrent::create()` using
  the comic format and empty music values; then call
  `Comic\Torrent::setIssue($torrent, $issueStart, $issueEnd,
  $issueLabel, $scanner, $pack)`.
- On duplicate series, reuse the group when `groupid` is supplied, or
  match `Name + Year + PublisherID` via
  `Comic\Series::findByTitlePublisherYear()`.

Edit path:

- `sections/torrents/edit.php` already renders `Upload::comic()` for
  comics. Pass the `torrent` key so the cover prefill works.
- `sections/torrents/edit_handle.php` gets a `$isComic` branch that
  validates and updates the same fields plus the torrent label. Music
  validation remains untouched.

## Series page

Route: `torrents.php?id=<groupid>`.

`sections/torrents/details.php` branches when
`$tgroup->categoryName() === 'Comics'` and renders:

- `templates/comic/series-header.twig`: cover, series title, year,
  publisher, creator links, tags, bookmark, subscribe, collage add.
- `templates/comic/series-torrents.twig`: table columns
  `Name | Issue | Scanner | Format | Checked | Files | Size | Snatches |
  Seeders | Leechers`.
- `templates/comic/torrent-row.twig`: label
  `issue (scanner) [format]` plus `/ Pack`; `CHECKED`/`UNCHECKED` badge;
  download/report/edit links.
- `templates/comic/series-description.twig` and the existing comment
  thread.

Do not render the artist sidebar, release-type header, mastering rows,
edition grouping, similar-albums block, or riplog links for comics.

`app/TGroup.php` comic branches:

- `text()`: `"{$name} ({$year})"` with publisher if set.
- `link()` / `torrentLink()`: keep the group URL; show the series label.
- `primaryArtist()` must never be called for comics.
- `categoryGrouped()` stays true for Comics.

`app/TorrentAbstract.php` comic branches:

- `shortLabelList()`: `[issueLabelOrRange, scanner, format, 'Pack']`.
- `label()`: combine short label with checked state.
- `isPerfectFlac()` / `isPerfecterFlac()`: return false for comics.
- `edition()`, `media()`, `encoding()`, `logScore()`, `hasLog()`,
  `hasCue()`: return empty/zero for comics; no template should call them
  on a comic torrent.

`app/Torrent.php::torrentFilename()` comic branch:

```
<safe series name> - <issue label> (<scanner>) [<format>]-<torrentId>.torrent
```

Sanitize with the existing `safeFilename()` and `shortenString()`.
Omit empty parentheses/brackets.

## Checked workflow

New endpoint: `sections/torrents/check.php`.

- Accepts `POST` only; requires `authorize()` and
  `$Viewer->permitted('torrents_edit')`.
- Inputs: `torrentid`, `checked` (`1` or `0`).
- Loads the torrent; rejects non-comic torrents.
- Updates `Checked`, `CheckedBy = $Viewer->id`,
  `CheckedAt = now()`.
- Writes `$torrent->logger()->torrent($torrent, $Viewer,
  'marked checked'/'marked unchecked')`.
- Flushes the torrent cache and refreshes the Sphinx delta.
- Returns the series page with a flash message, or JSON when
  `AJAX` is defined.

UI:

- Series page row shows a `CHECKED` / `UNCHECKED` badge.
- Staff see a toggle link.
- Browse search accepts `checked=1|0`.

## Browse and search

`app/Search/Torrent.php` additions:

- Fields: `series` (maps to `groupname`), `creatorname`, `publishername`,
  `issue`, `scanner`, `format`, `taglist`, `description`, `filelist`.
- Attributes: `checked` (0/1), `pack` (0/1), `issue_start`, `issue_end`.
- `searchstr` expands to `(groupname,publishername,creatorname,yearfulltext)`.
- Keep the music fields and attributes in the class so upstream patches
  still apply; hide them in the comic UI.

`misc/docker/sphinxsearch/sphinx.conf` source changes:

- `sphinx_tg`: add `publisher` from `publisher.Name`, `creators` from
  `creator.Name` joined through `series_creator`.
- `sphinx_t`: add `issue_start`, `issue_end`, `scanner`, `pack`,
  `checked`.
- `sql_query`: select the new columns and expose them as fields or
  attributes.
- `sql_joined_field creatorname`: reuse the `sphinx_a` pattern, joining
  `series_creator` + `creator`, ordered by torrent id.

`templates/torrent/browse-header.twig` comic branch:

- Replace Artist name / Album / Record label / Catalogue / Edition /
  Release type / Media / Encoding / Log / Cue / Scene rows with:
  Series name, Creator, Publisher, Year, Issue, Scanner, Format,
  Checked, Pack, Tags, Leech status.
- Category checkboxes render only Comics.
- Order by: Created, Year, Size, Snatches, Seeders, Leechers, Random.
- Group by series defaults to on.

Result rows:

- Grouped: series name, year, publisher, creator summary, torrent count,
  latest issue, size, snatches/seeders/leechers.
- Ungrouped: torrent label `issue (scanner) [format]`, checked badge,
  series name, size, stats.

## Hidden music surfaces

Gate with `constant('SITE_FLAVOR') === 'comics'` or a dedicated
`COMIC_ONLY` constant. Do not delete the underlying files.

| Surface | Gate point | Action |
|---|---|---|
| Navigation artists search | `templates/index/page-header.twig` | hide artist search form |
| Random artist | `templates/torrent/browse-header.twig` | hide link |
| RSS music feeds | `templates/index/private-header.twig` | show only `torrents_comics` and `torrents_all` |
| Album of the Month | `templates/index/private-sidebar.twig` | hide AOTM block; keep news/stats |
| Releases/Artists/Perfect FLAC counters | `templates/index/private-sidebar.twig` | replace with Series/Issues/Checked counters in the stats phase |
| Requests | `templates/index/page-header.twig`, `sections/requests/index.php` | hide nav and return 404 when disabled |
| Better/trumping | `templates/index/page-header.twig`, `sections/better/index.php` | hide nav and return 404 |
| Artists subsystem | `sections/artist/index.php`, `templates/artist/*` | return 404; remove artist search/autocomplete from comic templates |
| Music RSS feeds | `app/Feed.php` | leave code; do not link |
| Music stats | `app/Stats/Torrent.php` | leave methods; do not call from comic templates |
| Music contests | `sections/contest/*` | hide contest types that are music-only |
| Featured album | `app/FeaturedAlbum.php` | hide for comics |
| Collage artist/label/discography types | collage create/edit templates | hide those type options; keep Personal, Theme, Genre, Staff picks, Charts, Awards, Series |
| Music rules prose | `templates/rules/*.twig` | rewrite upload/tag/request/collage/forum sections for comics |
| Rules wiki article | `wiki_articles` id `RULES_WIKI_PAGE_ID` (127), `INDEX_WIKI_PAGE_ID` (1), `SOURCE_FLAG_WIKI_PAGE_ID` (113) | seed comic rules via a new migration or admin edit; templates alone do not replace DB content |
| Top 10 Last.fm / format | `sections/top10/*`, `templates/top10/*` | hide Last.fm and music format dropdown |
| Notification filters | `app/Notification/Filter.php`, `templates/user/edit-notification-filter.twig` | hide artist/format/media/encoding/release/label dimensions; keep tags |
| User display settings | `templates/user/setting/display.twig` | replace album/artist wording and autocomplete |
| Torrent merge/split/change category | `sections/torrents/merge.php`, `new_groupid.php`, `edit_group*` | hide or adapt to series; do not expose music split fields |
| Riplogs / log links | `templates/torrent/detail-torrent.twig`, `sections/torrents/*log*` | hide; comics have no logs |
| OpenSearch artist/request | `templates/index/private-header.twig` | hide artist/request search providers |
| Random artist | `sections/random/index.php`, `templates/index/page-header.twig` | hide `random.php?action=artist` |
| API music keys | `app/Api/*`, `app/Json/*` | keep keys for compatibility; add comic keys |

## Configurability contract

The fork stays a configurable framework, not a hardcoded one-off:

- Category visibility comes from `CATEGORY_ENABLED`, never from deleting
  rows or editing `CATEGORY`.
- The comic category id comes from `CATEGORY_COMIC`, never a literal `7`.
- Comic formats come from `COMIC_FORMAT`, never `FORMAT`.
- Comic file counting uses `COMIC_PRIMARY_EXT_REGEXP` when the group is
  Comics, and `PRIMARY_EXT_REGEXP` otherwise.
- Feature visibility comes from `SITE_FLAVOR`, never a scattered
  `if (SITE_NAME === ...)`.
- Site-specific values live in `lib/override.config.php`; the committed
  defaults in `lib/config.php` are the safe defaults.
- A future profile (for example manga) adds its own `CATEGORY_ENABLED`
  entry and `COMIC_*`-style constants without deleting the comic path.

## Tracker

Ocelot is the BitTorrent tracker, written in C++17 and built with CMake.
It is external to this repository; in the `33pages` workspace the sibling
source clone is `../Ocelot` (`https://github.com/OPSnet/Ocelot`). The fork
config is `misc/ocelot/ocelot.conf`; the optional compose override is
`misc/docker-compose.ocelot.yml`. Ocelot reads MySQL `gazelle` directly
and is category-blind. Its runtime schema is `torrents`,
`torrents_leech_stats`, `users_freeleeches`, `users_leech_stats`,
`users_main`, `xbt_client_whitelist`, `xbt_files_users`, and
`xbt_snatched`; it tracks TCP/IPv4 peers. Never rename or drop those
tables or their columns. Adding comic columns is safe. `app/Tracker.php`
only pushes `info_hash`, torrent id, and freeleech state; no
comic-specific tracker change is required.

Local Docker development sets `DISABLE_TRACKER = true` in
`misc/example.local.config.php`, so Ocelot is optional. To exercise
announce/scrape locally, set `DISABLE_TRACKER = false`, start with
`docker compose -f docker-compose.yml -f misc/docker-compose.ocelot.yml up -d`,
and make the 32-character `report_password` / `site_password` in
`misc/ocelot/ocelot.conf` match `TRACKER_REPORTKEY` / `TRACKER_SECRET`.
`bin/ocelotctl` is the announce/scrape/report harness.

## Upstream touchpoints

Every file below is intentionally modified by the retrofit. Add a row
when a new upstream file is touched. Keep each diff minimal and marked.

| File | Reason | Marker |
|---|---|---|
| `lib/config.php` | add `SITE_FLAVOR`, `CATEGORY_ENABLED`, `CATEGORY_COMIC`, `COMIC_*`; add Comics to `CATEGORY_GROUPED` | `// COMIC-ONLY` |
| `misc/example.local.config.php` | comic profile for the Docker bootstrap | `// COMIC-ONLY` |
| `Makefile` | Docker-only commands | `# COMIC-ONLY` |
| `README.md` | host policy | `<!-- COMIC-ONLY -->` |
| `sections/upload/upload.php` | default category, load music JS only for Music | `// COMIC-ONLY` |
| `sections/ajax/upload.php` | category map/default | `// COMIC-ONLY` |
| `sections/upload/upload_handle.php` | comic branch | `// COMIC-ONLY` |
| `app/Upload.php` | comic form context | `// COMIC-ONLY` |
| `templates/upload/comic.twig` | comic fields | `{# COMIC-ONLY #}` |
| `templates/upload/alert.twig` | comic upload notice | `{# COMIC-ONLY #}` |
| `app/Util/FileChecker.php` | allow `.cb7` comic files | `// COMIC-ONLY` |
| `sections/torrents/details.php` | comic page branch | `// COMIC-ONLY` |
| `sections/torrents/browse.php` | comic search defaults | `// COMIC-ONLY` |
| `sections/torrents/edit.php` | comic edit form | `// COMIC-ONLY` |
| `sections/torrents/edit_handle.php` | comic edit branch | `// COMIC-ONLY` |
| `sections/torrents/index.php` | checked-state route | `// COMIC-ONLY` |
| `templates/torrent/browse-header.twig` | comic filters | `{# COMIC-ONLY #}` |
| `templates/torrent/detail-torrent.twig` | comic row include | `{# COMIC-ONLY #}` |
| `app/TGroup.php` | comic text/link/grouping, freeleech selection | `// COMIC-ONLY` |
| `app/Torrent.php` | comic filename | `// COMIC-ONLY` |
| `app/TorrentDeleted.php` | comic deleted-torrent columns | `// COMIC-ONLY` |
| `app/TorrentAbstract.php` | comic primary extensions, label/perfect-flac guards | `// COMIC-ONLY` |
| `tests/helper.php` | comic test factories | `// COMIC-ONLY` |
| `app/Manager/TGroup.php` | comic group creation/merge/change-category | `// COMIC-ONLY` |
| `app/Manager/Torrent.php` | comic torrent metadata | `// COMIC-ONLY` |
| `app/Manager/Tag.php` | comic category for tag suggestions | `// COMIC-ONLY` |
| `app/Collector/Collage.php` | comic category for collage zips | `// COMIC-ONLY` |
| `app/Collector/TList.php` | comic category for torrent-list zips | `// COMIC-ONLY` |
| `app/Search/Torrent.php` | comic search fields | `// COMIC-ONLY` |
| `misc/docker/sphinxsearch/sphinx.conf` | comic index fields | `# COMIC-ONLY` |
| `app/Json/TGroup.php` | comic response fields | `// COMIC-ONLY` |
| `app/Json/Torrent.php` | comic response fields | `// COMIC-ONLY` |
| `app/Api/Torrent.php` | comic API fields | `// COMIC-ONLY` |
| `docs/07-API.md` | comic API fields | `<!-- COMIC-ONLY -->` |
| `templates/index/page-header.twig` | hide music nav/search | `{# COMIC-ONLY #}` |
| `templates/index/private-header.twig` | hide music RSS | `{# COMIC-ONLY #}` |
| `templates/index/private-sidebar.twig` | comic stats | `{# COMIC-ONLY #}` |
| `templates/rules/*.twig` | comic rules prose | `{# COMIC-ONLY #}` |

## Implementation phases

### Phase 1 — Category and config

Files: `lib/config.php`, `misc/example.local.config.php`, new MySQL and
PostgreSQL migrations, `app/Manager/Category.php` if it needs the `comic`
upload type.

Behavior: add `SITE_FLAVOR`, `CATEGORY_ENABLED`, `CATEGORY_DEFAULT`,
`CATEGORY_COMIC = 7`, `COMIC_FORMAT`, and
`COMIC_PRIMARY_EXT_REGEXP`; add Comics to `CATEGORY_GROUPED`; set the
Comics category row (id 7) to `upload = 'comic'`, `is_grouped = 1`.
Keep every category row, every id, and every music constant.

Done when: `bin/getconf -j CATEGORY_ENABLED` prints `["Comics"]`;
`docker compose exec -T web bin/getconf CATEGORY_COMIC` prints `7`;
`bin/getconf CATEGORY` still contains `Music` at index 0;
`docker compose exec -T web vendor/bin/phinx status` is clean.

Shared-code audit:

- `TGroup::setFreeleech()` must accept comic torrents. Change the
  `format() === 'FLAC'` condition to
  `$tgroup->categoryName() === 'Comics' || $torrent->format() === 'FLAC'`.
- `TorrentAbstract::fileListPrimary*()` must use
  `COMIC_PRIMARY_EXT_REGEXP` when the group category is Comics, and
  `PRIMARY_EXT_REGEXP` otherwise.
- `Manager\Tag::userTopTagList()` and `app/Collector/{Collage,TList}.php`
  hard-code `CategoryID = 1` (Music). Use `CATEGORY_COMIC` for comic tag
  suggestions and collage zips. The collectors accept
  `CATEGORY_COMIC` and `CATEGORY_MUSIC` so legacy music collages and
  upstream collector tests still resolve; leave the dormant music path
  untouched.
- `app/Stats/Torrent.php` music counters remain but are not called from
  comic templates.

### Phase 2 — Comic domain

Files: new `app/Comic/Publisher.php`, `app/Comic/Creator.php`,
`app/Comic/Series.php`, `app/Comic/Torrent.php`, `app/Comic/Upload.php`;
new `tests/phpunit/Comic/*`.

Behavior:

- `Comic\Publisher::softCreate(string $name): Publisher` returns the
  existing publisher or inserts one.
- `Comic\Creator::softCreate(string $name): Creator` returns the existing
  creator or inserts one.
- `Comic\Series::setPublisher(TGroup $tgroup, string $name): void`
  resolves/creates and updates `torrents_group.PublisherID`.
- `Comic\Series::setCreators(TGroup $tgroup, array $names): int` resolves,
  deduplicates, and replaces `series_creator` rows in the supplied order.
- `Comic\Series::publisher(TGroup $tgroup): ?string`,
  `Comic\Series::creatorList(TGroup $tgroup): array`,
  `Comic\Series::findByTitlePublisherYear(string $title, int $year, int $publisherId): ?TGroup`.
- `Comic\Torrent::setIssue(Torrent $torrent, ?int $start, ?int $end,
  ?string $label, ?string $scanner, bool $pack): void`.
- `Comic\Torrent::issueLabel(Torrent $torrent): string` returns
  `IssueLabel`, `"start-end"`, or `"start"`.
- `Comic\Upload::properties(array $post): array` validates and returns
  the normalized fields from the upload table.
- Extend `Torrent::infoRow()` SELECT with `IssueStart`, `IssueEnd`,
  `IssueLabel`, `Scanner`, `Pack`, `Checked`, `CheckedBy`, `CheckedAt`.
- Add `issueStart()`, `issueEnd()`, `issueLabel()`, `scanner()`,
  `isPack()`, `isChecked()`, `checkedBy()`, `checkedAt()` accessors to
  `TorrentAbstract`; they read the keys added to `infoRow()`.
- Extend `TGroup::info()` SELECT with `PublisherID`; add
  `Comic\Series::publisher(TGroup $tgroup): ?string`.
- Bump `TGroup::CACHE_KEY` to `tg_v4_%d` and `Torrent::CACHE_KEY` to
  `t_v4_%d` so cached rows from the old schema are not reused.

Done when: unit tests create a publisher, creator, series, and torrent;
read them back; assert `Pack` is set when end > start; assert duplicates
reuse the same publisher/creator rows.

### Phase 3 — Upload and edit

Files: `templates/upload/comic.twig`, `app/Upload.php`,
`sections/upload/upload.php`, `sections/ajax/upload.php`,
`sections/upload/upload_handle.php`, `sections/torrents/edit.php`,
`sections/torrents/edit_handle.php`, `tests/phpunit/UploadTest.php`.

Behavior: the comic form exposes the fields in § Upload; the handler
creates a series and issue torrent with empty music fields; the edit form
prefills and updates the same fields; `groupid` reuses an existing series.

`sections/upload/upload.php` and `sections/ajax/upload.php` default to
`CATEGORY_COMIC` and load `musicbrainz` JS/CSS only when the selected
category is Music. The comic form remains the only category form shown
because `CATEGORY_ENABLED` contains Comics.

`Upload::comic()` must pass `description` as the `desc` Textarea (group
description), `release_description` as the `release_desc` Textarea, and
`torrent` for cover prefill. `templates/upload/comic.twig` renders both
textareas. This fixes the existing mismatch where `Upload::comic()` passes
only a release-description textarea but the handler reads `desc` for the
group description.

Done when: a user with `site_upload` uploads a `.torrent` named
`Batman 001 (2024) (Zone-Empire).cbz` with publisher `DC`, creator
`Tom King`, year `2024`, issue `1`, scanner `Zone-Empire`, format `CBZ`;
the series page shows `Batman (2024)`, `DC`, `Tom King`, and a torrent
row `1 (Zone-Empire) [CBZ]`; the torrent downloads with the comic
filename; a pack upload with issue `1-10` shows `1-10 (...) / Pack`.

### Phase 4 — Series page and checked workflow

Files: `sections/torrents/details.php`, new `templates/comic/*`, new
`sections/torrents/check.php`, `app/TGroup.php`, `app/Torrent.php`,
`app/TorrentAbstract.php`, `tests/phpunit/Comic/SeriesPageTest.php`.

Behavior: comics render the series layout; music blocks are absent;
staff toggle checked state with an audit entry; the badge updates.

`templates/torrent/detail-torrent.twig` must render
`torrent.label(viewer)` for Comics so the issue/scanner/format label
appears; do not fall back to `torrent.name` (which returns only the
series title).

Done when: the series page has no artist sidebar, release type, mastering
row, edition group, similar albums, or riplog link; a staff user toggles
`CHECKED`; the row shows `CHECKED`; the audit trail contains the note; a
non-staff POST returns 403.

### Phase 5 — Browse and Sphinx

Files: `app/Search/Torrent.php`, `templates/torrent/browse-header.twig`,
`sections/torrents/browse.php`, `misc/docker/sphinxsearch/sphinx.conf`,
new MySQL migration for staging columns, `tests/phpunit/Comic/SearchTest.php`.

Behavior: search by series, creator, publisher, issue, scanner, format,
tags, checked, pack; grouped results default; music filters absent.

Add the same columns to the `sphinx_delta` writes in `TGroup::refresh()`
and `Torrent::removeTorrent()` so delta indexing matches the full index.

Done when: after reindex, `torrents.php?publishername=DC` returns the DC
series; `torrents.php?creatorname=Tom+King` returns the series;
`torrents.php?checked=1` returns only checked torrents;
`torrents.php?pack=1` returns pack torrents; the browse form has no
Artist/Album/Media/Encoding/Log/Cue/Scene fields.

### Phase 6 — Hide music surface and rebrand

Files: navigation/RSS/sidebar templates, `sections/requests/index.php`,
`sections/better/index.php`, `sections/artist/index.php`,
`sections/contest/*`, `templates/rules/*.twig`, `app/Feed.php` links,
`templates/index/*`.

Behavior: no music/movie category, nav link, RSS feed, stats label, rules
prose, or request/better/artist page is reachable from the comic UI.
Underlying code remains. Seed the DB-backed rules wiki articles
(`RULES_WIKI_PAGE_ID` 127, `INDEX_WIKI_PAGE_ID` 1,
`SOURCE_FLAG_WIKI_PAGE_ID` 113) with comic rules in a new MySQL migration;
template prose alone does not replace `wiki_articles`. Hide the Last.fm
and music-format Top 10 views, notification artist/format/media filters,
user display album/artist wording, torrent merge/split music fields,
riplog links, OpenSearch artist/request providers, and
`random.php?action=artist`.

Done when: a crawl of the nav shows Home, Torrents, Collages, Forums,
IRC, Top 10, Rules, Wiki, Staff; `/artist.php` and `/better.php` return
404; `/requests.php` returns 404; RSS dropdown has only All/Comic feeds;
the index sidebar shows Series/Issues/Checked, not Albums/Artists/FLACs;
`rules.php` shows comic rules; Top 10 has no Last.fm or format dropdown;
the notification filter has no artist/format/media fields; comic
merge/split UI is hidden.

### Phase 7 — API, docs, and final verification

Files: `app/Json/TGroup.php`, `app/Json/Torrent.php`,
`app/Api/Torrent.php`, `app/Api/Request.php`,
`docs/07-API.md`, `tests/phpunit/Comic/ApiTest.php`.

Behavior: comic fields appear in JSON/API responses; music keys remain
for compatibility; `Api\Request` guards `artistRole()` for non-Music so
it cannot fatal.

Add tests under `tests/phpunit/Comic/`. The existing suite still runs
because upstream ids and constants are intact. If a music-only test fails
because of the comic profile, tag it `#[Group('music')]` and exclude that
group in the comic CI job; do not delete the test. The GitLab CI file
needs no path change because PHPUnit scans `tests/phpunit`.

Done when: `api.php?action=torrent&req=group&id=<id>` includes
`Publisher`, `Creators`, `IssueStart`, `IssueEnd`, `IssueLabel`, `Pack`,
`Scanner`, `Checked`; `api.php?action=request` does not fatal for a
comics request; the full comic test group passes.

## Verification commands

```bash
docker compose up -d
docker compose exec -T web vendor/bin/phpunit -c misc/phpunit.xml tests/phpunit/Comic
docker compose exec -T web vendor/bin/phpstan analyse --memory-limit=1024M --configuration=misc/phpstan.neon
docker compose exec -T web vendor/bin/phpcs -p --parallel=4
docker compose exec -T web sh -c 'bin/twig-parse $(find templates -type f)'
docker compose exec -T sphinxsearch indexer -c /var/lib/sphinxsearch/conf/sphinx.conf --rotate --all
```

Manual UI proof: `http://localhost:7001/` after `docker compose up -d`.
Create a user, grant `site_upload` and `torrents_edit`, upload a comic
torrent, open the series page, toggle checked, and browse by publisher,
creator, format, pack, and checked.
