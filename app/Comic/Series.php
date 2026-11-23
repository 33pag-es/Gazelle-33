<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use Gazelle\Enum\CreatorRole;
use Gazelle\Enum\SeriesStatus;
use Gazelle\Enum\SeriesType;
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

    public static function type(TGroup $tgroup): ?SeriesType {
        return SeriesType::tryFrom((string)($tgroup->info()['series_type'] ?? ''));
    }

    public static function status(TGroup $tgroup): ?SeriesStatus {
        return SeriesStatus::tryFrom((string)($tgroup->info()['series_status'] ?? ''));
    }

    /**
     * ISO 639-1 code, a key of COMIC_LANGUAGE.
     */
    public static function language(TGroup $tgroup): string {
        return (string)($tgroup->info()['language'] ?? COMIC_LANGUAGE_DEFAULT);
    }

    public static function languageName(TGroup $tgroup): string {
        $code = self::language($tgroup);
        return COMIC_LANGUAGE[$code] ?? $code;
    }

    public static function imprint(TGroup $tgroup): ?string {
        $imprintId = $tgroup->info()['imprint_id'] ?? null;
        if (!$imprintId) {
            return null;
        }
        $name = self::$db->scalar("
            SELECT i.name FROM imprint i WHERE i.imprint_id = ?
        ", $imprintId);
        return $name === null || $name === false ? null : (string)$name;
    }

    /**
     * Set the type, status, language and imprint of a series. The imprint
     * belongs to the publisher of the series, so set the publisher first.
     */
    public static function setClassification(
        TGroup        $tgroup,
        ?SeriesType   $type,
        ?SeriesStatus $status,
        string        $language,
        ?string       $imprint,
    ): void {
        $imprintId = null;
        if ($imprint !== null && trim($imprint) !== '') {
            $publisherId = (int)self::$db->scalar("
                SELECT tg.PublisherID FROM torrents_group tg WHERE tg.ID = ?
            ", $tgroup->id);
            if ($publisherId) {
                $imprintId = Imprint::softCreate($publisherId, $imprint)->id;
            }
        }
        self::$db->prepared_query("
            UPDATE torrents_group SET
                series_type   = ?,
                series_status = ?,
                language      = ?,
                imprint_id    = ?
            WHERE ID = ?
        ", $type?->value, $status?->value, $language, $imprintId, $tgroup->id);
        $tgroup->flush();
    }

    /**
     * Replace the creators with a list of names that have no stated role.
     */
    public static function setCreators(TGroup $tgroup, array $names): int {
        return self::setCreatorRoles($tgroup, [CreatorRole::creator->value => $names]);
    }

    /**
     * Replace the creators of a series. A creator may hold several roles.
     *
     * @param array<string, array<string>> $roleList names by CreatorRole value
     */
    public static function setCreatorRoles(TGroup $tgroup, array $roleList): int {
        $seen = [];
        $args = [];
        foreach ($roleList as $role => $nameList) {
            $role = CreatorRole::from($role);
            foreach ($nameList as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $creator = Creator::softCreate($name);
                $key     = "{$creator->id}.{$role->value}";
                if (isset($seen[$key])) {
                    continue;
                }
                array_push($args, $tgroup->id, $creator->id, $role->value, count($seen));
                $seen[$key] = true;
            }
        }

        self::$db->prepared_query("
            DELETE FROM series_creator WHERE GroupID = ?
        ", $tgroup->id);
        if ($seen) {
            self::$db->prepared_query("
                INSERT INTO series_creator
                       (GroupID, CreatorID, role, Sequence)
                VALUES " . placeholders($seen, '(?, ?, ?, ?)'),
                ...$args,
            );
        }
        $tgroup->flush();
        return count($seen);
    }

    /**
     * The creators of a series, each named once whatever their roles.
     *
     * @return string[]
     */
    public static function creatorList(TGroup $tgroup): array {
        self::$db->prepared_query("
            SELECT c.Name
            FROM creator c
            INNER JOIN series_creator sc ON (sc.CreatorID = c.CreatorID)
            WHERE sc.GroupID = ?
            GROUP BY c.CreatorID, c.Name
            ORDER BY min(sc.Sequence), c.Name
        ", $tgroup->id);
        return self::$db->collect(0);
    }

    /**
     * The creators of a series by role, in the order of CreatorRole.
     *
     * @return array<string, list<string>> names by CreatorRole value
     */
    public static function creatorRoleList(TGroup $tgroup): array {
        self::$db->prepared_query("
            SELECT sc.role, c.Name
            FROM series_creator sc
            INNER JOIN creator c ON (c.CreatorID = sc.CreatorID)
            WHERE sc.GroupID = ?
            ORDER BY sc.role, sc.Sequence, c.Name
        ", $tgroup->id);
        $list = [];
        foreach (self::$db->to_array(false, MYSQLI_NUM) as [$role, $name]) {
            $list[(string)$role][] = (string)$name;
        }
        return $list;
    }

    /**
     * A series is identified by title, start year, publisher and type: the
     * monthly issues and the trade paperbacks of one title are two series.
     * A series whose type has not been set yet matches any type.
     */
    public static function findByTitlePublisherYear(
        string      $title,
        int         $year,
        int         $publisherId,
        ?SeriesType $type = null,
    ): ?TGroup {
        $id = self::$db->scalar("
            SELECT tg.ID
            FROM torrents_group tg
            WHERE tg.CategoryID = ?
                AND tg.Name = ?
                AND tg.Year = ?
                AND tg.PublisherID = ?
                AND (? IS NULL OR tg.series_type IS NULL OR tg.series_type = ?)
            ORDER BY (tg.series_type IS NULL), tg.ID
            LIMIT 1
        ", CATEGORY_COMIC, $title, $year, $publisherId, $type?->value, $type?->value);
        return $id ? new TGroup((int)$id) : null;
    }

    /**
     * torrents_group.TagList and the search staging tables store the tags of
     * a group as one space-separated VARCHAR(500).
     */
    final public const TAGLIST_MAX = 500;

    /**
     * @param string[] $nameList
     */
    public static function tagListFits(array $nameList): bool {
        return mb_strlen(implode(' ', $nameList)) <= self::TAGLIST_MAX;
    }

    /**
     * Can this tag be added without overflowing the tag list of the group?
     * A tag the group already has takes no extra room.
     */
    public static function hasTagRoom(TGroup $tgroup, string $name): bool {
        self::$db->prepared_query("
            SELECT t.Name
            FROM torrents_tags tt
            INNER JOIN tags t ON (t.ID = tt.TagID)
            WHERE tt.GroupID = ?
        ", $tgroup->id);
        $nameList = self::$db->collect(0);
        return in_array($name, $nameList, true) || self::tagListFits([...$nameList, $name]);
    }

    /**
     * Escape the LIKE wildcards of a user-supplied prefix.
     */
    public static function likePrefix(string $prefix): string {
        return addcslashes(trim($prefix), '\\%_');
    }

    /**
     * Move one issue out of its series into a new series. The new series
     * starts with the publisher, imprint, type, language, creators and tags
     * of the old one, which staff then correct on the group edit page.
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
        $roleList = self::creatorRoleList($old);
        $tagList  = $old->tagNameList();
        $type     = self::type($old);
        $language = self::language($old);
        $imprint  = self::imprint($old);

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
        self::setClassification($new, $type, null, $language, $imprint);
        self::setCreatorRoles($new, $roleList);
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
