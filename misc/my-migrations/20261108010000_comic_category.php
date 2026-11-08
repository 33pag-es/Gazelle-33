<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicCategory extends AbstractMigration {
    public function up(): void {
        $this->query("
            ALTER TABLE category
                MODIFY upload ENUM('audiobook','simple','music','comic') NOT NULL DEFAULT 'simple'
        ");
        $this->query("
            UPDATE category
            SET upload = 'comic', is_grouped = 1
            WHERE category_id = 7
        ");
    }

    public function down(): void {
        $this->query("
            UPDATE category
            SET upload = 'simple', is_grouped = 0
            WHERE category_id = 7
        ");
        $this->query("
            ALTER TABLE category
                MODIFY upload ENUM('audiobook','simple','music') NOT NULL DEFAULT 'simple'
        ");
    }
}
