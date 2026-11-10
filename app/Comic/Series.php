<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use Gazelle\TGroup;

final class Series extends \Gazelle\Base {
    public static function setPublisher(TGroup $tgroup, string $name): void {
        $publisher = Publisher::softCreate($name);
        self::$db->prepared_query("
            UPDATE torrents_group SET
                PublisherID = ?
            WHERE ID = ?
        ", $publisher->id, $tgroup->id);
        $tgroup->flush();
    }

    public static function publisher(TGroup $tgroup): ?string {
        $publisherId = $tgroup->info()['PublisherID'] ?? null;
        if (!$publisherId) {
            return null;
        }
        $name = self::$db->scalar("
            SELECT Name FROM publisher WHERE PublisherID = ?
        ", $publisherId);
        return $name === null || $name === false ? null : (string)$name;
    }

    /**
     * Replace the creator list, preserving the order of first occurrence.
     */
    public static function setCreators(TGroup $tgroup, array $names): int {
        $creatorIdList = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $creator = Creator::softCreate($name);
            $creatorIdList[$creator->id] = true;
        }
        $ids = array_keys($creatorIdList);

        self::$db->prepared_query("
            DELETE FROM series_creator WHERE GroupID = ?
        ", $tgroup->id);
        if ($ids) {
            $args = [];
            foreach ($ids as $sequence => $creatorId) {
                $args[] = $tgroup->id;
                $args[] = $creatorId;
                $args[] = $sequence;
            }
            self::$db->prepared_query("
                INSERT INTO series_creator
                       (GroupID, CreatorID, Sequence)
                VALUES " . placeholders($ids, '(?, ?, ?)'),
                ...$args,
            );
        }
        $tgroup->flush();
        return count($ids);
    }

    /**
     * @return string[]
     */
    public static function creatorList(TGroup $tgroup): array {
        self::$db->prepared_query("
            SELECT c.Name
            FROM creator c
            INNER JOIN series_creator sc ON (sc.CreatorID = c.CreatorID)
            WHERE sc.GroupID = ?
            ORDER BY sc.Sequence, c.Name
        ", $tgroup->id);
        return self::$db->collect(0);
    }

    public static function findByTitlePublisherYear(
        string $title,
        int    $year,
        int    $publisherId,
    ): ?TGroup {
        $id = self::$db->scalar("
            SELECT ID
            FROM torrents_group
            WHERE CategoryID = ?
                AND Name = ?
                AND Year = ?
                AND PublisherID = ?
        ", CATEGORY_COMIC, $title, $year, $publisherId);
        return $id ? new TGroup((int)$id) : null;
    }

    /**
     * Escape the LIKE wildcards of a user-supplied prefix.
     */
    public static function likePrefix(string $prefix): string {
        return addcslashes(trim($prefix), '\\%_');
    }

    /**
     * Move one issue out of its series into a new series. The new series
     * starts with the publisher, creators and tags of the old one, which
     * staff then correct on the group edit page.
     *
     * @throws \InvalidArgumentException when the new series already exists
     */
    public static function splitFromTorrent(
        \Gazelle\Torrent $torrent,
        string           $title,
        int              $year,
        \Gazelle\User    $user,
    ): TGroup {
        $old         = $torrent->group();
        $publisher   = self::publisher($old);
        $publisherId = (int)($old->info()['PublisherID'] ?? 0);
        if ($publisherId && self::findByTitlePublisherYear($title, $year, $publisherId)) {
            throw new \InvalidArgumentException(
                'That series already exists; move the issue to it instead of splitting.'
            );
        }
        $creatorList = self::creatorList($old);
        $tagList     = $old->tagNameList();

        $new = new \Gazelle\Manager\TGroup()->create(
            categoryId:  CATEGORY_COMIC,
            name:        $title,
            description: '',
            year:        $year,
            releaseType: null,
            image:       new \Gazelle\Image(null),
        );
        if ($publisher !== null) {
            self::setPublisher($new, $publisher);
        }
        self::setCreators($new, $creatorList);
        $tagMan = new \Gazelle\Manager\Tag();
        foreach ($tagList as $name) {
            $tagMan->findByName($name)?->addTGroup($new, $user, 10);
        }

        // absorb() moves the torrent and removes the old series if it is now empty
        $new->absorb($torrent, $user);
        $new->logger()->group($new, $user, "split from series {$old->id}");
        (new \Gazelle\Stats\Torrent())->flushComic();
        return $new;
    }
}
