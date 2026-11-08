<?php

/**
 * @phpstan-var \Gazelle\User $Viewer
 */

declare(strict_types=1);

namespace Gazelle;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Error400::error('Checked state can only be changed with POST.');
}
authorize();
if (!$Viewer->permitted('torrents_edit')) {
    Error403::error('You are not allowed to change the checked state.');
}

$torrent = new Manager\Torrent()->findById((int)($_POST['torrentid'] ?? 0));
if (is_null($torrent)) {
    Error404::error();
}
if ($torrent->group()->categoryName() !== 'Comics') {
    Error400::error('Only comic torrents can be checked.');
}

$checked = filter_var($_POST['checked'] ?? null, FILTER_VALIDATE_INT);
if ($checked === false || !in_array($checked, [0, 1], true)) {
    Error400::error('Invalid checked state.');
}

DB::DB()->prepared_query("
    UPDATE torrents SET
        Checked   = ?,
        CheckedBy = ?,
        CheckedAt = now()
    WHERE ID = ?
", $checked, $Viewer->id, $torrent->id);
$torrent->logger()->torrent($torrent, $Viewer, $checked ? 'marked checked' : 'marked unchecked');
$torrent->flush();
$torrent->group()->refresh();

if (defined('AJAX')) {
    json_print('success', ['checked' => $checked]);
    return;
}
header("Location: torrents.php?id={$torrent->groupId()}&checked=$checked");
