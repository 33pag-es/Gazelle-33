<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicDeletedGroup extends AbstractMigration {
    public function up(): void {
        // DB::softDelete() requires the deleted mirror to have every source column.
        $this->table('deleted_torrents_group')
            ->addColumn('PublisherID', 'integer', ['null' => true, 'after' => 'Year'])
            ->save();
    }

    public function down(): void {
        $this->table('deleted_torrents_group')
            ->removeColumn('PublisherID')
            ->save();
    }
}
