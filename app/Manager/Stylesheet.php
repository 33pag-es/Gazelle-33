<?php

namespace Gazelle\Manager;

use Gazelle\Enum\UserStatus;
use Gazelle\Util\SortableTableHeader;

class Stylesheet extends \Gazelle\Base {
    final public const CACHE_KEY = 'csslist4'; // COMIC-ONLY

    protected array $info;

    public function heading(): SortableTableHeader {
        return new SortableTableHeader(
            'id',
            [
                'id'      => ['dbColumn' => 's.ID',          'defaultSort' => 'asc'],
                'name'    => ['dbColumn' => 's.Name',        'defaultSort' => 'asc',  'text' => 'Name'],
                'enabled' => ['dbColumn' => 'total_enabled', 'defaultSort' => 'desc', 'text' => 'Enabled Users'],
                'total'   => ['dbColumn' => 'total',         'defaultSort' => 'desc', 'text' => 'Total Users'],
            ]
        );
    }

    public function list(): array {
        if (!isset($this->info)) {
            $info = self::$cache->get_value(self::CACHE_KEY);
            if ($info === false) {
                // COMIC-ONLY: only the stylesheets in STYLESHEET_OFFERED.
                self::$db->prepared_query("
                    SELECT ID AS id,
                        lower(replace(Name, ' ', '_')) AS css_name,
                        Name AS name,
                        theme
                    FROM stylesheets
                    WHERE Name IN (" . placeholders(STYLESHEET_OFFERED) . ")
                    ORDER BY NAME ASC
                ", ...STYLESHEET_OFFERED);
                $info = self::$db->to_array(false, MYSQLI_ASSOC);
                self::$cache->cache_value(self::CACHE_KEY, $info, 0);
            }
            $this->info = $info;
        }
        return $this->info;
    }

    public function usageList(): array {
        // COMIC-ONLY: the same stylesheets as list().
        self::$db->prepared_query("
            SELECT s.ID                       AS id,
                s.Name                        AS name,
                s.Description                 AS description,
                s.Default                     AS initial,
                s.theme                       AS theme,
                count(um.ID)                  AS total,
                sum(if(um.Enabled = ?, 1, 0)) AS total_enabled
            FROM stylesheets s
            LEFT JOIN users_main um ON (um.stylesheet_id = s.ID)
            WHERE s.Name IN (" . placeholders(STYLESHEET_OFFERED) . ")
            GROUP BY s.ID, s.Name, s.Description, s.theme
            ORDER BY {$this->heading()->orderBy()} {$this->heading()->dir()}
            ", UserStatus::enabled->value, ...STYLESHEET_OFFERED
        );
        return self::$db->to_array(false, MYSQLI_ASSOC);
    }
}
