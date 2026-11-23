<?php

declare(strict_types=1);

namespace Gazelle\Enum;

/**
 * COMIC-ONLY: what a creator did on a comic series.
 *
 * The cases are the values of the MySQL column series_creator.role, in the
 * same order. Append new cases; the search index stores index().
 */
enum CreatorRole: string {
    case creator     = 'creator';
    case writer      = 'writer';
    case artist      = 'artist';
    case penciller   = 'penciller';
    case inker       = 'inker';
    case colorist    = 'colorist';
    case letterer    = 'letterer';
    case coverArtist = 'cover-artist';
    case editor      = 'editor';

    public function label(): string {
        return match ($this) {
            self::creator     => 'Creator',
            self::writer      => 'Writer',
            self::artist      => 'Artist',
            self::penciller   => 'Penciller',
            self::inker       => 'Inker',
            self::colorist    => 'Colorist',
            self::letterer    => 'Letterer',
            self::coverArtist => 'Cover Artist',
            self::editor      => 'Editor',
        };
    }

    /**
     * Position of the value in the MySQL ENUM (column + 0); 0 means not set.
     */
    public function index(): int {
        return (int)array_search($this, self::cases(), true) + 1;
    }
}
