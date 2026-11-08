<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use InvalidArgumentException;

final class Publisher extends \Gazelle\Base {
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}

    public static function softCreate(string $name): self {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A publisher name between 1 and 255 characters is required.');
        }
        self::$db->prepared_query("
            INSERT INTO publisher (Name) VALUES (?)
            ON DUPLICATE KEY UPDATE PublisherID = LAST_INSERT_ID(PublisherID)
        ", $name);
        return new self((int)self::$db->inserted_id(), $name);
    }
}
