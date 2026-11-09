<?php

declare(strict_types=1);

namespace Gazelle;

// COMIC-ONLY: the artist subsystem is not part of the product.
if (preg_match('/^comics$/', SITE_FLAVOR) && ($_REQUEST['action'] ?? '') === 'artist') {
    Error404::error();
}

$object = match ($_REQUEST['action'] ?? '') {
    'artist'  => new Manager\Artist()->findRandom(),
    'collage' => new Manager\Collage()->findRandom(),
    default   => new Manager\TGroup()->findRandom(),
};
if (is_null($object)) {
    Error404::error(); /* only likely to happen on a brand new installation */
}

header("Location: " . $object->location());
