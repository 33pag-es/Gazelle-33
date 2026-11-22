<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Wiki content for 33pages.
 *
 * 1. Rewrites the three articles seeded by 20261108050000_comic_rules.php
 *    (index, source flags, upload rules), but only where the body is still
 *    the text that migration wrote. An article staff have edited is left
 *    alone.
 * 2. Creates the articles that the rules and the page header link to and
 *    that a fresh install lacks. Those that state site policy are created
 *    readable by staff only (MinClassRead = 800) and open with a note that
 *    lists what has to be decided before they are published. The numbers in
 *    them are read from lib/config.php when the migration runs.
 */
final class ComicWiki extends AbstractMigration {
    private const STAFF = 800;
    private const USER  = 100;

    private const OLD_INDEX  = 'This wiki tracks comic series, creators and publishers. Use the upload form to add a series, an issue, or an issue pack. Staff mark torrents as checked after review.';
    private const OLD_SOURCE = 'The source flag identifies the origin of a torrent file. Comic uploads follow the same source-flag rules as every other torrent: do not remove or alter a source flag supplied by another tracker.';
    private const OLD_RULES  = "Comics only. A group is a series and every torrent is one issue or one issue pack.\n\n[b]Series[/b]: use the series title as the group title and the series start year as the year. Enter the publisher and every creator.\n\n[b]Issue[/b]: enter the issue number, or an issue label for annuals and specials. For a pack, enter the first and last issue. Add the scanner if known.\n\n[b]Files[/b]: upload .cbz, .cbr, .cb7 or .pdf files. Music, video and application uploads are not accepted.\n\n[b]Duplicates[/b]: do not upload an issue that already exists in the series. Use the existing series when adding another issue.\n\n[b]Review[/b]: staff mark torrents checked after they have been reviewed.";

    private const DRAFT = "[important]Staff draft, not visible to members. Decide the points marked DECIDE, rewrite the page, then lower \"minimum class to read\" to publish it. The rules already link here.[/important]\n\n";

