<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use Gazelle\Torrent as BaseTorrent;

final class Torrent extends \Gazelle\Base {
    public static function setIssue(
        BaseTorrent $torrent,
        ?int        $start,
        ?int        $end,
        ?string     $label,
        ?string     $scanner,
        bool        $pack,
    ): void {
        self::$db->prepared_query("
            UPDATE torrents SET
                IssueStart = ?,
                IssueEnd   = ?,
                IssueLabel = ?,
                Scanner    = ?,
                Pack       = ?
            WHERE ID = ?
        ", $start, $end, $label, $scanner, (int)$pack, $torrent->id);
        $torrent->flush();
    }

    public static function issueLabel(BaseTorrent $torrent): string {
        $label = $torrent->issueLabel();
        if ($label !== null && $label !== '') {
            return $label;
        }
        $start = $torrent->issueStart();
        if ($start === null) {
            return '';
        }
        $end = $torrent->issueEnd();
        return $end !== null && $end > $start ? "{$start}-{$end}" : (string)$start;
    }
}
