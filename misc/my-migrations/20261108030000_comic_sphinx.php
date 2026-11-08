<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicSphinx extends AbstractMigration {
    public function up(): void {
        $this->table('sphinx_tg')
            ->addColumn('publisher', 'string', ['limit' => 255, 'null' => true, 'after' => 'tags'])
            ->addColumn('creators',  'string', ['limit' => 2048, 'null' => true, 'after' => 'publisher'])
            ->save();

        foreach (['sphinx_t', 'sphinx_delta'] as $table) {
            $this->table($table)
                ->addColumn('issue_start', 'integer', ['null' => true])
                ->addColumn('issue_end',   'integer', ['null' => true])
                ->addColumn('scanner',     'string',  ['limit' => 100, 'null' => true])
                ->addColumn('pack',        'boolean', ['default' => false])
                ->addColumn('checked',     'boolean', ['default' => false])
                ->save();
        }

        $this->table('sphinx_a')
            ->addColumn('kind', 'string', ['limit' => 16, 'default' => 'artist'])
            ->save();
    }

    public function down(): void {
        $this->table('sphinx_a')->removeColumn('kind')->save();
        foreach (['sphinx_t', 'sphinx_delta'] as $table) {
            $this->table($table)
                ->removeColumn('checked')
                ->removeColumn('pack')
                ->removeColumn('scanner')
                ->removeColumn('issue_end')
                ->removeColumn('issue_start')
                ->save();
        }
        $this->table('sphinx_tg')
            ->removeColumn('creators')
            ->removeColumn('publisher')
            ->save();
    }
}
