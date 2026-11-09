<?php

declare(strict_types=1);

namespace Gazelle;

use GazelleUnitTest\Helper;
use PHPUnit\Framework\TestCase;

final class ComicTorrentTest extends TestCase {
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

    private function makeTGroup(): TGroup {
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $this->prefix . ' publisher',
            [$this->prefix . ' creator'],
        );
        $this->tgroupList[] = $tgroup;
        return $tgroup;
    }

    public function testIssueRange(): void {
        $torrent = Helper::makeTorrentComic(
            tgroup:     $this->makeTGroup(),
            user:       $this->user,
            issueStart: 1,
            issueEnd:   10,
            scanner:    'Zone-Empire',
            format:     'CBZ',
            pack:       true,
        );
        $torrent = new Manager\Torrent()->findById($torrent->id);

        $this->assertSame(1, $torrent->issueStart(), 'issue-start');
        $this->assertSame(10, $torrent->issueEnd(), 'issue-end');
        $this->assertSame('Zone-Empire', $torrent->scanner(), 'issue-scanner');
        $this->assertSame('CBZ', $torrent->format(), 'issue-format');
        $this->assertTrue($torrent->isPack(), 'issue-pack');
        $this->assertFalse($torrent->isChecked(), 'issue-unchecked');
        $this->assertNull($torrent->checkedBy(), 'issue-unchecked-by');
        $this->assertNull($torrent->checkedAt(), 'issue-unchecked-at');
        $this->assertSame('1-10', Comic\Torrent::issueLabel($torrent), 'issue-label-range');
    }

    public function testIssueLabelOverridesRange(): void {
        $torrent = Helper::makeTorrentComic(
            tgroup:     $this->makeTGroup(),
            user:       $this->user,
            issueStart: null,
            issueLabel: 'Annual 1',
            scanner:    null,
            format:     'PDF',
        );
        $torrent = new Manager\Torrent()->findById($torrent->id);

        $this->assertSame('Annual 1', $torrent->issueLabel(), 'issue-raw-label');
        $this->assertSame('Annual 1', Comic\Torrent::issueLabel($torrent), 'issue-label');
        $this->assertFalse($torrent->isPack(), 'issue-label-pack');
        $this->assertNull($torrent->scanner(), 'issue-label-scanner');
    }

    public function testSingleIssue(): void {
        $torrent = Helper::makeTorrentComic(
            tgroup:     $this->makeTGroup(),
            user:       $this->user,
            issueStart: 5,
            issueEnd:   5,
            scanner:    'Scanner',
            format:     'CBR',
        );
        $torrent = new Manager\Torrent()->findById($torrent->id);

        $this->assertSame('5', Comic\Torrent::issueLabel($torrent), 'issue-single');
        $this->assertFalse($torrent->isPack(), 'issue-single-pack');
    }

    public function testLabelEscapesUserInput(): void {
        $torrent = Helper::makeTorrentComic(
            tgroup:     $this->makeTGroup(),
            user:       $this->user,
            issueStart: null,
            issueLabel: '<b>Annual</b>',
            scanner:    '<img src=x onerror=alert(1)>',
            format:     'CBZ',
        );
        $torrent = new Manager\Torrent()->findById($torrent->id);

        $this->assertSame(
            '&lt;b&gt;Annual&lt;/b&gt; / &lt;img src=x onerror=alert(1)&gt; / CBZ',
            $torrent->shortLabel(),
            'label-escaped',
        );
        $this->assertStringNotContainsString('<img', $torrent->shortLabelLink(), 'label-link-escaped');
        $this->assertStringNotContainsString('<img', $torrent->label($this->user), 'label-full-escaped');
    }
}
