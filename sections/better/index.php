<?php

// COMIC-ONLY: better/trumping is hidden.

declare(strict_types=1);

namespace Gazelle;

if (preg_match('/^comics$/', SITE_FLAVOR)) {
    Error404::error();
}

require __DIR__ . match ($_GET['method'] ?? '') {
    'transcode' => '/transcode.php',
    default     => '/better.php',
};
