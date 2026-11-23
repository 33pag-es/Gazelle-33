<?php

declare(strict_types=1);

namespace Gazelle\Enum;

/**
 * COMIC-ONLY: whether a comic series is still being published.
 *
 * The cases are the values of the MySQL column torrents_group.series_status, in the
 * same order. Append new cases; the search index stores index().
 */
enum SeriesStatus: string {
    case ongoing   = 'ongoing';
    case complete  = 'complete';
    case cancelled = 'cancelled';
    case hiatus    = 'hiatus';

    public function label(): string {
        return match ($this) {
            self::ongoing   => 'Ongoing',
            self::complete  => 'Complete',
            self::cancelled => 'Cancelled',
            self::hiatus    => 'Hiatus',
        };
    }

    /**
     * Position of the value in the MySQL ENUM (column + 0); 0 means not set.
     */
    public function index(): int {
        return (int)array_search($this, self::cases(), true) + 1;
    }
}
