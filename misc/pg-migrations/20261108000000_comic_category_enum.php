<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicCategoryEnum extends AbstractMigration {
    public function up(): void {
        // PostgreSQL cannot use the new value in the transaction that adds it,
        // so the category row is updated by the following migration.
        $this->query("ALTER TYPE category_upload_t ADD VALUE IF NOT EXISTS 'comic'");
    }

    public function down(): void {
        // PostgreSQL has no ALTER TYPE ... DROP VALUE. Removing the value would
        // require recreating the type and every column that depends on it.
    }
}
