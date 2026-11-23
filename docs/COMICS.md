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
   enum value in the same transaction. The migration must sort before
   `20250204000000_attr_tables.php`, which copies the MySQL category rows
   into PostgreSQL. This fork uses
   `20250203000000_comic_category_enum_early.php` for that prerequisite;
   the later `20261108000000_comic_category_enum.php` is idempotent.
2. In a following migration, update the PostgreSQL `category` row 7 to
   `upload = 'comic', is_grouped = true`; keep all rows.
3. Do not remap `request.id_category`; requests remain category 7 and
   stay dormant.

New search staging columns (MySQL, via a new migration):

- `sphinx_tg`: `publisher VARCHAR(255) NULL`, `creators VARCHAR(2048) NULL`.
- `sphinx_t`: `issue_start INT NULL`, `issue_end INT NULL`,
  `issue_label VARCHAR(32) NULL`, `scanner VARCHAR(100) NULL`,
  `pack TINYINT(1) NOT NULL DEFAULT 0`,
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
| Series type | `series_type` | yes for a new series | a `Gazelle\Enum\SeriesType` value | `torrents_group.series_type` |
| Status | `series_status` | no | a `Gazelle\Enum\SeriesStatus` value | `torrents_group.series_status` |
| Publisher | `publisher` | yes | 2-255 chars; autocomplete existing or create | `publisher.Name`, `torrents_group.PublisherID` |
| Imprint | `imprint` | no | 2-255 chars; belongs to the publisher | `imprint.name`, `torrents_group.imprint_id` |
| Language | `language` | no, default `en` | a key of `COMIC_LANGUAGE` | `torrents_group.language` |
| Creators | `creators[<role>]` | yes, at least 1 in any role | role is a `Gazelle\Enum\CreatorRole` value; comma-separated names, 1-255 chars each; dedupe; max 20 per role. `creators[]` without a role is stored as role `creator` | `creator.Name`, `series_creator` |
| Year | `year` | yes | 1900..current year+1 | `torrents_group.Year` |
| Issue | `issue_start` | yes unless `issue_label` | the number as printed, § Series organisation | `torrents.issue_number` |
| Through issue | `issue_end` | no | an issue number that sorts at or after `issue_start` | `torrents.issue_number_end` |
| Issue label | `issue_label` | no | 1-32 chars | `torrents.IssueLabel` |
| Release date | `release_date` | no | `YYYY-MM-DD`, 1900..next year | `torrents.release_date` |
| Source | `comic_source` | yes | `digital` or `scan` | `torrents.comic_source` |
| Scanner | `scanner` | no | 1-100 chars | `torrents.Scanner` |
| Collects | `collects` | no | one line per run: series link or id, then `1-6` or `7`; max 50 lines | `torrent_collect` |
| Format | `format` | yes | one of `FORMAT` | `torrents.Format` |
| Tags | `tags` | yes | existing tag rules, 2-200 chars | `torrents_tags` |
| Cover | `image` | no | `Image::fromUntrusted()` | `torrents_group.WikiImage` |
| Description | `desc` | yes | 10-1,000,000 chars | `torrents_group.WikiBody` |
| Torrent file | `file_input` | yes | `.torrent`, V1, not encrypted | `File\Torrent` |
| Existing series | `groupid` | no | existing group id | `torrents.GroupID` |

Derived values:

- `Pack = (issue_end sorts after issue_start)`.
- `IssueStart`, `IssueEnd` = the whole-number part of the two issue
  numbers; `issue_sort` = where `issue_start` sorts.
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
- `sections/torrents/edit_handle.php` gets a `$isComic` branch. Music
  validation remains untouched.
- The issue fields (`Comic\Upload::issueProperties()`) are validated for
  every editor. The series fields (`seriesProperties()`) are read and
  validated only for viewers with `torrents_edit`; an uploader's edit must
  not depend on the state of the series (members add and remove series
  tags from the series page). On edit the `tags` field accepts 1000
  characters instead of 200.
- Staff also edit title, year, cover, description, publisher, and creators
  on the group edit page (`torrents.php?action=editgroup`).

## Series page

Route: `torrents.php?id=<groupid>`.

