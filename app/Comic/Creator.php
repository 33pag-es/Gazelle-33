<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use InvalidArgumentException;

final class Creator extends \Gazelle\Base {
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}

    public static function softCreate(string $name): self {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A creator name between 1 and 255 characters is required.');
        }
        self::$db->prepared_query("
            INSERT INTO creator (Name) VALUES (?)
            ON DUPLICATE KEY UPDATE CreatorID = LAST_INSERT_ID(CreatorID)
        ", $name);
        return new self((int)self::$db->inserted_id(), $name);
    }

    /**
     * Suggest existing creators for the upload and edit forms.
     *
     * @return list<array{value: string, data: int}>
     */
    public static function autocomplete(string $prefix): array {
        self::$db->prepared_query("
            SELECT Name AS value, CreatorID AS data
            FROM creator
            WHERE Name LIKE concat(?, '%')
            ORDER BY Name
            LIMIT 10
        ", Series::likePrefix($prefix));
        return array_map(
            fn (array $row) => ['value' => (string)$row['value'], 'data' => (int)$row['data']],
            array_values(self::$db->to_array(false, MYSQLI_ASSOC)),
        );
    }
}
