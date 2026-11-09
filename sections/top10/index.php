<?php

// COMIC-ONLY: music-only Top 10 views are hidden.

/**
 * @phpstan-var \Gazelle\User $Viewer
 * @phpstan-var \Twig\Environment $Twig
 */

declare(strict_types=1);

namespace Gazelle;

if (!$Viewer->permitted('site_top10')) {
    Error403::error('Your class does not have permission to view the top 10 lists.');
}

if (preg_match('/^comics$/', SITE_FLAVOR) && ($_GET['type'] ?? '') === 'lastfm') {
    Error404::error();
}

require __DIR__ . match ($_GET['type'] ?? '') {
    'donors'  => '/donors.php',
    'history' => '/history.php',
    'lastfm'  => '/lastfm.php',
    'tags'    => '/tags.php',
    'users'   => '/users.php',
    'votes'   => '/votes.php',
    default   => '/torrents.php',
};
