<?php

declare(strict_types=1);

namespace Gazelle;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ComicSearchTest extends TestCase {
    /**
     * @return array<string, mixed>
     */
    private function staticProperty(string $name): array {
        $reflection = new ReflectionClass(Search\Torrent::class);
        $property   = $reflection->getProperty($name);
        return $property->getValue();
    }

    public function testComicFieldsAreRegistered(): void {
        $fields = $this->staticProperty('Fields');
        foreach (['series', 'creatorname', 'publishername', 'issue', 'scanner'] as $field) {
            $this->assertArrayHasKey($field, $fields, "search-field-$field");
        }

        $attributes = $this->staticProperty('Attributes');
        foreach (['checked', 'pack', 'issue_start', 'issue_end'] as $attribute) {
            $this->assertArrayHasKey($attribute, $attributes, "search-attribute-$attribute");
        }

        $forms = $this->staticProperty('FormsToFields');
        $this->assertSame('groupname', $forms['series'], 'search-series-form');
        $this->assertStringContainsString('publishername', $forms['searchstr'], 'searchstr-publisher');
        $this->assertStringContainsString('creatorname', $forms['searchstr'], 'searchstr-creator');
    }
}
