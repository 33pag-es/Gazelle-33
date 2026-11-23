<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bring the wiki index (1) and the Upload Rules (127) in line with
 * 20261123000000_comic_standard.php: series types, collected editions and
 * annuals as their own series, issue numbers as printed, source, release
 * date and collected issues.
 *
 * Each passage is replaced only where it still reads as
 * 20261122020000_comic_wiki.php wrote it, so anything staff have reworded
 * is left alone.
 */
final class ComicWikiStandard extends AbstractMigration {
    private const INDEX = [
        '[*] [b]Series[/b]: a series page carries the title, the year the series started, the publisher, the creators, the cover and the tags.'
            => <<<'END'
            [*] [b]Series[/b]: a series is one run of a title in one format. Its page carries the title, the type (monthly issues, limited series, trade paperbacks, omnibuses and so on), the year it started, the publisher and imprint, the language, the creators by role, the cover and the tags.
            [*] [b]Collected editions[/b]: trade paperbacks, hardcovers and omnibuses have their own series page, and so do annuals. Each volume says which issues it collects, and the series it collects from links back to it.
            END,
        '[*] [b]Issues[/b]: each torrent is one issue, or one pack of consecutive issues. It records the issue number or label, the scanner if known, and the file format.'
            => '[*] [b]Issues[/b]: each torrent is one issue, one volume, or one pack of consecutive issues. It records the issue number as printed or a label, the release date if known, whether the files are a digital release or a scan, the scanner if known, and the file format. Issues are listed in number order.',
        '[*] [b]Search[/b]: browse by series, publisher, creator, issue number, scanner, format, tag, pack and checked state.'
            => <<<'END'
            [*] [b]Search[/b]: browse by series, series type, status, publisher, imprint, language, creator, issue number, source, scanner, format, tag, pack and checked state.
            [*] [b]Story arcs[/b]: a collage in the Story Arc category puts the issues of an arc or event in reading order across series.
            END,
    ];

    private const RULES = [
        'Every upload is a comic: one issue, or one pack of consecutive issues of the same series. Music, video, applications and other books are not accepted.'
            => 'Every upload is a comic: one issue, one volume of a collected edition, or one pack of consecutive issues of the same series. Music, video, applications and other books are not accepted.',
        'Search before you upload. If the series already has a page, open it and use [b]Add issue[/b]. A new series page is only for a series the site does not have yet.'
            => <<<'END'
            Search before you upload. If the series already has a page, open it and use [b]Add issue[/b]. A new series page is only for a series the site does not have yet.

            === 2.2 One series per format ===
            A series is one run of a title in one format, so choose the series type with care:
            [*] [b]Single Issue[/b]: the regular comic, issue by issue. Most series are this.
            [*] [b]Limited Series[/b]: a series planned with a fixed number of issues.
            [*] [b]One-Shot[/b]: a story complete in one issue.
            [*] [b]Annual[/b]: the annuals of a title. They are their own series, not issues of the regular one.
            [*] [b]Digital Chapters[/b]: a comic released in digital chapters.
            [*] [b]Graphic Novel[/b]: a book that was not published as single issues first.
            [*] [b]Trade Paperback[/b], [b]Hardcover[/b], [b]Omnibus[/b]: collected editions. Each line of collected editions is its own series, separate from the issues it collects.
            A relaunch that starts again from issue 1 is a new series with its own start year.
            END,
        '=== 2.2 Series details ==='
            => '=== 2.3 Series details ===',
        'For a new series enter the series title, the year the series started, the publisher, and every creator you know (up to 20). Add a cover if you have one, and a description of at least a sentence.'
            => 'For a new series enter the series title, the series type, the year the series started, the publisher, and the language if it is not English. Enter the imprint if the publisher has one for the series, and the status if you know it. Enter every creator you know under the role they had (up to 20 names per role); at least one creator is required. Use Artist when one person drew the book and pencils and inks are not credited separately. Add a cover if you have one, and a description of at least a sentence.',
        '=== 2.3 Who can change series details ==='
            => '=== 2.4 Who can change series details ===',
        'Enter the issue number. For a pack, enter the first and the last issue.'
            => 'Enter the issue number as it is printed: 1, 0, -1, ½, 1.MU. Do not pad it with zeroes. For a collected edition, enter the volume number. For a pack, enter the first and the last issue.',
        'Use the issue label for anything that has no plain number: annuals, specials, one-shots, variants.'
            => 'Use the issue label for an issue that has no number, and for a variant. The label is shown in place of the number, so when there is a number, enter it as well: it decides where the issue is listed. Annuals and one-shots are not labels; they are series of their own type (rule 2.2).',
        '=== 3.3 Scanner ==='
            => <<<'END'
            === 3.3 Source ===
            Say whether the files are [b]Digital[/b] (a file sold by the publisher or a store) or a [b]Scan[/b] (made from a printed copy). This is required.

            === 3.4 Release date ===
            Enter the day the issue went on sale if you know it. Leave it empty if you do not; do not guess.

            === 3.5 Collected issues ===
            For a volume of a collected edition, list the issues it reprints in the Collects field, one series per line: the link or id of the series, then the issues, such as [b]1234 1-6[/b]. The series must already have a page.

            === 3.6 Scanner ===
            END,
        'You may edit your own upload. If you change the issue number, issue label, scanner or format of a checked torrent, it goes back to UNCHECKED until staff review it again.'
            => 'You may edit your own upload. If you change the issue number, issue label, source, scanner or format of a checked torrent, it goes back to UNCHECKED until staff review it again.',
    ];

    public function up(): void {
        $this->replace(1, self::INDEX);
        $this->replace(127, self::RULES);
    }

    public function down(): void {
        // Seeded wiki prose is content, not schema. Leave it in place on rollback.
    }

    /**
     * @param array<string, string> $passage old text => new text
     */
    private function replace(int $id, array $passage): void {
        $pdo  = $this->getAdapter()->getConnection();
        $find = $pdo->prepare("SELECT wa.Body FROM wiki_articles wa WHERE wa.ID = ?");
        $find->execute([$id]);
        $old = $find->fetchColumn();
        if (!is_string($old)) {
            return;
        }
        $new = strtr($old, $passage);
        if ($new === $old) {
            return;
        }
        $pdo->prepare("
            UPDATE wiki_articles SET
                Body     = ?,
                Revision = Revision + 1
            WHERE ID = ?
        ")->execute([$new, $id]);
    }
}
