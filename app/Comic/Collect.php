<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use Gazelle\TGroup;
use Gazelle\TorrentAbstract;

/**
 * What a collected edition collects: a trade paperback volume reprints
 * issues 1 to 6 of the monthly series, for instance.
 *
 * @phpstan-type CollectRange array{tgroup_id: int, issue_start: string, issue_end: ?string}
 */
final class Collect extends \Gazelle\Base {
    final public const LINE_MAX = 50;

    /**
     * Replace what a torrent collects.
     *
     * @param list<CollectRange> $rangeList
     */
    public static function set(\Gazelle\Torrent $torrent, array $rangeList): int {
        self::$db->prepared_query("
            DELETE FROM torrent_collect WHERE torrent_id = ?
        ", $torrent->id);
        if (!$rangeList) {
            return 0;
        }
        $args = [];
        foreach ($rangeList as $range) {
            array_push($args, $torrent->id, $range['tgroup_id'], $range['issue_start'], $range['issue_end']);
        }
        self::$db->prepared_query("
            INSERT IGNORE INTO torrent_collect
                   (torrent_id, tgroup_id, issue_start, issue_end)
            VALUES " . placeholders($rangeList, '(?, ?, ?, ?)'),
            ...$args,
        );
        return self::$db->affected_rows();
    }

    /**
     * @return list<CollectRange>
     */
    public static function rangeList(TorrentAbstract $torrent): array {
        self::$db->prepared_query("
            SELECT tc.tgroup_id, tc.issue_start, tc.issue_end
            FROM torrent_collect tc
            WHERE tc.torrent_id = ?
            ORDER BY tc.torrent_collect_id
        ", $torrent->id);
        return array_map(
            fn (array $row) => [
                'tgroup_id'   => (int)$row['tgroup_id'],
                'issue_start' => (string)$row['issue_start'],
                'issue_end'   => $row['issue_end'] === null ? null : (string)$row['issue_end'],
            ],
            array_values(self::$db->to_array(false, MYSQLI_ASSOC)),
        );
    }

    public static function rangeText(string $start, ?string $end): string {
        return $end === null ? "#{$start}" : "#{$start}–{$end}";
    }

    /**
     * The text of the "Collects" field of the edit form, one range per line.
     */
    public static function formText(TorrentAbstract $torrent): string {
        return implode("\n", array_map(
            fn (array $range) => $range['tgroup_id'] . ' '
                . $range['issue_start'] . ($range['issue_end'] === null ? '' : "-{$range['issue_end']}"),
            self::rangeList($torrent),
        ));
    }

    /**
     * What every torrent of a series collects, for the series page.
     *
     * @return array<int, list<array{tgroup: TGroup, range: string}>> by torrent id
     */
    public static function byTorrent(TGroup $tgroup): array {
        self::$db->prepared_query("
            SELECT tc.torrent_id, tc.tgroup_id, tc.issue_start, tc.issue_end
            FROM torrent_collect tc
            INNER JOIN torrents t ON (t.ID = tc.torrent_id)
            WHERE t.GroupID = ?
            ORDER BY tc.torrent_collect_id
        ", $tgroup->id);
        $list = [];
        foreach (self::$db->to_array(false, MYSQLI_ASSOC) as $row) {
            $list[(int)$row['torrent_id']][] = [
                'tgroup' => new TGroup((int)$row['tgroup_id']),
                'range'  => self::rangeText((string)$row['issue_start'], $row['issue_end']),
            ];
        }
        return $list;
    }

    /**
     * The collected editions that reprint issues of this series.
     *
     * @return list<array{tgroup: TGroup, torrent_id: int, title: string, range: string}>
     */
    public static function collectedIn(TGroup $tgroup): array {
        self::$db->prepared_query("
            SELECT t.GroupID, min(t.ID) AS torrent_id, t.issue_number, t.IssueLabel,
                tc.issue_start, tc.issue_end
            FROM torrent_collect tc
            INNER JOIN torrents t ON (t.ID = tc.torrent_id)
            WHERE tc.tgroup_id = ?
            GROUP BY t.GroupID, t.issue_sort, t.issue_number, t.IssueLabel, tc.issue_start, tc.issue_end
            ORDER BY t.GroupID, (t.issue_sort IS NULL), t.issue_sort, t.issue_number
            LIMIT 200
        ", $tgroup->id);
        return array_map(
            fn (array $row) => [
                'tgroup'     => new TGroup((int)$row['GroupID']),
                'torrent_id' => (int)$row['torrent_id'],
                'title'      => implode(' ', array_filter([
                    new TGroup((int)$row['GroupID'])->name(),
                    Series::type(new TGroup((int)$row['GroupID']))?->label(),
                    (string)($row['IssueLabel'] ?: $row['issue_number']),
                ], fn ($part) => $part !== null && $part !== '')),
                'range'      => self::rangeText((string)$row['issue_start'], $row['issue_end']),
            ],
            array_values(self::$db->to_array(false, MYSQLI_ASSOC)),
        );
    }

    /**
     * When two series are merged, what was collected from the old series
     * is collected from the one that remains.
     */
    public static function moveSeries(TGroup $old, TGroup $new): int {
        self::$db->prepared_query("
            UPDATE IGNORE torrent_collect SET
                tgroup_id = ?
            WHERE tgroup_id = ?
        ", $new->id, $old->id);
        return self::$db->affected_rows();
    }
}
