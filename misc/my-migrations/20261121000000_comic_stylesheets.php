<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Register the 33pages themes, make the light one the default, and move
 * users off the stylesheets that are no longer offered (STYLESHEET_HIDDEN in
 * lib/config.php). The upstream stylesheet rows stay.
 */
final class ComicStylesheets extends AbstractMigration {
    private const HIDDEN = [
        'ApolloStage',
        'ApolloStage Coffee',
        'ApolloStage Sunset',
        'Orpheus Haze',
        'Orpheus Paper',
        'Proton',
        'Xanax cake',
    ];

    public function up(): void {
        $this->execute("
            INSERT INTO stylesheets (Name, Description, `Default`, theme)
            SELECT n.Name, n.Description, '0', n.theme
            FROM (
                SELECT '33pages' AS Name, 'Newsprint: paper, ink and a red accent' AS Description, 'light' AS theme
                UNION ALL
                SELECT '33pages Dark', 'Ink: near-black with a yellow accent', 'dark'
            ) n
            WHERE NOT EXISTS (SELECT 1 FROM stylesheets s WHERE s.Name = n.Name)
        ");
        $this->execute("UPDATE stylesheets SET `Default` = if(Name = '33pages', '1', '0')");
        $this->execute("
            UPDATE users_main um
            INNER JOIN stylesheets old ON (old.ID = um.stylesheet_id)
            CROSS JOIN stylesheets new
            SET um.stylesheet_id = new.ID
            WHERE new.Name = '33pages'
                AND old.Name IN ('" . implode("', '", self::HIDDEN) . "')
        ");
    }

    public function down(): void {
        $this->execute("UPDATE stylesheets SET `Default` = if(Name = 'ApolloStage', '1', '0')");
        $this->execute("
            UPDATE users_main um
            INNER JOIN stylesheets old ON (old.ID = um.stylesheet_id)
            CROSS JOIN stylesheets new
            SET um.stylesheet_id = new.ID
            WHERE new.Name = 'ApolloStage'
                AND old.Name IN ('33pages', '33pages Dark')
        ");
        $this->execute("DELETE FROM stylesheets WHERE Name IN ('33pages', '33pages Dark')");
    }
}
