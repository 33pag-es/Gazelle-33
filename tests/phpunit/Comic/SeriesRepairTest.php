<?php

declare(strict_types=1);

namespace Gazelle;

use GazelleUnitTest\Helper;
use PHPUnit\Framework\TestCase;

final class ComicSeriesRepairTest extends TestCase {
    private User $staff;
    private string $prefix;
    /** @var TGroup[] */
    private array $tgroupList = [];

    protected function setUp(): void {
        $this->staff = Helper::makeUser('comic.' . randomString(8), 'comic');
        $this->staff->setField('PermissionID', SYSOP)->modify();
        $this->staff->requestContext()->setViewer($this->staff);
        $this->prefix = 'phpunit comic ' . randomString(8);
    }

    protected function tearDown(): void {
        $db = DB::DB();
        foreach ($this->tgroupList as $tgroup) {
            Helper::removeTGroup($tgroup, $this->staff);
        }
        $db->prepared_query("DELETE FROM creator WHERE Name LIKE ?", $this->prefix . '%');
        $db->prepared_query("DELETE FROM publisher WHERE Name LIKE ?", $this->prefix . '%');
        Helper::removeUser($this->staff);
    }

    private function makeSeries(string $suffix): TGroup {
        $tgroup = Helper::makeTGroupComic(
            $this->staff,
            "{$this->prefix} series $suffix",
            "{$this->prefix} publisher $suffix",
            ["{$this->prefix} creator $suffix"],
        );
        $this->tgroupList[] = $tgroup;
        return $tgroup;
    }

    private function seriesCreatorTotal(int $tgroupId): int {
        return (int)DB::DB()->scalar("SELECT count(*) FROM series_creator WHERE GroupID = ?", $tgroupId);
    }

    public function testMergeKeepsTargetSeriesMetadata(): void {
        $duplicate = $this->makeSeries('duplicate');
        $target    = $this->makeSeries('target');
        $issue     = Helper::makeTorrentComic($duplicate, $this->staff, issueStart: 3);
        Helper::makeTorrentComic($target, $this->staff, issueStart: 1)->flush();

        $this->assertTrue(new Manager\TGroup()->merge($duplicate, $target, $this->staff), 'repair-merge');

        $tgMan = new Manager\TGroup();
        $this->assertNull($tgMan->findById($duplicate->id), 'repair-merge-duplicate-gone');
        $this->assertSame(0, $this->seriesCreatorTotal($duplicate->id), 'repair-merge-duplicate-creators-gone');
        $target = $tgMan->findById($target->id);
        $this->assertSame(
            $target->id,
            new Manager\Torrent()->findById($issue->id)->groupId(),
            'repair-merge-issue-moved',
        );
        $this->assertCount(2, $target->torrentIdList(), 'repair-merge-target-issues');
        $this->assertSame("{$this->prefix} publisher target", Comic\Series::publisher($target), 'repair-merge-publisher-kept');
        $this->assertSame(["{$this->prefix} creator target"], Comic\Series::creatorList($target), 'repair-merge-creators-kept');
    }

    public function testMoveIssueToAnotherSeries(): void {
        $wrong = $this->makeSeries('wrong');
        $right = $this->makeSeries('right');
        $stays = Helper::makeTorrentComic($wrong, $this->staff, issueStart: 1);
        $moves = Helper::makeTorrentComic($wrong, $this->staff, issueStart: 2);

        $this->assertSame(1, $right->absorb($moves, $this->staff), 'repair-move');

        $torMan = new Manager\Torrent();
        $this->assertSame($right->id, $torMan->findById($moves->id)->groupId(), 'repair-move-issue-moved');
        $this->assertSame($wrong->id, $torMan->findById($stays->id)->groupId(), 'repair-move-other-issue-stays');
        $this->assertNotNull(new Manager\TGroup()->findById($wrong->id), 'repair-move-source-kept');
    }

    public function testSplitIssueIntoNewSeries(): void {
        $old   = $this->makeSeries('old');
        $stays = Helper::makeTorrentComic($old, $this->staff, issueStart: 1);
        $moves = Helper::makeTorrentComic($old, $this->staff, issueStart: 2);
        $stays->flush();

        $new = Comic\Series::splitFromTorrent($moves, "{$this->prefix} series new", 2019, $this->staff);
        $this->tgroupList[] = $new;

        $this->assertNotSame($old->id, $new->id, 'repair-split-new-series');
        $this->assertSame(CATEGORY_COMIC, $new->categoryId(), 'repair-split-category');
        $this->assertSame("{$this->prefix} series new", $new->name(), 'repair-split-title');
        $this->assertSame(2019, $new->year(), 'repair-split-year');
        $this->assertSame("{$this->prefix} publisher old", Comic\Series::publisher($new), 'repair-split-publisher-copied');
        $this->assertSame(["{$this->prefix} creator old"], Comic\Series::creatorList($new), 'repair-split-creators-copied');
        $this->assertSame($new->id, new Manager\Torrent()->findById($moves->id)->groupId(), 'repair-split-issue-moved');
        $this->assertSame(
            [$stays->id],
            new Manager\TGroup()->findById($old->id)->torrentIdList(),
            'repair-split-old-series-keeps-other-issue',
        );
    }

    public function testSplitLastIssueRemovesOldSeries(): void {
        $old   = $this->makeSeries('old');
        $moves = Helper::makeTorrentComic($old, $this->staff, issueStart: 1);

        $new = Comic\Series::splitFromTorrent($moves, "{$this->prefix} series new", 2019, $this->staff);
        $this->tgroupList[] = $new;

        $this->assertNull(new Manager\TGroup()->findById($old->id), 'repair-split-empty-series-removed');
        $this->assertSame([$moves->id], $new->torrentIdList(), 'repair-split-last-issue-moved');
    }

    public function testSplitRefusesExistingSeries(): void {
        $old   = $this->makeSeries('old');
        $moves = Helper::makeTorrentComic($old, $this->staff, issueStart: 1);

        $this->expectException(\InvalidArgumentException::class);
        Comic\Series::splitFromTorrent($moves, $old->name(), (int)$old->year(), $this->staff);
    }

    public function testAutocompleteMatchesPrefixLiterally(): void {
        $this->makeSeries('100% alpha');
        $this->makeSeries('100x beta');

        $publisherList = array_column(Comic\Publisher::autocomplete("{$this->prefix} publisher 100"), 'value');
        $this->assertSame(
            ["{$this->prefix} publisher 100% alpha", "{$this->prefix} publisher 100x beta"],
            $publisherList,
            'repair-autocomplete-publisher-prefix',
        );
        $this->assertSame(
            ["{$this->prefix} creator 100% alpha"],
            array_column(Comic\Creator::autocomplete("{$this->prefix} creator 100%"), 'value'),
            'repair-autocomplete-wildcard-is-literal',
        );
        $this->assertSame([], Comic\Publisher::autocomplete('alpha'), 'repair-autocomplete-not-infix');
    }
}
