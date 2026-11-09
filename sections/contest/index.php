<?php

/**
 * @phpstan-var \Gazelle\User $Viewer
 * @phpstan-var \Twig\Environment $Twig
 */

declare(strict_types=1);

namespace Gazelle;

// COMIC-ONLY: contests are music/request contests in this profile.
if (preg_match('/^comics$/', SITE_FLAVOR)) {
    Error404::error();
}

$contestMan = new Manager\Contest();

switch ($_GET['action'] ?? '') {
    case 'leaderboard':
        require __DIR__ . '/leaderboard.php';
        break;
    case 'admin':
    case 'create':
        require __DIR__ . '/admin.php';
        break;
    default:
        echo $Twig->render('contest/intro.twig', [
            'contest' => $contestMan->currentContest(),
            'viewer'  => $Viewer,
        ]);
}
