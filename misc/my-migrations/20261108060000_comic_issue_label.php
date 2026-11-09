<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicIssueLabel extends AbstractMigration {
    public function up(): void {
        foreach (['sphinx_t', 'sphinx_delta'] as $table) {
            $this->table($table)
                ->addColumn('issue_label', 'string', ['limit' => 32, 'null' => true, 'after' => 'issue_end'])
                ->save();
        }
    }

    public function down(): void {
        foreach (['sphinx_t', 'sphinx_delta'] as $table) {
            $this->table($table)
                ->removeColumn('issue_label')
                ->save();
        }
    }
}
