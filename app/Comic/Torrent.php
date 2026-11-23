<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use Gazelle\Enum\ComicSource;
use Gazelle\Torrent as BaseTorrent;

final class Torrent extends \Gazelle\Base {
    /**
     * Issue numbers are stored as printed: 1, 0, -1, ½, 1.MU, 23.1.
     */
    final public const ISSUE_NUMBER_REGEXP = '/^-?[0-9½¼¾][0-9A-Za-z½¼¾.\/]*$/u';
    final public const ISSUE_NUMBER_LENGTH = 16;
    final public const ISSUE_SORT_MAX      = 9_999_999;

    /**
     * Where an issue number sorts within its series: the leading number of
     * the text, so 1.MU sorts with 1 and ½ sorts between 0 and 1. Null when
     * the text does not start with a number.
     */
    public static function issueSort(string $number): ?float {
        if (preg_match('/^(-?)(\d+)\/(\d+)$/', $number, $match) && (int)$match[3] > 0) {
            return ($match[1] === '-' ? -1 : 1) * (int)$match[2] / (int)$match[3];
        }
        $number = strtr($number, ['½' => '.5', '¼' => '.25', '¾' => '.75']);
        return preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)/', $number, $match)
            ? (float)$match[0]
            : null;
    }

    /**
     * A torrent is a pack when it runs from one issue number to a later one.
     */
    public static function isPack(?string $number, ?string $numberEnd): bool {
        if ($number === null || $numberEnd === null) {
            return false;
        }
        $start = self::issueSort($number);
        $end   = self::issueSort($numberEnd);
        return $start !== null && $end !== null && $end > $start;
    }

    /**
     * Read a search term as an issue number: the number as stored and the
     * value of the issue_start search attribute. Null when the term is not
     * an issue number.
     *
     * @return ?array{number: string, start: int}
     */
    public static function searchNumber(string $term): ?array {
        try {
            $number = Upload::normalizeIssueNumber($term);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $sort = self::issueSort($number);
        return $sort === null
            ? null
            : ['number' => $number, 'start' => (int)floor($sort)];
    }

    /**
     * Store the issue of a torrent. IssueStart, IssueEnd and Pack are derived
     * from the issue numbers: they are the whole-number part, kept for the
     * search index and the API.
     */
    public static function setIssue(
        BaseTorrent  $torrent,
        ?string      $number,
        ?string      $numberEnd,
        ?string      $label,
        ?string      $scanner,
        ?string      $releaseDate = null,
        ?ComicSource $source = null,
    ): void {
        $sort    = $number === null ? null : self::issueSort($number);
        $sortEnd = $numberEnd === null ? null : self::issueSort($numberEnd);
        self::$db->prepared_query("
            UPDATE torrents SET
                IssueStart       = ?,
                IssueEnd         = ?,
                IssueLabel       = ?,
                issue_number     = ?,
                issue_number_end = ?,
                issue_sort       = ?,
                release_date     = ?,
                comic_source     = ?,
                Scanner          = ?,
                Pack             = ?
            WHERE ID = ?
        ",  $sort === null ? null : (int)floor($sort),
            $sortEnd === null ? null : (int)floor($sortEnd),
            $label, $number, $numberEnd,
            $sort === null ? null : sprintf('%.3F', $sort),
            $releaseDate, $source?->value, $scanner,
            (int)self::isPack($number, $numberEnd), $torrent->id
        );
        $torrent->flush();
    }

    public static function issueLabel(\Gazelle\TorrentAbstract $torrent): string {
        $label = $torrent->issueLabel();
        if ($label !== null && $label !== '') {
            return $label;
        }
        $number = $torrent->issueNumber();
        if ($number === null) {
            return '';
        }
        $end = $torrent->issueNumberEnd();
        return self::isPack($number, $end) ? "{$number}-{$end}" : $number;
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

    /**
     * Withdraw a check that no longer holds because the issue was edited by
     * someone who cannot check torrents. Nobody vouches for the torrent now,
     * so the checker and time are cleared; the audit entry names the editor.
     */
    public static function resetChecked(BaseTorrent $torrent, \Gazelle\User $editor): void {
        self::$db->prepared_query("
            UPDATE torrents SET
                Checked   = 0,
                CheckedBy = NULL,
                CheckedAt = NULL
            WHERE ID = ?
        ", $torrent->id);
        $torrent->logger()->torrent($torrent, $editor, 'marked unchecked: issue details edited by a non-staff member');
        $torrent->flush();
        $torrent->group()->refresh();
        (new \Gazelle\Stats\Torrent())->flushComic();
    }
}
