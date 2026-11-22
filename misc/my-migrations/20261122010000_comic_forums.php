<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Replace the upstream (music site) default forums with a comics set.
 *
 * A forum is only removed while it has no thread, so on a site that is
 * already in use nothing with content disappears. Forum ids that
 * lib/config.php refers to are kept: Announcements (12), Staff (5), Trash
 * (4), Invitations (39) and Editing (34, which becomes staff-only because
 * edit requests are not offered to members). Staff and class-restricted
 * forums are kept as they are.
 *
 * The forum list is cached; flush memcached after running this on a site
 * that is already up.
 */
final class ComicForums extends AbstractMigration {
    /** Unrestricted upstream forums that have no place on a comics site */
    private const REMOVE = [
        'Artists', 'Audiophile', 'BitTorrent', 'Concerts and Events', 'Music',
        'Offered', 'Projects', 'Serious Discussions', 'The Studio', 'Tutorials',
        'Vanity House', 'Vinyl',
    ];

    public function up(): void {
        // Categories: Site, Comics, Suggestions, Community, Help, Trash
        $this->execute("UPDATE forums_categories SET Name = 'Comics', Sort = 2 WHERE Name = 'Music'");

        $rename = $this->statement("
            UPDATE forums SET Name = ?, Description = ? WHERE Name = ?
        ");
        foreach (
            [
                ['Orpheus', '33pages',              'The site itself: how it works and where it is going'],
                ['Chat',    'The Lounge',           'Everything that is not comics'],
                ['Games',   'Movies, TV and Games', 'Adaptations and everything else on a screen'],
                ['Help!',   'Help',                 'Questions about the site, your client or your account'],
            ] as [$old, $new, $description]
        ) {
            $rename->execute([$new, $description, $old]);
        }

        $describe = $this->statement("
            UPDATE forums SET Description = ? WHERE Name = ? AND coalesce(Description, '') = ''
        ");
        foreach (
            [
                ['Announcements', 'News from staff'],
                ['Bugs',          'Something on the site is broken'],
                ['Suggestions',   'Ideas for the site'],
                ['Technology',    'Readers, tablets, software and hardware'],
            ] as [$name, $description]
        ) {
            $describe->execute([$description, $name]);
        }

        $this->statement("
            DELETE FROM forums
            WHERE NumTopics = 0
                AND Name IN (" . implode(', ', array_fill(0, count(self::REMOVE), '?')) . ")
        ")->execute(self::REMOVE);

        // Edit requests are not offered to members; the forum they post to is for staff.
        $this->execute("
            UPDATE forums SET MinClassRead = 800, MinClassWrite = 800, MinClassCreate = 800
            WHERE ID = 34 AND Name = 'Editing' AND NumTopics = 0
        ");

        // The comics forums, in the category that used to be Music
        $category = $this->statement("SELECT ID FROM forums_categories WHERE Name = 'Comics'");
        $category->execute();
        $categoryId = (int)$category->fetchColumn();
        if (!$categoryId) {
            return;
        }
        $this->statement("
            UPDATE forums SET CategoryID = ?, Sort = 10, Description = ?
            WHERE Name = 'Comics'
        ")->execute([$categoryId, 'Series, runs, creators and everything about the books']);

        $exists = $this->statement("SELECT 1 FROM forums WHERE Name = ?");
        $insert = $this->statement("
            INSERT INTO forums
                   (CategoryID, Sort, Name, Description, MinClassRead, MinClassWrite, MinClassCreate, LastPostAuthorID)
            VALUES (?,          ?,    ?,    ?,           100,          100,           100,            0)
        ");
        foreach (
            [
                [20, 'New This Week',           'The week\'s releases'],
                [30, 'Recommendations',         'What to read next, and why'],
                [40, 'Creators and Publishers', 'Writers, artists, imprints and the business'],
                [50, 'Scans and Editions',      'Scan quality, digital editions, formats and collections'],
            ] as [$sort, $name, $description]
        ) {
            $exists->execute([$name]);
            if (!$exists->fetchColumn()) {
                $insert->execute([$categoryId, $sort, $name, $description]);
            }
            $exists->closeCursor();
        }
    }

    public function down(): void {
        // Forum layout is site content. Leave it in place on rollback.
    }

    private function statement(string $sql): \PDOStatement {
        $st = $this->getAdapter()->getConnection()->prepare($sql);
        if ($st === false) {
            throw new \RuntimeException("cannot prepare: $sql");
        }
        return $st;
    }
}
