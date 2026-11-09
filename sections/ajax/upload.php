<?php

/** @phpstan-var \Gazelle\User $Viewer */

declare(strict_types=1);

namespace Gazelle;

// echo out the slice of the form needed for the selected upload type ($_GET['section']).

$uploadForm = new Upload($Viewer);
$emitJS = isset($_GET['js']);
// COMIC-ONLY: default to the comic category.
$categoryIndex = (int)($_GET['categoryid'] ?? CATEGORY_COMIC - 1);
$comicCategory = CATEGORY[CATEGORY_COMIC - 1];
if (!in_array(CATEGORY[$categoryIndex] ?? '', CATEGORY_ENABLED, true)) {
    $categoryIndex = CATEGORY_COMIC - 1;
}

switch (CATEGORY[$categoryIndex] ?? '') {
    case 'Applications':
        echo $emitJS ? $uploadForm->descriptionJS() : $uploadForm->application();
        break;

    case 'Audiobooks':
        echo $emitJS ? $uploadForm->albumReleaseJS() : $uploadForm->audiobook();
        break;

    case 'Comedy':
        echo $emitJS ? $uploadForm->albumReleaseJS() : $uploadForm->comedy();
        break;

    case $comicCategory:
        echo $emitJS ? $uploadForm->descriptionJS() : $uploadForm->comic();
        break;

    case 'E-Books':
        echo $emitJS ? $uploadForm->descriptionJS() : $uploadForm->ebook();
        break;

    case 'E-Learning Videos':
        echo $emitJS ? $uploadForm->descriptionJS() : $uploadForm->elearning();
        break;

    case 'Music':
        echo $emitJS
            ? $uploadForm->albumReleaseJS()
            : $uploadForm->music(new Manager\Tag()->genreList(), new Manager\TGroup());
        break;

    default:
        echo 'Invalid action!';
}
