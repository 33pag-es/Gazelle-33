<?php

declare(strict_types=1);

namespace Gazelle\Enum;

/**
 * COMIC-ONLY: how a comic series is published.
 *
 * The cases are the values of the MySQL column torrents_group.series_type, in the
 * same order. Append new cases; the search index stores index().
 */
enum SeriesType: string {
    case singleIssue     = 'single-issue';
    case limitedSeries   = 'limited-series';
    case oneShot         = 'one-shot';
    case annual          = 'annual';
    case digitalChapters = 'digital-chapters';
    case graphicNovel    = 'graphic-novel';
    case tradePaperback  = 'trade-paperback';
    case hardcover       = 'hardcover';
    case omnibus         = 'omnibus';

    public function label(): string {
        return match ($this) {
            self::singleIssue     => 'Single Issue',
            self::limitedSeries   => 'Limited Series',
            self::oneShot         => 'One-Shot',
            self::annual          => 'Annual',
            self::digitalChapters => 'Digital Chapters',
            self::graphicNovel    => 'Graphic Novel',
            self::tradePaperback  => 'Trade Paperback',
            self::hardcover       => 'Hardcover',
            self::omnibus         => 'Omnibus',
        };
    }

    /**
     * Position of the value in the MySQL ENUM (column + 0); 0 means not set.
     */
    public function index(): int {
        return (int)array_search($this, self::cases(), true) + 1;
    }
}
