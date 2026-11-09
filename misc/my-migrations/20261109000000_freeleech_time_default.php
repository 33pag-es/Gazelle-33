<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * COMIC-ONLY: Ocelot records freeleech-token downloads with
 * INSERT INTO users_freeleeches (UserID, TorrentID, Downloaded) ...
 * ON DUPLICATE KEY UPDATE. Under STRICT_TRANS_TABLES MySQL rejects that
 * statement (error 1364) because Time has no default, even when the row
 * already exists. Gazelle always writes Time explicitly, so a default does
 * not change its behaviour.
 */
final class FreeleechTimeDefault extends AbstractMigration {
    public function up(): void {
        $this->query("
            ALTER TABLE users_freeleeches
                MODIFY Time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
        ");
    }

    public function down(): void {
        $this->query("
            ALTER TABLE users_freeleeches
                MODIFY Time datetime NOT NULL
        ");
    }
}
