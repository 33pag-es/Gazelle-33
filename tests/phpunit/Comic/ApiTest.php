<?php

declare(strict_types=1);

namespace Gazelle;

use GazelleUnitTest\Helper;
use PHPUnit\Framework\TestCase;

final class ComicApiTest extends TestCase {
    private User $user;
    private string $prefix;
    /** @var TGroup[] */
    private array $tgroupList = [];

    protected function setUp(): void {
        $this->user = Helper::makeUser('comic.' . randomString(8), 'comic');
        $this->user->requestContext()->setViewer($this->user);
        $this->prefix = 'phpunit comic ' . randomString(8);
    }

    protected function tearDown(): void {
        foreach ($this->tgroupList as $tgroup) {
            Helper::removeTGroup($tgroup, $this->user);
        }
        $db = DB::DB();
        $db->prepared_query("DELETE FROM creator WHERE Name LIKE ?", $this->prefix . '%');
        $db->prepared_query("DELETE FROM publisher WHERE Name LIKE ?", $this->prefix . '%');
        Helper::removeUser($this->user);
    }

    public function testPayloadContainsComicFields(): void {
        $publisherName = $this->prefix . ' publisher';
        $creatorList   = [$this->prefix . ' creator'];
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $publisherName,
            $creatorList,
        );
        $this->tgroupList[] = $tgroup;
        $torrent = Helper::makeTorrentComic(
            tgroup:     $tgroup,
            user:       $this->user,
            issueStart: 1,
            issueEnd:   12,
            scanner:    'Zone-Empire',
            format:     'CBZ',
            pack:       true,
        );

        $group = new Json\TGroup($tgroup, $this->user)->tgroupPayload();
        $this->assertSame($publisherName, $group['publisher'], 'json-publisher');
        $this->assertSame($creatorList, $group['creators'], 'json-creators');

        $item = new Json\Torrent($torrent, $this->user)->torrentPayload();
        $this->assertSame(1, $item['issueStart'], 'json-issue-start');
        $this->assertSame(12, $item['issueEnd'], 'json-issue-end');
        $this->assertSame('1-12', $item['issueLabel'], 'json-issue-label');
        $this->assertSame('Zone-Empire', $item['scanner'], 'json-scanner');
        $this->assertTrue($item['pack'], 'json-pack');
        $this->assertFalse($item['checked'], 'json-checked');
    }
}
