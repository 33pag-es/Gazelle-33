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
}
