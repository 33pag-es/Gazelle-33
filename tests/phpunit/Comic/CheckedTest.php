<?php

declare(strict_types=1);

namespace Gazelle;

use GazelleUnitTest\Helper;
use PHPUnit\Framework\TestCase;

final class ComicCheckedTest extends TestCase {
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

    public function testCheckedWorkflow(): void {
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $this->prefix . ' publisher',
            [$this->prefix . ' creator'],
        );
        $this->tgroupList[] = $tgroup;
        $torrent = Helper::makeTorrentComic(
            tgroup:  $tgroup,
            user:    $this->user,
            scanner: 'Zone-Empire',
            format:  'CBZ',
        );
        $this->assertFalse($torrent->isChecked(), 'comic-starts-unchecked');
        $this->assertNull($torrent->checkedBy(), 'comic-unchecked-by');

        Comic\Torrent::setChecked($torrent, $this->user, true);
        $torrent = new Manager\Torrent()->findById($torrent->id);
        $this->assertTrue($torrent->isChecked(), 'comic-checked');
        $this->assertSame($this->user->id, $torrent->checkedBy(), 'comic-checked-by');
        $this->assertNotNull($torrent->checkedAt(), 'comic-checked-at');

        Comic\Torrent::setChecked($torrent, $this->user, false);
        $torrent = new Manager\Torrent()->findById($torrent->id);
        $this->assertFalse($torrent->isChecked(), 'comic-unchecked');
        $this->assertSame($this->user->id, $torrent->checkedBy(), 'comic-unchecked-by-user');
        $this->assertNotNull($torrent->checkedAt(), 'comic-unchecked-at');
    }
}
