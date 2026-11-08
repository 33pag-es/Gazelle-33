<?php

declare(strict_types=1);

namespace Gazelle;

use PHPUnit\Framework\TestCase;

final class ComicUploadTest extends TestCase {
    /**
     * @return array<string, mixed>
     */
    private function validPost(): array {
        return [
            'title'       => 'Batman',
            'publisher'   => 'DC',
            'creators'    => ['Tom King', 'Mitch Gerads'],
            'year'        => '2024',
            'issue_start' => '1',
            'issue_end'   => '10',
            'issue_label' => '',
            'scanner'     => 'Zone-Empire',
            'format'      => 'cbz',
            'tags'        => 'superhero, batman',
            'desc'        => 'A comic series description.',
            'release_desc' => 'A release description.',
        ];
    }

    public function testProperties(): void {
        $properties = Comic\Upload::properties($this->validPost());

        $this->assertSame('Batman', $properties['title'], 'upload-title');
        $this->assertSame('DC', $properties['publisher'], 'upload-publisher');
        $this->assertSame(['Tom King', 'Mitch Gerads'], $properties['creators'], 'upload-creators');
        $this->assertSame(2024, $properties['year'], 'upload-year');
        $this->assertSame(1, $properties['issue_start'], 'upload-issue-start');
        $this->assertSame(10, $properties['issue_end'], 'upload-issue-end');
        $this->assertNull($properties['issue_label'], 'upload-issue-label');
        $this->assertSame('Zone-Empire', $properties['scanner'], 'upload-scanner');
        $this->assertTrue($properties['pack'], 'upload-pack');
        $this->assertSame('CBZ', $properties['format'], 'upload-format');
        $this->assertSame(['superhero', 'batman'], $properties['tags'], 'upload-tags');
        $this->assertSame('A comic series description.', $properties['description'], 'upload-description');
        $this->assertSame('A release description.', $properties['release_description'], 'upload-release-description');
    }

    public function testLabelWithoutIssueNumber(): void {
        $post = $this->validPost();
        unset($post['issue_start'], $post['issue_end']);
        $post['issue_label'] = 'Annual 1';

        $properties = Comic\Upload::properties($post);
        $this->assertNull($properties['issue_start'], 'upload-label-start');
        $this->assertSame('Annual 1', $properties['issue_label'], 'upload-label');
        $this->assertFalse($properties['pack'], 'upload-label-pack');
    }

    public function testDuplicateCreatorsAreCollapsed(): void {
        $post = $this->validPost();
        $post['creators'] = ['Tom King', 'tom king', 'Mitch Gerads'];

        $properties = Comic\Upload::properties($post);
        $this->assertSame(['Tom King', 'Mitch Gerads'], $properties['creators'], 'upload-creator-dedupe');
    }

    public function testZeroIssueIsValid(): void {
        $post = $this->validPost();
        $post['issue_start'] = '0';
        $post['issue_end']   = '0';

        $properties = Comic\Upload::properties($post);
        $this->assertSame(0, $properties['issue_start'], 'upload-issue-zero');
        $this->assertFalse($properties['pack'], 'upload-issue-zero-pack');
    }

    public function testInvalidFormat(): void {
        $post = $this->validPost();
        $post['format'] = 'FLAC';
        $this->expectException(\InvalidArgumentException::class);
        Comic\Upload::properties($post);
    }

    public function testEndBeforeStart(): void {
        $post = $this->validPost();
        $post['issue_end'] = '0';
        $this->expectException(\InvalidArgumentException::class);
        Comic\Upload::properties($post);
    }

    public function testMissingIssue(): void {
        $post = $this->validPost();
        unset($post['issue_start'], $post['issue_end'], $post['issue_label']);
        $this->expectException(\InvalidArgumentException::class);
        Comic\Upload::properties($post);
    }

    public function testEndWithoutStart(): void {
        $post = $this->validPost();
        unset($post['issue_start']);
        $this->expectException(\InvalidArgumentException::class);
        Comic\Upload::properties($post);
    }
}
