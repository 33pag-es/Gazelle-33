<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 33pages is the only stylesheet offered (STYLESHEET_OFFERED in
 * lib/config.php) and is now a dark theme forked from Post Office. Move
 * every user to it and drop the short-lived "33pages Dark" row. A user's
 * external stylesheet URL is kept. The upstream stylesheet rows stay.
 */
final class ComicSingleStylesheet extends AbstractMigration {
    public function up(): void {
        $this->execute("
            UPDATE stylesheets SET
                theme = 'dark',
                Description = '33pages'
            WHERE Name = '33pages'
        ");
        $this->execute("
            UPDATE users_main um
            CROSS JOIN stylesheets s
            SET um.stylesheet_id = s.ID
            WHERE s.Name = '33pages'
                AND um.stylesheet_id != s.ID
        ");
        $this->execute("DELETE FROM stylesheets WHERE Name = '33pages Dark'");
    }

    public function down(): void {
        $this->execute("
            INSERT INTO stylesheets (Name, Description, `Default`, theme)
            SELECT '33pages Dark', 'Ink: near-black with a yellow accent', '0', 'dark'
            WHERE NOT EXISTS (SELECT 1 FROM stylesheets WHERE Name = '33pages Dark')
        ");
        $this->execute("UPDATE stylesheets SET theme = 'light' WHERE Name = '33pages'");
    }
}
