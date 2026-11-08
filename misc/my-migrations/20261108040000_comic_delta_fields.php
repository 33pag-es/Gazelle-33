<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicDeltaFields extends AbstractMigration {
    public function up(): void {
        $this->table('sphinx_delta')
            ->addColumn('Publisher', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('Creators',  'string', ['limit' => 2048, 'null' => true])
            ->save();
    }

    public function down(): void {
        $this->table('sphinx_delta')
            ->removeColumn('Creators')
            ->removeColumn('Publisher')
            ->save();
    }
}
