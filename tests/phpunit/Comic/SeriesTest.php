<?php

declare(strict_types=1);

namespace Gazelle;

use GazelleUnitTest\Helper;
use PHPUnit\Framework\TestCase;

final class ComicSeriesTest extends TestCase {
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

    public function testSoftCreateIsIdempotent(): void {
        $publisherName = $this->prefix . ' publisher';
        $first  = Comic\Publisher::softCreate($publisherName);
        $second = Comic\Publisher::softCreate($publisherName);
        $this->assertSame($first->id, $second->id, 'publisher-reuse');

        $creatorName = $this->prefix . ' creator';
        $first  = Comic\Creator::softCreate($creatorName);
        $second = Comic\Creator::softCreate($creatorName);
        $this->assertSame($first->id, $second->id, 'creator-reuse');
    }

    public function testSeriesMetadata(): void {
        $publisherName = $this->prefix . ' publisher';
        $creatorList   = [
            $this->prefix . ' creator one',
            $this->prefix . ' creator two',
            $this->prefix . ' creator one',
        ];
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $publisherName,
            $creatorList,
            2024,
        );
        $this->tgroupList[] = $tgroup;

        $this->assertSame($publisherName, Comic\Series::publisher($tgroup), 'series-publisher');
        $this->assertSame(
            [$creatorList[0], $creatorList[1]],
            Comic\Series::creatorList($tgroup),
            'series-creators',
        );

        $publisher = Comic\Publisher::softCreate($publisherName);
        $found = Comic\Series::findByTitlePublisherYear($tgroup->name(), 2024, $publisher->id);
        $this->assertInstanceOf(TGroup::class, $found, 'series-find');
        $this->assertSame($tgroup->id, $found->id, 'series-find-id');
        $this->assertNull(
            Comic\Series::findByTitlePublisherYear($tgroup->name(), 2023, $publisher->id),
            'series-find-wrong-year',
        );
    }

    public function testCreatorReplacementPreservesOrder(): void {
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $this->prefix . ' publisher',
            [$this->prefix . ' creator one'],
        );
        $this->tgroupList[] = $tgroup;

        $creatorList = [
            $this->prefix . ' creator three',
            $this->prefix . ' creator two',
        ];
        $this->assertSame(2, Comic\Series::setCreators($tgroup, $creatorList), 'creator-count');
        $this->assertSame($creatorList, Comic\Series::creatorList($tgroup), 'creator-replace-order');
    }

    public function testTagRoom(): void {
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $this->prefix . ' publisher',
            [$this->prefix . ' creator'],
        );
        $this->tgroupList[] = $tgroup;

        // ten 49-character tags and nine separators fill 499 of the 500 characters
        $stem   = 'pu.' . strtolower(randomString(8)) . '.';
        $tagMan = new Manager\Tag();
        $nameList = [];
        for ($n = 0; $n < 10; $n++) {
            $nameList[] = $stem . str_repeat('x', 48 - strlen($stem)) . $n;
            $tagMan->softCreate($nameList[$n], $this->user)->addTGroup($tgroup, $this->user, 10);
        }
        $this->assertSame(499, strlen(implode(' ', $nameList)), 'series-tag-fixture-length');

        $this->assertTrue(Comic\Series::hasTagRoom($tgroup, $nameList[3]), 'series-tag-existing-fits');
        $this->assertFalse(Comic\Series::hasTagRoom($tgroup, 'x'), 'series-tag-no-room');

        $tagMan->findByName($nameList[9])->removeTGroup($tgroup);
        $this->assertTrue(Comic\Series::hasTagRoom($tgroup, $nameList[9]), 'series-tag-room-after-removal');
        $this->assertFalse(Comic\Series::hasTagRoom($tgroup, $nameList[9] . 'yy'), 'series-tag-one-over');

        DB::DB()->prepared_query("DELETE FROM torrents_tags WHERE GroupID = ?", $tgroup->id);
        DB::DB()->prepared_query("DELETE FROM tags WHERE Name LIKE ?", $stem . '%');
    }
}