`sections/torrents/details.php` branches when
`$tgroup->categoryName() === 'Comics'` and renders:

- `templates/comic/series-header.twig`: series title, type, year, status,
  publisher, imprint, language, creators by role, “Collected in”, tags,
  collage add.
- `templates/comic/series-torrents.twig`: table columns
  `Name | Issue | Released | Source | Scanner | Format | Checked | Files |
  Size | Snatches | Seeders | Leechers`, issues in number order.
- `templates/comic/torrent-row.twig`: `CHECKED`/`UNCHECKED` badge;
  download/report/edit links; what a collected edition collects.
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
- Flushes the torrent cache and refreshes the Manticore delta.
- Returns the series page with a flash message, or JSON when
  `AJAX` is defined.

UI:

- Series page row shows a `CHECKED` / `UNCHECKED` badge.
- Staff see a toggle link.
- Browse search accepts `checked=1|0`.
- When an editor without `torrents_edit` changes the issue start, issue
  end, issue label, scanner, or format of a checked torrent, the torrent
  returns to `Checked = 0`, `CheckedBy = NULL`, `CheckedAt = NULL` and the
  audit trail records the editor. Staff edits and release-description
  edits keep the checked state. The edit form warns the uploader.

## Series repair

All repair tools require `torrents_edit`.

- Add issue: the series page links to `upload.php?groupid=<id>`.
- Merge series: group edit page, `tgroup-merge`. Both groups must be
  Comics. The target keeps its publisher and creators.
- Move issue: torrent edit page, `editgroupid`. The destination must be a
  comic series. A series left empty is removed.
- Split issue: torrent edit page, `newgroup` with `title` and `year`.
  `Comic\Series::splitFromTorrent()` creates a comic series that starts
  with the publisher, creators, and tags of the old one, and refuses a
  title/year/publisher that already exists.
- Autocomplete: `torrents.php?action=autocomplete_comic&type=publisher|creator|imprint&query=`
  returns up to 10 names by prefix (`Name LIKE 'query%'` on an index;
  minimum 2 characters). Wired to `#publisher`, `#imprint` and the
  `.comic_creator` role fields on the upload, torrent edit, and group edit
  forms.

## Series organisation

The site follows the conventions of the comic databases (Metron, Comic
Vine, the `ComicInfo.xml` schema). Added by
`20261123000000_comic_standard.php`; new tables and columns follow
`docs/CodingStandards.txt` (singular table, `<table>_id`, lower-case
column names, descriptive ENUM values).

- **A series is one run of a title in one format.** Its identity is title
  + start year + publisher + type. The monthly issues, the trade
  paperbacks, the hardcovers, the omnibuses and the annuals of one title
  are separate series. `Comic\Series::findByTitlePublisherYear()` treats a
  series whose type is not set as matching any type.
- **Series type** (`torrents_group.series_type`, `Enum\SeriesType`):
  `single-issue` (the monthly comic), `limited-series`, `one-shot`,
  `annual`, `digital-chapters`, `graphic-novel`, `trade-paperback`,
  `hardcover`, `omnibus`. `TGroup::text()` shows the type after the title
  unless it is `single-issue`.
- **Status** (`series_status`, `Enum\SeriesStatus`): `ongoing`,
  `complete`, `cancelled`, `hiatus`, or not set.
- **Imprint** (`imprint` table, `torrents_group.imprint_id`): a publishing
  line of one publisher. Unique per publisher.
- **Language** (`torrents_group.language`): ISO 639-1 code from
  `COMIC_LANGUAGE`; default `COMIC_LANGUAGE_DEFAULT`.
- **Creator roles** (`series_creator.role`, `Enum\CreatorRole`): `writer`,
  `artist`, `penciller`, `inker`, `colorist`, `letterer`, `cover-artist`,
  `editor`, and `creator` for a name with no stated role (every creator
  entered before this migration). The key is series + creator + role.
  `Series::creatorList()` names each creator once;
  `Series::creatorRoleList()` groups them by role.
