<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ComicSchema extends AbstractMigration {
    public function up(): void {
        $this->table('publisher', ['id' => false, 'primary_key' => 'PublisherID'])
            ->addColumn('PublisherID', 'integer', ['identity' => true])
            ->addColumn('Name',        'string',  ['limit' => 255])
            ->addColumn('Created',     'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['Name'], ['unique' => true, 'name' => 'publisher_name_uidx'])
            ->create();

        $this->table('creator', ['id' => false, 'primary_key' => 'CreatorID'])
            ->addColumn('CreatorID', 'integer', ['identity' => true])
            ->addColumn('Name',      'string',  ['limit' => 255])
            ->addColumn('Created',   'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['Name'], ['unique' => true, 'name' => 'creator_name_uidx'])
            ->create();

        $this->table('series_creator', ['id' => false, 'primary_key' => ['GroupID', 'CreatorID']])
            ->addColumn('GroupID',   'integer')
            ->addColumn('CreatorID', 'integer')
            ->addColumn('Sequence',  'integer', ['default' => 0])
            ->addIndex(['CreatorID'], ['name' => 'series_creator_creator_idx'])
            ->addForeignKey('GroupID', 'torrents_group', 'ID', [
                'delete'     => 'CASCADE',
                'update'     => 'NO_ACTION',
                'constraint' => 'series_creator_group_fk',
            ])
            ->addForeignKey('CreatorID', 'creator', 'CreatorID', [
                'delete'     => 'CASCADE',
                'update'     => 'NO_ACTION',
                'constraint' => 'series_creator_creator_fk',
            ])
            ->create();

        $this->table('torrents_group')
            ->addColumn('PublisherID', 'integer', ['null' => true, 'after' => 'Year'])
            ->addIndex(['PublisherID'], ['name' => 'torrents_group_publisher_idx'])
            ->addForeignKey('PublisherID', 'publisher', 'PublisherID', [
                'constraint' => 'torrents_group_publisher_fk',
            ])
            ->save();

        $this->table('torrents')
            ->addColumn('IssueStart', 'integer',  ['null' => true, 'after' => 'Format'])
            ->addColumn('IssueEnd',   'integer',  ['null' => true, 'after' => 'IssueStart'])
            ->addColumn('IssueLabel', 'string',   ['limit' => 32, 'null' => true, 'after' => 'IssueEnd'])
            ->addColumn('Scanner',    'string',   ['limit' => 100, 'null' => true, 'after' => 'IssueLabel'])
            ->addColumn('Pack',       'boolean',  ['default' => false, 'after' => 'Scanner'])
            ->addColumn('Checked',    'boolean',  ['default' => false, 'after' => 'Pack'])
            ->addColumn('CheckedBy',  'integer',  ['null' => true, 'after' => 'Checked'])
            ->addColumn('CheckedAt',  'datetime', ['null' => true, 'after' => 'CheckedBy'])
            ->addIndex(['IssueStart', 'IssueEnd'], ['name' => 'torrents_issue_idx'])
            ->addIndex(['Scanner'], ['name' => 'torrents_scanner_idx'])
            ->addIndex(['Checked'], ['name' => 'torrents_checked_idx'])
            ->save();

        $this->table('deleted_torrents')
            ->addColumn('IssueStart', 'integer',  ['null' => true, 'after' => 'Format'])
            ->addColumn('IssueEnd',   'integer',  ['null' => true, 'after' => 'IssueStart'])
            ->addColumn('IssueLabel', 'string',   ['limit' => 32, 'null' => true, 'after' => 'IssueEnd'])
            ->addColumn('Scanner',    'string',   ['limit' => 100, 'null' => true, 'after' => 'IssueLabel'])
            ->addColumn('Pack',       'boolean',  ['default' => false, 'after' => 'Scanner'])
            ->addColumn('Checked',    'boolean',  ['default' => false, 'after' => 'Pack'])
            ->addColumn('CheckedBy',  'integer',  ['null' => true, 'after' => 'Checked'])
            ->addColumn('CheckedAt',  'datetime', ['null' => true, 'after' => 'CheckedBy'])
            ->save();
    }

    public function down(): void {
        $this->table('deleted_torrents')
            ->removeColumn('CheckedAt')
            ->removeColumn('CheckedBy')
            ->removeColumn('Checked')
            ->removeColumn('Pack')
            ->removeColumn('Scanner')
            ->removeColumn('IssueLabel')
            ->removeColumn('IssueEnd')
            ->removeColumn('IssueStart')
            ->save();

        $this->table('torrents')
            ->removeIndexByName('torrents_issue_idx')
            ->removeIndexByName('torrents_scanner_idx')
            ->removeIndexByName('torrents_checked_idx')
            ->save();
        $this->table('torrents')
            ->removeColumn('CheckedAt')
            ->removeColumn('CheckedBy')
            ->removeColumn('Checked')
            ->removeColumn('Pack')
            ->removeColumn('Scanner')
            ->removeColumn('IssueLabel')
            ->removeColumn('IssueEnd')
            ->removeColumn('IssueStart')
            ->save();

        $this->table('torrents_group')
            ->dropForeignKey('PublisherID')
            ->save();
        $this->table('torrents_group')
            ->removeIndexByName('torrents_group_publisher_idx')
            ->removeColumn('PublisherID')
            ->save();

        $this->table('series_creator')->drop()->save();
        $this->table('creator')->drop()->save();
        $this->table('publisher')->drop()->save();
    }
}
