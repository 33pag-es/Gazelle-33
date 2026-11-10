<?php

/**
 * @phpstan-var \Gazelle\User $Viewer
 * @phpstan-var \Twig\Environment $Twig
 */

declare(strict_types=1);

namespace Gazelle;

/***************************************************************
* This page handles the backend of the "new group" function
* which splits a torrent off into a new group.
****************************************************************/

authorize();

if (!$Viewer->permitted('torrents_edit')) {
    Error403::error();
}

// COMIC-ONLY: an issue splits into a new series, which has no artist.
$comicTorrent = new Manager\Torrent()->findById((int)($_POST['torrentid'] ?? 0));
if ($comicTorrent?->group()->categoryId() === CATEGORY_COMIC) {
    try {
        $series = Comic\Upload::titleAndYear($_POST);
        if (empty($_POST['confirm'])) {
            echo $Twig->render('comic/confirm-split.twig', [
                'title'   => $series['title'],
                'year'    => $series['year'],
                'torrent' => $comicTorrent,
                'viewer'  => $Viewer,
            ]);
            return;
        }
        $new = Comic\Series::splitFromTorrent($comicTorrent, $series['title'], $series['year'], $Viewer);
    } catch (\InvalidArgumentException $e) {
        Error400::error($e->getMessage());
    }
    header('Location: ' . $new->location());
    return;
}

$artistName = trim($_POST['artist']);
$title      = trim($_POST['title']);
$year       = (int)$_POST['year'];
if (!$year || empty($title) || empty($artistName)) {
    Error400::error('Missing parameters to set up new group');
}

$torrent = new Manager\Torrent()->findById((int)($_POST['torrentid'] ?? 0));
if (is_null($torrent)) {
    Error400::error('Torrent does not exist!');
}

// double check
if (empty($_POST['confirm'])) {
    echo $Twig->render('torrent/confirm-split.twig', [
        'artist'  => $_POST['artist'],
        'title'   => $_POST['title'],
        'year'    => $_POST['year'],
        'torrent' => $torrent,
        'viewer'  => $Viewer,
    ]);
    return;
}

$new = new Manager\TGroup()->createFromTorrent(
    $torrent,
    $artistName,
    $title,
    $year,
    new Manager\Artist(),
    new Manager\Bookmark(),
    new Manager\Comment(),
    new Manager\Vote(),
    $Viewer,
);

header('Location: ' . $new->location());