    public function up(): void {
        $site   = SITE_NAME;
        $source = SOURCE_FLAG;
        $format = implode(', ', COMIC_FORMAT);

        $this->rewrite(1, 'Wiki', self::OLD_INDEX, <<<END
            == Welcome to $site ==

            $site is a private tracker for comics. It picks up where 32pages left off in 2020: every series has one page, and every issue or issue pack is a torrent on that page.

            == Start here ==

            [*] [url=rules.php]Golden rules[/url]: they apply everywhere on the site.
            [*] [url=rules.php?p=upload]Upload rules[/url]: read these before your first upload.
            [*] [url=rules.php?p=ratio]Ratio requirements[/url]
            [*] [url=rules.php?p=clients]Allowed clients[/url]
            [*] [url=wiki.php?action=article&id=113]Source flags[/url]: why you download your own torrent again after uploading it.

            == How the site is organised ==

            [*] [b]Series[/b]: a series page carries the title, the year the series started, the publisher, the creators, the cover and the tags.
            [*] [b]Issues[/b]: each torrent is one issue, or one pack of consecutive issues. It records the issue number or label, the scanner if known, and the file format.
            [*] [b]Checked[/b]: staff mark a torrent CHECKED once they have reviewed it. Everything else shows as UNCHECKED.
            [*] [b]Search[/b]: browse by series, publisher, creator, issue number, scanner, format, tag, pack and checked state.

            == Getting help ==

            Ask in the Help forum or send a [url=staffpm.php]Staff PM[/url].
            END
        );

        $this->rewrite(113, 'Source Flags', self::OLD_SOURCE, <<<END
            == What a source flag is ==

            A source flag is a short label inside a .torrent file that says which tracker the file belongs to. It makes the same data produce a different torrent on each tracker, so that one tracker's statistics cannot be mixed up with another's.

            == The $site source flag ==

            The $site flag is [b]{$source}[/b].

            When you upload a .torrent that does not carry this flag, $site adds it, and marks the torrent private if it was not. Either change gives the torrent a new identity. Your client is still seeding the file you made, which the tracker no longer recognises.

            [b]After every upload, download the torrent from its series page and add that file to your client.[/b] Point it at the data you already have; nothing is downloaded again. The upload page tells you when this is needed.

            To avoid the extra step, create your torrents as private and with the source set to [b]{$source}[/b].

            == Torrents from other trackers ==

            Do not remove or alter the source flag of a torrent you got from another tracker in order to upload it elsewhere. Make a new torrent from the data instead.
            END
        );

        $this->rewrite(127, 'Upload Rules', self::OLD_RULES, <<<END
            == 1. What belongs on $site ==

            === 1.1 Comics only ===
            Every upload is a comic: one issue, or one pack of consecutive issues of the same series. Music, video, applications and other books are not accepted.

            === 1.2 One issue or one pack per torrent ===
            A torrent holds a single issue, or a pack that runs from one issue number to a later one. Do not mix series in one torrent.

            === 1.3 File formats ===
            Accepted formats: [b]{$format}[/b]. Choose the format that matches the files in the torrent.

            == 2. The series ==

            === 2.1 Use the existing series ===
            Search before you upload. If the series already has a page, open it and use [b]Add issue[/b]. A new series page is only for a series the site does not have yet.

            === 2.2 Series details ===
            For a new series enter the series title, the year the series started, the publisher, and every creator you know (up to 20). Add a cover if you have one, and a description of at least a sentence.

            === 2.3 Who can change series details ===
            Series details are shared by every issue, so only staff change them once the series exists. If something is wrong, report the torrent or send a [url=staffpm.php]Staff PM[/url].

            == 3. The issue ==

            === 3.1 Issue number ===
            Enter the issue number. For a pack, enter the first and the last issue.

            === 3.2 Issue label ===
            Use the issue label for anything that has no plain number: annuals, specials, one-shots, variants.

            === 3.3 Scanner ===
            Name the scanner or release group if you know it. Leave the field empty if you do not; do not guess.

            == 4. Tags ==

            === 4.1 Tag the series ===
            Give a new series at least one tag. Tags describe the series (genre, theme, era), not your upload: no format, scanner or quality tags.

            === 4.2 Room for tags ===
            A series holds about 500 characters of tags. When it is full the site refuses another tag until one is removed.

            == 5. The torrent file ==

            === 5.1 Private and source flag ===
            Torrents must be private and carry the $site source flag. The site sets both for you if they are missing; you then have to download the torrent from the series page and seed that file. See [url=wiki.php?action=article&id=113]Source flags[/url].

            === 5.2 Seed what you upload ===
            A torrent that is never seeded is removed. Keep seeding until others have a full copy.

            == 6. Duplicates ==

            === 6.1 No duplicate issues ===
            Do not upload an issue that the series already has.

            == 7. Checked torrents ==

            === 7.1 What CHECKED means ===
            Staff mark a torrent CHECKED after reviewing it. UNCHECKED means nobody on staff has reviewed it yet, not that something is wrong with it.

            === 7.2 Editing a checked torrent ===
            You may edit your own upload. If you change the issue number, issue label, scanner or format of a checked torrent, it goes back to UNCHECKED until staff review it again.

            == 8. Fixing mistakes ==

            === 8.1 Ask staff ===
            Staff can move an issue to another series, split it into a new series, or merge two pages for the same series. Report the torrent and say what is wrong.
            END
        );

        // Linked by id from the page header (Tokens) and the forum rules.
        $this->create(57, 'Freeleech Tokens', self::USER, null, sprintf(<<<'END'
            == What a token does ==

            A freeleech token makes one torrent free for you: what you download from it does not count against your ratio.

            == Using a token ==

            Use the FL link beside a torrent's download link, and confirm.

            == How long a token lasts ==

            A token covers the torrent for %d days from the moment you use it. Finish the download within that time.
            END,
            FREELEECH_TOKEN_EXPIRY_DAYS,
        ));

        $this->create(96, 'Mature Content', self::STAFF, null, self::DRAFT . <<<'END'
            The forum rules say that mature imagery "must abide by the rules found here". This page is that link.

            == DECIDE ==

            [*] Which kinds of mature imagery may be posted in the forums, and in which forums.
            [*] Whether it must sit behind a [mature] tag or a spoiler.
            [*] What is never allowed.
            [*] Whether the same limits apply to series covers and descriptions. Comics include adult titles, so this needs its own answer.
            END
        );

        $this->create(null, 'Invites', self::STAFF, 'invite', self::DRAFT . sprintf(<<<'END'
            The Golden Rules link here.

            == What the site does today ==

            [*] New accounts start with %d invites.
            [*] Donors receive %d invites.
            [*] Invites can be bought with bonus points from the class with level %d upwards.
            [*] Open registration is a configuration setting (OPEN_REGISTRATION); when it is off, an invite is the only way in.

            == DECIDE ==

            [*] Who may invite, and whether inviters answer for the people they invite.
            [*] Whether invites may be offered anywhere outside the site.
            [*] Whether there will be recruiters and where.
            END,
            STARTING_INVITES, DONOR_INVITES, MIN_INVITE_CLASS,
        ));

        $this->create(null, 'Account Inactivity', self::STAFF, 'accountinactivity', self::DRAFT . sprintf(<<<'END'
            The Golden Rules link here.

            == What the site does today ==

            [*] An account that has not logged in for %d days is deactivated by a scheduled task.
            [*] A warning email is sent after %d days without a login.
            [*] Both run only if the inactive-user tasks are enabled in the scheduler.

            == DECIDE ==

            [*] Whether %d days is the period you want.
            [*] Which classes, if any, are exempt.
            [*] How a deactivated member gets the account back.
            END,
            INACTIVE_USER_DEACTIVATE_DAYS, INACTIVE_USER_WARN_DAYS, INACTIVE_USER_DEACTIVATE_DAYS,
        ));

        $this->create(null, 'User Classes', self::STAFF, 'classes', self::DRAFT . <<<'END'
            The Golden Rules link here.

            == What the site does today ==

            Promotions are automatic. The upstream thresholds, unchanged:

            [*] User to Member: 10 GiB uploaded, ratio 0.7, account 1 week old.
            [*] Member to Power User: 25 GiB uploaded, ratio 1.05, 5 uploads, 2 weeks.
            [*] Power User to Elite: 100 GiB uploaded, ratio 1.05, 50 uploads, 4 weeks.
            [*] Elite to Torrent Master: 500 GiB uploaded, ratio 1.05, 500 uploads, 8 weeks.
            [*] Torrent Master to Power TM: the same, plus 500 unique groups (series).
            [*] Power TM to Elite TM: the same, plus 500 "Perfect FLACs".
            [*] Elite TM to the next class: 2 TiB uploaded, 2000 uploads, 12 weeks, plus 2000 "Perfecter FLACs".

            == DECIDE ==

            [*] [b]The last two promotions cannot happen on a comics site[/b]: they require FLAC uploads. Choose a replacement (for example checked uploads) or drop those classes.
            [*] Whether the upload-size thresholds suit comics. Issues are small; 500 GiB is a very large number of them.
            [*] The class names. They are the upstream music names, and the list also contains upstream team classes.
            [*] What each class may do (invites, forums, collages).
            END
        );

        $this->create(null, 'Freeleech Autosnatching', self::STAFF, 'autofl', self::DRAFT . <<<'END'
            The Golden Rules link here as the "Freeleech Autosnatching Policy".

            == DECIDE ==

            [*] Whether automated snatching of freeleech torrents is allowed at all.
            [*] If it is, what limits apply (how many, how soon after upload, how long to seed).
            END
        );

        $this->create(null, 'Responsible Disclosure', self::STAFF, 'disclosurepolicy', self::DRAFT . <<<'END'
            The Golden Rules link here as "Responsible Disclosure".

            == DECIDE ==

            [*] How a member reports a security problem, and to whom.
            [*] What they may and may not do while testing.
            [*] Whether there is a reward.
            END
        );

        $this->create(null, 'Removing Your Upload', self::STAFF, 'deletetorrent', self::DRAFT . sprintf(<<<'END'
            The upload page links here when a folder name clashes with an existing torrent.

            == What the site does today ==

            [*] After removing %d torrents an uploader has to wait %d hours before removing another.
            [*] A torrent that is never seeded is removed %d hours after upload; notices are sent first.
            [*] A torrent that loses all its seeders is removed after %d days without one; notices are sent first.

            == DECIDE ==

            [*] When a member should remove an upload themselves and when they should report it instead.
            [*] Whether the removal periods above are the ones you want.
            END,
            USER_TORRENT_DELETE_MAX, USER_TORRENT_DELETE_HOURS, REMOVE_NEVER_SEEDED_HOUR, intdiv(REMOVE_UNSEEDED_HOUR, 24),
        ));
    }

