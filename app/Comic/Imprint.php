<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use InvalidArgumentException;

/**
 * A publishing line of one publisher, such as Vertigo at DC.
 */
final class Imprint extends \Gazelle\Base {
    public function __construct(
        public readonly int $id,
        public readonly int $publisherId,
        public readonly string $name,
    ) {}

    public static function softCreate(int $publisherId, string $name): self {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('An imprint name between 1 and 255 characters is required.');
        }
        self::$db->prepared_query("
            INSERT INTO imprint (publisher_id, name) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE imprint_id = LAST_INSERT_ID(imprint_id)
        ", $publisherId, $name);
        return new self((int)self::$db->inserted_id(), $publisherId, $name);
    }

    /**
     * Suggest existing imprints for the upload and edit forms.
     *
     * @return list<array{value: string, data: int}>
     */
    public static function autocomplete(string $prefix): array {
        self::$db->prepared_query("
            SELECT i.name AS value, min(i.imprint_id) AS data
            FROM imprint i
            WHERE i.name LIKE concat(?, '%')
            GROUP BY i.name
            ORDER BY i.name
            LIMIT 10
        ", Series::likePrefix($prefix));
        return array_map(
            fn (array $row) => ['value' => (string)$row['value'], 'data' => (int)$row['data']],
            array_values(self::$db->to_array(false, MYSQLI_ASSOC)),
        );
    }
}
