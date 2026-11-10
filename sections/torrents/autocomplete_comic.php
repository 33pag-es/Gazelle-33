<?php

declare(strict_types=1);

namespace Gazelle;

header('Content-Type: application/json');

$query = trim((string)($_GET['query'] ?? ''));
if (!is_string($_GET['query'] ?? null) || mb_strlen($query) < 2) {
    echo json_encode(['query' => $query, 'suggestions' => []]);
    return;
}

echo json_encode([
    'query'       => $query,
    'suggestions' => match ($_GET['type'] ?? '') {
        'publisher' => Comic\Publisher::autocomplete($query),
        'creator'   => Comic\Creator::autocomplete($query),
        default     => [],
    },
]);
