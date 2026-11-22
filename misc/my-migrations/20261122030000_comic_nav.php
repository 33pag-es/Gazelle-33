<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The optional "random" link in the user menu picks a random torrent group;
 * on this site that is a series, not an album.
 */
final class ComicNav extends AbstractMigration {
    public function up(): void {
        $this->execute("UPDATE nav_items SET title = 'Random Series' WHERE tag = 'random' AND title = 'Random Album'");
    }

    public function down(): void {
        $this->execute("UPDATE nav_items SET title = 'Random Album' WHERE tag = 'random' AND title = 'Random Series'");
    }
}
