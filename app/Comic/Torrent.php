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

    public static function issueLabel(\Gazelle\TorrentAbstract $torrent): string {
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

    /**
     * Mark a comic torrent checked or unchecked, audit it, and refresh caches.
     */
    public static function setChecked(BaseTorrent $torrent, \Gazelle\User $viewer, bool $checked): void {
        self::$db->prepared_query("
            UPDATE torrents SET
                Checked   = ?,
                CheckedBy = ?,
                CheckedAt = now()
            WHERE ID = ?
        ", (int)$checked, $viewer->id, $torrent->id);
        $torrent->logger()->torrent(
            $torrent,
            $viewer,
            $checked ? 'marked checked' : 'marked unchecked',
        );
        $torrent->flush();
        $torrent->group()->refresh();
        (new \Gazelle\Stats\Torrent())->flushComic();
    }
}
