<?php

declare(strict_types=1);

namespace Gazelle;

use Gazelle\Enum\ComicSource;
use Gazelle\Enum\CreatorRole;
use Gazelle\Enum\SeriesStatus;
use Gazelle\Enum\SeriesType;
use GazelleUnitTest\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComicStandardTest extends TestCase {
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

    private function makeSeries(string $suffix, ?string $name = null): TGroup {
        $tgroup = Helper::makeTGroupComic(
            $this->staff,
            $name ?? "{$this->prefix} series $suffix",
            "{$this->prefix} publisher",
            ["{$this->prefix} creator"],
        );
        $this->tgroupList[] = $tgroup;
        return $tgroup;
    }

    /**
     * @return array<string, array{class-string<\BackedEnum>, string, string}>
     */
    public static function enumColumnProvider(): array {
        return [
            'series type'   => [SeriesType::class, 'torrents_group', 'series_type'],
            'series status' => [SeriesStatus::class, 'torrents_group', 'series_status'],
            'creator role'  => [CreatorRole::class, 'series_creator', 'role'],
            'comic source'  => [ComicSource::class, 'torrents', 'comic_source'],
        ];
    }

    /**
     * The search index stores the position of a value in the MySQL ENUM, and
     * the search filter sends index(): the two lists must be in the same order.
     *
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('enumColumnProvider')]
    public function testEnumMatchesColumn(string $enum, string $table, string $column): void {
        $type = (string)DB::DB()->scalar("
            SELECT c.COLUMN_TYPE
            FROM information_schema.COLUMNS c
            WHERE c.TABLE_SCHEMA = database() AND c.TABLE_NAME = ? AND c.COLUMN_NAME = ?
        ", $table, $column);
        preg_match_all("/'([^']+)'/", $type, $match);
        $this->assertSame(
            array_map(fn (\BackedEnum $case) => $case->value, $enum::cases()),
            $match[1],
            "enum-order-$column",
        );
    }

    public function testIssuesListInNumberOrder(): void {
        $tgroup = $this->makeSeries('order');
        // uploaded out of order, in formats that used to decide the order
        foreach (
            [
                ['10', null, null, 'CBR'], ['2', null, null, 'PDF'], ['1.MU', null, null, 'CBZ'],
                [null, null, 'Free Comic Book Day', 'CBZ'], ['½', null, null, 'CBZ'],
                ['1', null, null, 'PDF'], ['-1', null, null, 'CBZ'], ['0', null, null, 'CBR'],
                ['3', '6', null, 'CBZ'],
            ] as [$number, $end, $label, $format]
        ) {
            Helper::makeTorrentComic($tgroup, $this->staff, $number, $end, $label, format: $format)->flush();
        }
        $tgroup->flush();
        $torMan = new Manager\Torrent();
        $this->assertSame(
            ['-1', '0', '½', '1', '1.MU', '2', '3-6', '10', 'Free Comic Book Day'],
            array_map(
                fn (int $id) => Comic\Torrent::issueLabel($torMan->findById($id)),
                new TGroup($tgroup->id)->torrentIdList(),
            ),
            'standard-issue-order',
        );
    }

    public function testIssueNumberDerivesLegacyColumns(): void {
        $tgroup  = $this->makeSeries('legacy');
        $torrent = Helper::makeTorrentComic($tgroup, $this->staff, '1.MU', releaseDate: '2014-03-19', source: ComicSource::scan);
        $torrent = new Manager\Torrent()->findById($torrent->id);
        $this->assertSame('1.MU', $torrent->issueNumber(), 'standard-number-as-printed');
        $this->assertSame(1, $torrent->issueStart(), 'standard-number-whole-part');
        $this->assertFalse($torrent->isPack(), 'standard-number-not-pack');
        $this->assertSame('2014-03-19', $torrent->releaseDate(), 'standard-release-date');
        $this->assertSame(ComicSource::scan, $torrent->comicSource(), 'standard-source');
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function issueNumberProvider(): array {
        return [
            'leading zeroes dropped' => ['007', '7'],
            'zero kept'              => ['000', '0'],
            'negative'               => ['-1', '-1'],
            'half'                   => ['½', '½'],
            'point issue'            => ['1.MU', '1.MU'],
            'range in one field'     => ['1-6', null],
            'no number'              => ['Annual', null],
            'too long'               => ['12345678901234567', null],
            'too large to sort'      => ['99999999', null],
        ];
    }

    #[DataProvider('issueNumberProvider')]
    public function testIssueNumberValidation(string $input, ?string $expected): void {
        if ($expected === null) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $this->assertSame($expected, Comic\Upload::normalizeIssueNumber($input), 'standard-issue-number');
    }

    public function testRangeMustAscend(): void {
        $post = ['issue_start' => '½', 'issue_end' => '2', 'format' => 'CBZ', 'comic_source' => 'scan'];
        $this->assertTrue(Comic\Upload::issueProperties($post)['pack'], 'standard-fraction-range-is-pack');
        $this->expectException(\InvalidArgumentException::class);
        Comic\Upload::issueProperties([...$post, 'issue_start' => '2', 'issue_end' => '½']);
    }

    public function testSourceIsRequired(): void {
        $this->expectExceptionMessage('digital release or a scan');
        Comic\Upload::issueProperties(['issue_start' => '1', 'format' => 'CBZ']);
    }

    public function testCreatorRoles(): void {
        $tgroup = $this->makeSeries('roles');
        $writer = "{$this->prefix} creator writer";
        $artist = "{$this->prefix} creator artist";
        $roleList = Comic\Upload::creatorRoleList([
            'cover-artist' => $writer,
            'writer'       => "$writer, $writer",
            'artist'       => [$artist],
            'editor'       => '',
        ]);
        $this->assertSame(
            ['writer' => [$writer], 'artist' => [$artist], 'cover-artist' => [$writer]],
            $roleList,
            'standard-roles-parsed-in-role-order',
        );
        Comic\Series::setCreatorRoles($tgroup, $roleList);
        $this->assertSame($roleList, Comic\Series::creatorRoleList($tgroup), 'standard-roles-stored');
        $this->assertEqualsCanonicalizing(
            [$writer, $artist],
            Comic\Series::creatorList($tgroup),
            'standard-creator-named-once',
        );

        $this->assertSame(
            ['creator' => ['A', 'B']],
            Comic\Upload::creatorRoleList(['A, B']),
            'standard-roles-plain-list-kept',
        );
        $this->expectException(\InvalidArgumentException::class);
        Comic\Upload::creatorRoleList(['director' => 'A']);
    }

    public function testSeriesIdentityIncludesType(): void {
        $name    = "{$this->prefix} series identity";
        $monthly = $this->makeSeries('', $name);
        $trade   = $this->makeSeries('', $name);
        $publisherId = (int)$monthly->info()['PublisherID'];
        Comic\Series::setClassification($trade, SeriesType::tradePaperback, SeriesStatus::complete, 'fr', 'Vertigo');
        $trade = new TGroup($trade->id);

        // the monthly series has no type yet: it stands in for any type but the trade's
        $find = fn (?SeriesType $type) => Comic\Series::findByTitlePublisherYear($name, 2024, $publisherId, $type)?->id;
        $this->assertSame($trade->id, $find(SeriesType::tradePaperback), 'standard-identity-exact-type');
        $this->assertSame($monthly->id, $find(SeriesType::singleIssue), 'standard-identity-unset-type-matches');
        Comic\Series::setClassification($monthly, SeriesType::singleIssue, null, 'en', null);
        $this->assertNull($find(SeriesType::omnibus), 'standard-identity-other-type-is-new-series');

        $this->assertSame(SeriesType::tradePaperback, Comic\Series::type($trade), 'standard-type');
        $this->assertSame(SeriesStatus::complete, Comic\Series::status($trade), 'standard-status');
        $this->assertSame('French', Comic\Series::languageName($trade), 'standard-language');
        $this->assertSame('Vertigo', Comic\Series::imprint($trade), 'standard-imprint');
        $this->assertStringContainsString('[Trade Paperback]', $trade->text(), 'standard-type-in-title');
        $this->assertStringNotContainsString('[', new TGroup($monthly->id)->text(), 'standard-monthly-title-plain');
    }

    public function testNewSeriesNeedsType(): void {
        $post = [
            'title' => 'Saga', 'publisher' => 'Image', 'creators' => ['writer' => 'Brian K. Vaughan'],
            'year' => '2012', 'tags' => 'science.fiction', 'desc' => 'A series description.',
        ];
        $this->assertNull(
            Comic\Upload::seriesProperties([...$post, 'groupid' => '1'])['series_type'],
            'standard-existing-series-keeps-its-type',
        );
        $this->expectExceptionMessage('Select a series type.');
        Comic\Upload::seriesProperties($post);
    }

    public function testEditSeriesLogsChanges(): void {
        $tgroup = $this->makeSeries('edit');
        $post   = [
            'publisher'   => "{$this->prefix} publisher",
            'creators'    => ['creator' => "{$this->prefix} creator"],
            'series_type' => 'omnibus',
            'language'    => 'en',
        ];
        $this->assertSame(['type “” → “Omnibus”'], Comic\Upload::editSeries($tgroup, $post), 'standard-edit-log');
        $tgroup = new TGroup($tgroup->id);
        $this->assertSame([], Comic\Upload::editSeries($tgroup, $post), 'standard-edit-no-change');
        $this->assertSame(SeriesType::omnibus, Comic\Series::type($tgroup), 'standard-edit-applied');
    }

    public function testCollects(): void {
        $monthly = $this->makeSeries('monthly');
        $trade   = $this->makeSeries('trade');
        $volume  = Helper::makeTorrentComic($trade, $this->staff, 1);

        $rangeList = Comic\Upload::collectList(
            "torrents.php?id={$monthly->id}&torrentid=9#torrent9  #001-6\n\n{$monthly->id} 7"
        );
        $this->assertSame(
            [
                ['tgroup_id' => $monthly->id, 'issue_start' => '1', 'issue_end' => '6'],
                ['tgroup_id' => $monthly->id, 'issue_start' => '7', 'issue_end' => null],
            ],
            $rangeList,
            'standard-collects-parsed',
        );
        $this->assertSame(2, Comic\Collect::set($volume, $rangeList), 'standard-collects-stored');
        $this->assertSame("{$monthly->id} 1-6\n{$monthly->id} 7", Comic\Collect::formText($volume), 'standard-collects-form');

        $byTorrent = Comic\Collect::byTorrent($trade);
        $this->assertSame(['#1–6', '#7'], array_column($byTorrent[$volume->id], 'range'), 'standard-collects-on-volume');
        $collectedIn = Comic\Collect::collectedIn($monthly);
        $this->assertSame([$trade->id, $trade->id], array_map(fn ($c) => $c['tgroup']->id, $collectedIn), 'standard-collected-in');

        // merging the monthly series away keeps what the volume collects
        $target = $this->makeSeries('target');
        $this->assertSame(2, Comic\Collect::moveSeries($monthly, $target), 'standard-collects-follow-merge');
        $this->assertCount(2, Comic\Collect::collectedIn($target), 'standard-collects-on-target');

        $volume->removeTorrent($this->staff, 'phpunit');
        $this->assertSame([], Comic\Collect::collectedIn($target), 'standard-collects-removed-with-torrent');

        $this->expectExceptionMessage('there is no series');
        Comic\Upload::collectList('999999999 1-6');
    }
}
