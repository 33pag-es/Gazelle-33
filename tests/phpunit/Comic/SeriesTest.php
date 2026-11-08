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
}
