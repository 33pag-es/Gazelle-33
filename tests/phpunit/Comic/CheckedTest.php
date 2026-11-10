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

    public function testUploaderEditWithdrawsCheck(): void {
        $staff = Helper::makeUser('comic.' . randomString(8), 'comic');
        $staff->setField('PermissionID', SYSOP)->modify();
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $this->prefix . ' publisher',
            [$this->prefix . ' creator'],
        );
        $this->tgroupList[] = $tgroup;
        $torrent = Helper::makeTorrentComic($tgroup, $this->user, issueStart: 4, scanner: 'Zone-Empire', format: 'CBZ');
        $same    = ['issue_start' => '4', 'scanner' => 'Zone-Empire', 'format' => 'CBZ', 'release_desc' => 'new notes'];
        $changed = [...$same, 'issue_start' => '5'];

        $this->assertFalse(
            Comic\Upload::invalidatesCheck($torrent, Comic\Upload::issueProperties($changed), $this->user),
            'comic-unchecked-nothing-to-withdraw',
        );

        Comic\Torrent::setChecked($torrent, $staff, true);
        $torrent = new Manager\Torrent()->findById($torrent->id);
        $this->assertFalse(
            Comic\Upload::invalidatesCheck($torrent, Comic\Upload::issueProperties($same), $this->user),
            'comic-check-survives-release-description',
        );
        $this->assertFalse(
            Comic\Upload::invalidatesCheck($torrent, Comic\Upload::issueProperties($changed), $staff),
            'comic-check-survives-staff-edit',
        );
        $this->assertTrue(
            Comic\Upload::invalidatesCheck($torrent, Comic\Upload::issueProperties($changed), $this->user),
            'comic-check-withdrawn-by-uploader-edit',
        );

        Comic\Torrent::resetChecked($torrent, $this->user);
        $torrent = new Manager\Torrent()->findById($torrent->id);
        $this->assertFalse($torrent->isChecked(), 'comic-reset-unchecked');
        $this->assertNull($torrent->checkedBy(), 'comic-reset-by-cleared');
        $this->assertNull($torrent->checkedAt(), 'comic-reset-at-cleared');

        Helper::removeUser($staff);
    }
}
