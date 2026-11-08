<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicCategoryRow extends AbstractMigration {
    public function up(): void {
        $this->query("
            UPDATE category
            SET upload = 'comic', is_grouped = true
            WHERE id_category = 7
        ");
    }

    public function down(): void {
        $this->query("
            UPDATE category
            SET upload = 'simple', is_grouped = false
            WHERE id_category = 7
        ");
    }
}