- **Issue numbers are text** (`torrents.issue_number`,
  `issue_number_end`): `1`, `0`, `-1`, `½`, `1.MU`, `23.1`. At most 16
  characters, beginning with a digit (`Comic\Torrent::ISSUE_NUMBER_REGEXP`);
  leading zeroes of a plain number are dropped. For a collected edition
  the number is the volume. `issue_sort` (DECIMAL) is the leading number
  of the text (`Comic\Torrent::issueSort()`), so `1.MU` sorts with `1` and
  `½` between `0` and `1`. The torrent list of a group is ordered by
  `issue_sort`, then `issue_number`, then `IssueLabel`; torrents with only
  a label come last. `IssueStart`/`IssueEnd`/`Pack` are derived and kept
  for the search attributes and the existing API keys.
- **Release date** (`torrents.release_date`): the on-sale date of the
  issue, optional.
- **Source** (`torrents.comic_source`, `Enum\ComicSource`): `digital` or
  `scan`; required on upload and edit, empty on torrents uploaded before.
  It is part of the torrent label, and an uploader changing it withdraws
  the checked state.
- **Collects** (`torrent_collect`): a torrent of a collected edition lists
  the runs of issues it reprints (`tgroup_id`, `issue_start`,
  `issue_end`). The series page shows them under the torrent, and the
  collected series shows “Collected in”. `Comic\Collect::moveSeries()`
  keeps the links when a series is merged; removing a torrent removes its
  rows.
- **Story arcs and events**: collage category 10, “Story Arc”
  (`COLLAGE`, `Enum\CollageType::storyArc`).

The ENUM values of the four columns are the cases of the PHP enums in the
same order, and the search index stores the position (`column + 0`).
Append new values; never insert or reorder
(`ComicStandardTest::testEnumMatchesColumn`).

