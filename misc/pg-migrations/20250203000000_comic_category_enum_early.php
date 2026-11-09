<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * COMIC-ONLY: 20250204000000_attr_tables.php copies the MySQL category rows
 * into PostgreSQL. The MySQL migration has already marked category 7 as
 * "comic", so the PostgreSQL enum must contain that value before that copy.
 */
final class ComicCategoryEnumEarly extends AbstractMigration {
    public function up(): void {
        $this->query("ALTER TYPE category_upload_t ADD VALUE IF NOT EXISTS 'comic'");
    }

    public function down(): void {
        // PostgreSQL has no ALTER TYPE ... DROP VALUE.
    }
}