    public function down(): void {
        // Seeded wiki prose is content, not schema. Leave it in place on rollback.
    }

    private function text(string $body): string {
        // heredoc bodies are indented with the code
        return trim((string)preg_replace('/^ {12}/m', '', $body));
    }

    private function rewrite(int $id, string $title, string $old, string $body): void {
        $st = $this->statement("
            UPDATE wiki_articles SET
                Title    = ?,
                Body     = ?,
                Revision = Revision + 1
            WHERE ID = ?
                AND Body = ?
        ");
        $st->execute([$title, $this->text($body), $id, $old]);
    }

    private function create(?int $id, string $title, int $minRead, ?string $alias, string $body): void {
        $pdo = $this->getAdapter()->getConnection();
        if ($alias !== null) {
            $st = $this->statement("SELECT 1 FROM wiki_aliases WHERE Alias = ?");
            $st->execute([$alias]);
            if ($st->fetchColumn()) {
                return;
            }
        }
        if ($id !== null) {
            $st = $this->statement("SELECT 1 FROM wiki_articles WHERE ID = ?");
            $st->execute([$id]);
            if ($st->fetchColumn()) {
                return;
            }
        }
        $st = $this->statement("
            INSERT INTO wiki_articles
                   (ID, Revision, Title, Body, MinClassRead, MinClassEdit, Author)
            VALUES (?,  1,        ?,     ?,    ?,            ?,            0)
        ");
        $st->execute([$id, $title, $this->text($body), $minRead, self::STAFF]);
        if ($alias !== null) {
            $st = $this->statement("
                INSERT INTO wiki_aliases (Alias, UserID, ArticleID) VALUES (?, 0, ?)
            ");
            $st->execute([$alias, $id ?? (int)$pdo->lastInsertId()]);
        }
    }

    private function statement(string $sql): \PDOStatement {
        $st = $this->getAdapter()->getConnection()->prepare($sql);
        if ($st === false) {
            throw new \RuntimeException("cannot prepare: $sql");
        }
        return $st;
    }
}