Access paths: the series page reads one group by primary key, its
torrents by `GroupID`, `torrent_collect` by `torrent_id` (through the
group's torrents) and by `tgroup_id` (both indexed), and `imprint` by
primary key. Browse filters go through Manticore, not MySQL, so the new
series columns carry no MySQL index. Imprint autocomplete is a prefix
match on `imprint_name_idx`.

## Tag limit

`torrents_group.TagList`, `deleted_torrents_group.TagList`,
`sphinx_tg.tags`, and `sphinx_delta.TagList` are upstream `VARCHAR(500)`
columns that hold the tags of a group separated by spaces. The limit is
kept. `Comic\Series::hasTagRoom()` makes `torrents.php?action=add_tag`
return 400 with a message when a new tag would not fit, and the staff
edit form rejects a tag list longer than `Comic\Series::TAGLIST_MAX`.

## Branding and themes

The site is 33pages, the successor to 32pages (32P), which closed in
October 2020. The wordmark keeps the shape of the 32P one: the number in
the accent colour, `PAGES` in white. The site name itself always comes
from `SITE_NAME`; no template spells out another site's name.

- Theme: `sass/33pages/style.scss`, the only stylesheet offered
  (`STYLESHEET_OFFERED` in `lib/config.php`). It is a fork of
  `sass/post_office/style.scss` with the same layout. The header comment
  of the file lists the four differences: self-hosted Lato, the 33pages
  logo, the green accent `#86c232`, and the `33PAGES ADDITIONS` block at
  the end. Put new theme rules in that block. To pick up a later Post
  Office change, diff the two files. Build with `make css`; lint with
  `make lint-css` (`misc/stylelint.json`).
- No third-party requests: the upstream Post Office theme loads Lato from
  `fonts.googleapis.com`, which tells Google which member loaded which
  page. The fork serves the font from
  `public/static/styles/33pages/fonts/` (latin and latin-ext subsets, SIL
  OFL, licence in `OFL.txt` beside them).
- A stylesheet named `X Y` in the `stylesheets` table loads
  `public/static/styles/x_y/style.css` and icons from
  `public/static/styles/x_y/images/`. Migrations
  `20261121000000_comic_stylesheets.php` and
  `20261122000000_comic_single_stylesheet.php` register 33pages, make it
  the default, and move every user to it. The upstream stylesheet rows and
  Sass sources stay, dormant. A member's external stylesheet URL is kept
  and still works; the settings page shows only that field.
- Logo, in `public/static/svg/site/`: `33pages-lockup.svg` (header, login
  and other public pages), `33pages-lockup-33p.svg` (splash page: `33P`
  alone), and
  `33pages-mark.svg` (page icon). The letters are Lato Black converted to
  outlines, so the files render the same everywhere and need no font.
- Site icons in `public/`: `favicon.svg`, `favicon.ico`,
  `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`,
  `android-chrome-*.png`, `mstile-150x150.png`, `safari-pinned-tab.svg`,
  `site.webmanifest`. These replace upstream files of the same name.
- Public pages (`sass/public/style.scss`, `templates/logo.twig`,
  `templates/index/public.twig`) use the same dark palette and typeface;
  the splash page shows only the `33P` mark, one line (“Continued from the
  last issue.”, in `templates/index/public.twig`) and the entry links. The
  line is meant for people who remember 32P; it explains nothing to anyone
  else, on purpose. There is no tagline.

## Seeded site content

Three migrations replace upstream's music-site content. Each leaves
anything staff have already changed alone.

- Forums (`20261122010000_comic_forums.php`): the Music category becomes
  Comics, with Comics, New This Week, Recommendations, Creators and
  Publishers, and Scans and Editions. “Orpheus” becomes “33pages”, “Chat”
  becomes “The Lounge”, “Games” becomes “Movies, TV and Games”. Twelve
  unrestricted music or generic forums are removed, each only while it has
  no thread. Staff and class-restricted forums are untouched. Forum ids
  named in `lib/config.php` keep their meaning; Editing (34) becomes
  staff-only. The forum list is cached: flush memcached after running the
  migration on a live site.
- Wiki (`20261122020000_comic_wiki.php`): rewrites the index (1), Source
  Flags (113) and Upload Rules (127) while they still hold the text of
  `20261108050000_comic_rules.php`. The upload rules are numbered headings
  so the rule page's table of contents and filter work. It also creates
  the pages the rules and header link to: Freeleech Tokens (57, public)
  and, readable by staff only until published, Mature Content (96),
  `invite`, `accountinactivity`, `classes`, `autofl`, `disclosurepolicy`,
  `deletetorrent`. The staff-only pages state what the software does, with
  numbers read from `lib/config.php` at migration time, and list what has
  to be decided. They state no policy.
- User menu (`20261122030000_comic_nav.php`): “Random Album” becomes
  “Random Series”.
- Series organisation (`20261123010000_comic_wiki_standard.php`): brings
  the wiki index (1) and the Upload Rules (127) in line with § Series
  organisation: series types, collected editions and annuals as their own
  series, issue numbers as printed, source, release date, collected
  issues. It replaces passage by passage, and only passages that still
  read as the wiki migration wrote them.

Known gap recorded in the `classes` page: the last two automatic
promotions in `Manager\User::promotionCriteria()` require “Perfect FLAC”
uploads and cannot be met on this site.

Phones: the 33pages theme is a fixed desktop layout. For that stylesheet
`templates/index/private-header.twig` asks for a 1000px viewport, so a
phone shows the whole page scaled down instead of a wrapped header.

## Browse and search

`app/Search/Torrent.php` additions:

- Fields: `series` (maps to `groupname`), `creatorname`, `publishername`,
  `imprintname`, `language`, `issue`, `scanner`, `format`, `taglist`,
  `description`, `filelist`. `issue` holds the issue label and the issue
  numbers as printed; a search for a plain number uses the `issue_start`
  attribute instead.
- Attributes: `checked` (0/1), `pack` (0/1), `issue_start`, `issue_end`,
  `series_type`, `series_status`, `comic_source`. The last three are
  filtered by enum value in the URL (`series_type=omnibus`) and stored as
  the position of the value in the MySQL ENUM.
- `searchstr` expands to `(groupname,publishername,creatorname,yearfulltext)`.
- Keep the music fields and attributes in the class so upstream patches
  still apply; hide them in the comic UI.

`misc/docker/manticore/manticore.conf` source changes:

- `sphinx_tg`: add `publisher` from `publisher.Name`, `creators` from
  `creator.Name` joined through `series_creator`.
- `sphinx_t`: add `issue_start`, `issue_end`, `issue_label`, `scanner`,
  `pack`, `checked`.
- `sql_query`: select the new columns and expose them as fields or
  attributes.
- `sql_joined_field creatorname`: reuse the `sphinx_a` pattern, joining
  `series_creator` + `creator`, ordered by torrent id.

Manticore 29 uses plain `table` blocks (`type = plain`). The delta table
must declare `killlist_target = torrents:kl`, and the delta source must not
select `Creators` twice. The Sphinx CJK `ngram_chars` settings are omitted
because they overlap the existing charset table; rebuild indices from MySQL
instead of converting Sphinx data files.

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
| Collage artist/label/discography types | collage create/edit templates | hide those type options; keep Personal, Theme, Genre, Staff picks, Charts, Awards, Series, Story Arc |
| Music rules prose | `templates/rules/*.twig` | rewrite upload/tag/request/collage/forum sections for comics |
| Rules wiki article | `wiki_articles` id `RULES_WIKI_PAGE_ID` (127), `INDEX_WIKI_PAGE_ID` (1), `SOURCE_FLAG_WIKI_PAGE_ID` (113) | seed comic rules via a new migration or admin edit; templates alone do not replace DB content |
| Top 10 Last.fm / format | `sections/top10/*`, `templates/top10/*` | hide Last.fm and music format dropdown |
| Notification filters | `app/Notification/Filter.php`, `templates/user/edit-notification-filter.twig` | hide artist/format/media/encoding/release/label dimensions; keep tags |
| User display settings | `templates/user/setting/display.twig` | replace album/artist wording and autocomplete |
| Torrent merge/split/change category | `sections/torrents/merge.php`, `new_groupid.php`, `edit_group*` | hide or adapt to series; do not expose music split fields |
| Riplogs / log links | `templates/torrent/detail-torrent.twig`, `sections/torrents/*log*` | hide; comics have no logs |
| OpenSearch artist/request | `templates/index/private-header.twig` | hide artist/request search providers |
| Random artist | `sections/random/index.php`, `templates/index/page-header.twig` | hide `random.php?action=artist` |
| API music keys | `app/API/*`, `app/Json/*` | keep keys for compatibility; add comic keys |

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
| `misc/example.production.config.php` | production tracker host, `ALLOWED_PROXY` for the pinned Compose gateway | `// COMIC-ONLY` |
| `Makefile` | Docker-only commands | `# COMIC-ONLY` |
| `docker-compose.yml` | persist `/var/lib/gazelle` storage | `# COMIC-ONLY` |
| `misc/docker/web/entrypoint.sh` | cache ownership fallback and storage initialization for Docker Desktop | `# COMIC-ONLY` |
| `README.md` | host policy, production stack, reverse proxy and client IP | `<!-- COMIC-ONLY -->` |
| `sections/upload/upload.php` | default category, load music JS only for Music | `// COMIC-ONLY` |
| `sections/ajax/upload.php` | category map/default | `// COMIC-ONLY` |
| `sections/ajax/torrent_tag_add.php` | refuse a tag that overflows the tag list | `// COMIC-ONLY` |
| `sections/upload/upload_handle.php` | comic branch | `// COMIC-ONLY` |
| `app/Upload.php` | comic form context | `// COMIC-ONLY` |
| `templates/upload/comic.twig` | comic fields | `{# COMIC-ONLY #}` |
| `templates/upload/header.twig` | show only enabled categories | `{# COMIC-ONLY #}` |
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
| `app/View.php` | hide music nav items, rename random group link | `// COMIC-ONLY` |
| `app/Torrent.php` | comic filename | `// COMIC-ONLY` |
| `app/TorrentDeleted.php` | comic deleted-torrent columns | `// COMIC-ONLY` |
| `app/Enum/CollageType.php` | Story Arc collage category | `// COMIC-ONLY` |
| `app/TorrentAbstract.php` | comic primary extensions, label/perfect-flac guards | `// COMIC-ONLY` |
| `tests/helper.php` | comic test factories | `// COMIC-ONLY` |
| `app/Manager/Tag.php` | comic category for tag suggestions | `// COMIC-ONLY` |
| `app/Collector/Collage.php` | comic category for collage zips | `// COMIC-ONLY` |
| `app/Collector/TList.php` | comic category for torrent-list zips | `// COMIC-ONLY` |
| `app/Search/Torrent.php` | comic search fields | `// COMIC-ONLY` |
| `app/Stats/Torrent.php` | comic series/issue/checked counters | `// COMIC-ONLY` |
| `app/Route/Index/Authenticated.php` | hide featured album | `// COMIC-ONLY` |
| `sections/requests/index.php` | 404 for comics | `// COMIC-ONLY` |
| `sections/better/index.php` | 404 for comics | `// COMIC-ONLY` |
| `sections/artist/index.php` | 404 for comics | `// COMIC-ONLY` |
| `sections/top10/index.php` | hide Last.fm | `// COMIC-ONLY` |
| `sections/top10/torrents.php` | hide music filters | `// COMIC-ONLY` |
| `sections/contest/admin.php` | 404 for comics | `// COMIC-ONLY` |
| `templates/index/page-header.twig` | hide artist/request search | `{# COMIC-ONLY #}` |
| `templates/index/private-header.twig` | hide music OpenSearch/RSS | `{# COMIC-ONLY #}` |
| `templates/index/private-sidebar.twig` | comic counters, hide AOTM | `{# COMIC-ONLY #}` |
| `templates/rules/tag.twig` | comic tag prose | `{# COMIC-ONLY #}` |
| `templates/rules/collage.twig` | comic collage prose | `{# COMIC-ONLY #}` |
| `templates/user/edit-notification-filter.twig` | hide music dimensions | `{# COMIC-ONLY #}` |
| `templates/user/setting/display.twig` | comic display wording | `{# COMIC-ONLY #}` |
| `templates/collage/new.twig` | hide artist/label types | `{# COMIC-ONLY #}` |
| `templates/top10/linkbox.twig` | hide Last.fm tab | `{# COMIC-ONLY #}` |
| `misc/docker/manticore/manticore.conf` | comic index fields | `# COMIC-ONLY` |
| `app/Json/TGroup.php` | comic response fields | `// COMIC-ONLY` |
| `app/Json/Torrent.php` | comic response fields | `// COMIC-ONLY` |
| `app/API/Torrent.php` | comic API fields | `// COMIC-ONLY` |
| `app/API/Request.php` | guard non-music artist role | `// COMIC-ONLY` |
| `docs/07-API.md` | comic API fields | `<!-- COMIC-ONLY -->` |
| `sections/contest/index.php` | 404 for comics | `// COMIC-ONLY` |
| `sections/random/index.php` | block random artist for comics | `// COMIC-ONLY` |
| `sections/rules/index.php` | 404 for request rules | `// COMIC-ONLY` |
| `sections/user/notify_handle.php` | comic notification dimensions and category mapping | `// COMIC-ONLY` |
| `sections/torrents/edit_category_handle.php` | reject comic category changes | `// COMIC-ONLY` |
| `templates/index/private-main.twig` | hide contest widget | `{# COMIC-ONLY #}` |
| `templates/rules/golden.twig` | remove request-abuse link | `{# COMIC-ONLY #}` |
| `templates/rules/toc.twig` | hide request rules row | `{# COMIC-ONLY #}` |
| `templates/torrent/detail-header.twig` | hide request links, add-issue link | `{# COMIC-ONLY #}` |
| `templates/torrent/edit-torrent.twig` | hide category change, show move/split for comics | `{# COMIC-ONLY #}` |
| `templates/tgroup/edit.twig` | series publisher/creators, series merge | `{# COMIC-ONLY #}` |
| `sections/torrents/edit_group.php` | series publisher/creators | `// COMIC-ONLY` |
| `sections/torrents/edit_group_handle.php` | series publisher/creators, comic year floor, skip absent freeleech fields | `// COMIC-ONLY` |
| `sections/torrents/merge.php` | series merge | `// COMIC-ONLY` |
| `sections/torrents/new_groupid.php` | move issue to another series | `// COMIC-ONLY` |
| `sections/torrents/new_group_handle.php` | split issue into a new series | `// COMIC-ONLY` |
| `public/static/functions/autocomplete.mjs` | publisher/creator autocomplete | `// COMIC-ONLY` |
| `app/Manager/Stylesheet.php` | offer only the stylesheets in `STYLESHEET_OFFERED` | `// COMIC-ONLY` |
| `app/User/Stylesheet.php` | cache key bump for the new default | `// COMIC-ONLY` |
| `sass/public/style.scss` | 33pages colours and typeface on public pages | `// COMIC-ONLY` |
| `templates/user/setting/appearance.twig` | hide the stylesheet chooser when one stylesheet is offered | `{# COMIC-ONLY #}` |
| `templates/logo.twig` | 33pages wordmark | `{# COMIC-ONLY #}` |
| `templates/index/public.twig` | splash page without the upstream poem | `{# COMIC-ONLY #}` |
| `templates/index/public-header.twig` | SVG site icon | `{# COMIC-ONLY #}` |
| `templates/index/private-header.twig` | SVG site icon | `{# COMIC-ONLY #}` |
| `templates/donation/index.twig`, `templates/donation/special-rank-1.twig`, `templates/seedbox/view.twig`, `templates/recovery/index.twig`, `templates/contest/intro.twig` | site name and URL from config instead of upstream's | `{# COMIC-ONLY #}` |
| `sections/referral/index.php` | 33pages wordmark | `// COMIC-ONLY` |
| `tests/phpunit/UserTest.php` | stylesheet test starts from a second installed stylesheet | `// COMIC-ONLY` |
| `templates/user/sidebar-stats.twig`, `sections/user/user.php` | no artist, request or FLAC statistics on profiles | `{# COMIC-ONLY #}` / `// COMIC-ONLY` |
| `templates/user/setting/display.twig`, `personal.twig`, `paranoia.twig` | series wording; request, Last.fm, FLAC and artist rows hidden but kept in the form | `{# COMIC-ONLY #}` |
| `templates/collage/browse.twig`, `sections/bookmarks/torrents.php`, `templates/comment/list.twig` | no artist or request tabs; no music collage categories in the filter | `{# COMIC-ONLY #}` / `// COMIC-ONLY` |
| `templates/admin/toolbox.twig` | hide the Feature Album tool | `{# COMIC-ONLY #}` (inline condition) |
| `templates/upload/footer.twig` | no request grace-period notice | `{# COMIC-ONLY #}` |
| `templates/rules/forum.twig`, `templates/rules/upload.twig` | comics wording, no request rule, comic filter examples | `{# COMIC-ONLY #}` |
| `public/static/common/avatars/default.png` | default avatar without the upstream waveform | none (binary) |
| `public/favicon.ico`, `public/favicon-*.png`, `public/apple-touch-icon.png`, `public/android-chrome-*.png`, `public/mstile-150x150.png`, `public/safari-pinned-tab.svg`, `public/site.webmanifest` | 33pages site icons | none (binary/JSON) |

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
  `$tgroup->categoryId() === CATEGORY_COMIC || $torrent->format() === 'FLAC'`.
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
`app/Comic/SeriesPage.php`, new `sections/torrents/check.php`,
`app/TGroup.php`, `app/Torrent.php`, `app/TorrentAbstract.php`,
`tests/phpunit/Comic/SeriesPageTest.php`,
`tests/phpunit/Comic/CheckedTest.php`.

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

### Phase 5 — Browse and search

Files: `app/Search/Torrent.php`, `templates/torrent/browse-header.twig`,
`sections/torrents/browse.php`, `misc/docker/manticore/manticore.conf`,
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
`app/API/Torrent.php`, `app/API/Request.php`,
`docs/07-API.md`, `tests/phpunit/Comic/ApiTest.php`.

Behavior: comic fields appear in JSON/API responses; music keys remain
for compatibility; `API\Request` guards `artistRole()` for non-Music so
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
docker compose exec -T -u manticore manticore indexer -c /var/lib/manticore/manticore.conf --rotate --all
```

Manual UI proof: `http://localhost:7001/` after `docker compose up -d`.
Create a user, grant `site_upload` and `torrents_edit`, upload a comic
torrent, open the series page, toggle checked, and browse by publisher,
creator, format, pack, and checked.
