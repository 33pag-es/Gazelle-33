<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicRules extends AbstractMigration {
    public function up(): void {
        $this->query("
            INSERT INTO wiki_articles
                   (ID, Revision, Title, Body, MinClassRead, MinClassEdit, Author)
            VALUES (1,   1, 'Wiki',
                    'This wiki tracks comic series, creators and publishers. Use the upload form to add a series, an issue, or an issue pack. Staff mark torrents as checked after review.',
                    0, 100, 0),
                   (113, 1, 'Source Flags',
                    'The source flag identifies the origin of a torrent file. Comic uploads follow the same source-flag rules as every other torrent: do not remove or alter a source flag supplied by another tracker.',
                    0, 100, 0),
                   (127, 1, 'Upload Rules',
                    'Comics only. A group is a series and every torrent is one issue or one issue pack.\\n\\n[b]Series[/b]: use the series title as the group title and the series start year as the year. Enter the publisher and every creator.\\n\\n[b]Issue[/b]: enter the issue number, or an issue label for annuals and specials. For a pack, enter the first and last issue. Add the scanner if known.\\n\\n[b]Files[/b]: upload .cbz, .cbr, .cb7 or .pdf files. Music, video and application uploads are not accepted.\\n\\n[b]Duplicates[/b]: do not upload an issue that already exists in the series. Use the existing series when adding another issue.\\n\\n[b]Review[/b]: staff mark torrents checked after they have been reviewed.',
                    0, 100, 0)
            ON DUPLICATE KEY UPDATE
                Title    = VALUES(Title),
                Body     = VALUES(Body),
                Revision = Revision + 1,
                Date     = now()
        ");
    }

    public function down(): void {
        // Seeded wiki prose is content, not schema. Leave it in place on rollback.
    }
}
