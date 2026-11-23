<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * COMIC-ONLY: organise series the way comic databases do: series type and
 * status, imprint, language, creator roles, issue numbers as printed,
 * release date, digital or scan, and what a collected edition collects.
 *
 * The values of the ENUM columns are the cases of Gazelle\Enum\SeriesType,
 * SeriesStatus, CreatorRole and ComicSource, in the same order. The search
 * index stores the position of a value (column + 0), so new values must be
 * appended, never inserted.
 */
final class ComicStandard extends AbstractMigration {
    private const SERIES_COLUMNS = "
        ADD COLUMN series_type ENUM('single-issue', 'limited-series', 'one-shot', 'annual',
            'digital-chapters', 'graphic-novel', 'trade-paperback', 'hardcover', 'omnibus') NULL AFTER PublisherID,
        ADD COLUMN series_status ENUM('ongoing', 'complete', 'cancelled', 'hiatus') NULL AFTER series_type,
        ADD COLUMN imprint_id INT NULL AFTER series_status,
        ADD COLUMN language CHAR(2) NOT NULL DEFAULT 'en' AFTER imprint_id";

    private const ISSUE_COLUMNS = "
        ADD COLUMN issue_number VARCHAR(16) NULL AFTER IssueLabel,
        ADD COLUMN issue_number_end VARCHAR(16) NULL AFTER issue_number,
        ADD COLUMN issue_sort DECIMAL(10,3) NULL AFTER issue_number_end,
        ADD COLUMN release_date DATE NULL AFTER issue_sort,
        ADD COLUMN comic_source ENUM('digital', 'scan') NULL AFTER release_date";

    public function up(): void {
        $this->execute("
            CREATE TABLE imprint (
                imprint_id   INT AUTO_INCREMENT PRIMARY KEY,
                publisher_id INT NOT NULL,
                name         VARCHAR(255) NOT NULL,
                created      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY imprint_publisher_name_uidx (publisher_id, name),
                KEY imprint_name_idx (name),
                CONSTRAINT imprint_publisher_fk FOREIGN KEY (publisher_id)
                    REFERENCES publisher (PublisherID) ON DELETE CASCADE
            )
        ");

        $this->execute("
            ALTER TABLE torrents_group" . self::SERIES_COLUMNS . ",
                ADD KEY torrents_group_imprint_idx (imprint_id),
                ADD CONSTRAINT torrents_group_imprint_fk FOREIGN KEY (imprint_id)
                    REFERENCES imprint (imprint_id) ON DELETE SET NULL
        ");
        // DB::softDelete() requires the deleted mirror to have every source column.
        $this->execute("ALTER TABLE deleted_torrents_group" . self::SERIES_COLUMNS);

        $this->execute("
            ALTER TABLE series_creator
                ADD COLUMN role ENUM('creator', 'writer', 'artist', 'penciller', 'inker',
                    'colorist', 'letterer', 'cover-artist', 'editor') NOT NULL DEFAULT 'creator' AFTER CreatorID,
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (GroupID, CreatorID, role)
        ");

        $this->execute("ALTER TABLE torrents" . self::ISSUE_COLUMNS);
        $this->execute("ALTER TABLE deleted_torrents" . self::ISSUE_COLUMNS);
        foreach (['torrents', 'deleted_torrents'] as $table) {
            $this->execute("
                UPDATE $table SET
                    issue_number     = IssueStart,
                    issue_number_end = IssueEnd,
                    issue_sort       = IssueStart
                WHERE IssueStart IS NOT NULL
            ");
        }

        $this->execute("
            CREATE TABLE torrent_collect (
                torrent_collect_id INT AUTO_INCREMENT PRIMARY KEY,
                torrent_id         INT NOT NULL,
                tgroup_id          INT NOT NULL,
                issue_start        VARCHAR(16) NOT NULL,
                issue_end          VARCHAR(16) NULL,
                created            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY torrent_collect_uidx (torrent_id, tgroup_id, issue_start),
                KEY torrent_collect_tgroup_idx (tgroup_id),
                CONSTRAINT torrent_collect_torrent_fk FOREIGN KEY (torrent_id)
                    REFERENCES torrents (ID) ON DELETE CASCADE,
                CONSTRAINT torrent_collect_tgroup_fk FOREIGN KEY (tgroup_id)
                    REFERENCES torrents_group (ID) ON DELETE CASCADE
            )
        ");

        $this->execute("
            ALTER TABLE sphinx_tg
                ADD COLUMN series_type TINYINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN series_status TINYINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN language CHAR(2) NULL,
                ADD COLUMN imprint VARCHAR(255) NULL
        ");
        $this->execute("
            ALTER TABLE sphinx_t
                ADD COLUMN issue_number VARCHAR(40) NULL,
                ADD COLUMN comic_source TINYINT UNSIGNED NOT NULL DEFAULT 0
        ");
        $this->execute("
            ALTER TABLE sphinx_delta
                ADD COLUMN series_type TINYINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN series_status TINYINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN language CHAR(2) NULL,
                ADD COLUMN imprint VARCHAR(255) NULL,
                ADD COLUMN issue_number VARCHAR(40) NULL,
                ADD COLUMN comic_source TINYINT UNSIGNED NOT NULL DEFAULT 0
        ");
    }

    public function down(): void {
        $this->execute("
            ALTER TABLE sphinx_delta
                DROP COLUMN comic_source, DROP COLUMN issue_number, DROP COLUMN imprint,
                DROP COLUMN language, DROP COLUMN series_status, DROP COLUMN series_type
        ");
        $this->execute("ALTER TABLE sphinx_t DROP COLUMN comic_source, DROP COLUMN issue_number");
        $this->execute("
            ALTER TABLE sphinx_tg
                DROP COLUMN imprint, DROP COLUMN language, DROP COLUMN series_status, DROP COLUMN series_type
        ");
        $this->execute("DROP TABLE torrent_collect");
        foreach (['torrents', 'deleted_torrents'] as $table) {
            $this->execute("
                ALTER TABLE $table
                    DROP COLUMN comic_source, DROP COLUMN release_date, DROP COLUMN issue_sort,
                    DROP COLUMN issue_number_end, DROP COLUMN issue_number
            ");
        }
        // one row per creator again: keep the first role of each
        $this->execute("
            DELETE sc FROM series_creator sc
            INNER JOIN series_creator keep ON (keep.GroupID = sc.GroupID AND keep.CreatorID = sc.CreatorID AND keep.role < sc.role)
        ");
        $this->execute("
            ALTER TABLE series_creator
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (GroupID, CreatorID),
                DROP COLUMN role
        ");
        $this->execute("
            ALTER TABLE torrents_group
                DROP FOREIGN KEY torrents_group_imprint_fk,
                DROP KEY torrents_group_imprint_idx,
                DROP COLUMN language, DROP COLUMN imprint_id, DROP COLUMN series_status, DROP COLUMN series_type
        ");
        $this->execute("
            ALTER TABLE deleted_torrents_group
                DROP COLUMN language, DROP COLUMN imprint_id, DROP COLUMN series_status, DROP COLUMN series_type
        ");
        $this->execute("DROP TABLE imprint");
    }
}
