<?php

declare(strict_types=1);

namespace Gazelle\Enum;

/**
 * COMIC-ONLY: whether a comic file is a digital release or a scan of a printed copy.
 *
 * The cases are the values of the MySQL column torrents.comic_source, in the
 * same order. Append new cases; the search index stores index().
 */
enum ComicSource: string {
    case digital = 'digital';
    case scan    = 'scan';

    public function label(): string {
        return match ($this) {
            self::digital => 'Digital',
            self::scan    => 'Scan',
        };
    }

    /**
     * Position of the value in the MySQL ENUM (column + 0); 0 means not set.
     */
    public function index(): int {
        return (int)array_search($this, self::cases(), true) + 1;
    }
}
