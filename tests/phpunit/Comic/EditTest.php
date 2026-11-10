<?php

declare(strict_types=1);

namespace Gazelle;

use GazelleUnitTest\Helper;
use PHPUnit\Framework\TestCase;

final class ComicEditTest extends TestCase {
    private User $uploader;
    private User $staff;
    private string $prefix;
    /** @var TGroup[] */
    private array $tgroupList = [];

    protected function setUp(): void {
        $this->uploader = Helper::makeUser('comic.' . randomString(8), 'comic');
        $this->staff    = Helper::makeUser('comic.' . randomString(8), 'comic');
        $this->staff->setField('PermissionID', SYSOP)->modify();
        $this->uploader->requestContext()->setViewer($this->uploader);
        $this->prefix = 'phpunit comic ' . randomString(8);
    }

    protected function tearDown(): void {
        $db = DB::DB();
        foreach ($this->tgroupList as $tgroup) {
            $db->prepared_query("DELETE FROM wiki_torrents WHERE PageID = ?", $tgroup->id);
            Helper::removeTGroup($tgroup, $this->staff);
        }
        $db->prepared_query("DELETE FROM creator WHERE Name LIKE ?", $this->prefix . '%');
        $db->prepared_query("DELETE FROM publisher WHERE Name LIKE ?", $this->prefix . '%');
        Helper::removeUser($this->uploader);
        Helper::removeUser($this->staff);
    }

    /**
     * @return array{TGroup, Torrent}
     */
    private function makeIssue(): array {
        $tgroup = Helper::makeTGroupComic(
            $this->uploader,
            $this->prefix . ' series',
            $this->prefix . ' publisher',
            [$this->prefix . ' creator'],
        );
        $this->tgroupList[] = $tgroup;
        $torrent = Helper::makeTorrentComic($tgroup, $this->uploader, issueStart: 1, scanner: 'Before');
        return [$tgroup, $torrent];
    }

    /**
     * @return array<string, mixed>
     */
    private function editPost(): array {
        return [
            'title'       => $this->prefix . ' renamed',
            'publisher'   => $this->prefix . ' other publisher',
            'creators'    => [$this->prefix . ' other creator'],
            'year'        => '2020',
            'issue_start' => '2',
            'scanner'     => 'After',
            'format'      => 'CBR',
            'tags'        => 'phpunit.comic.edit',
            'desc'        => 'A replaced series description.',
        ];
    }

    public function testUploaderChangesOnlyIssueFields(): void {
        [$tgroup, $torrent] = $this->makeIssue();
        // an uploader's form is read for the issue fields only
        $comic = Comic\Upload::issueProperties($this->editPost());

        $this->assertFalse(Comic\Upload::canEditSeries($this->uploader), 'edit-uploader-cannot-edit-series');
        $this->assertFalse(
            Comic\Upload::reviseSeries($tgroup, null, Image::fromUntrusted(null), $this->uploader),
            'edit-uploader-no-revision',
        );
        $this->assertFalse(
            Comic\Upload::applyMetadata($tgroup, $torrent, $comic, null, $this->uploader, $tgroup->image()),
            'edit-uploader-series-skipped',
        );

        $tgroup  = new Manager\TGroup()->findById($tgroup->id);
        $torrent = new Manager\Torrent()->findById($torrent->id);
        $this->assertSame($this->prefix . ' series', $tgroup->name(), 'edit-uploader-name-kept');
        $this->assertSame($this->prefix . ' publisher', Comic\Series::publisher($tgroup), 'edit-uploader-publisher-kept');
        $this->assertSame([$this->prefix . ' creator'], Comic\Series::creatorList($tgroup), 'edit-uploader-creators-kept');
        $this->assertSame(2, $torrent->issueStart(), 'edit-uploader-issue-changed');
        $this->assertSame('After', $torrent->scanner(), 'edit-uploader-scanner-changed');
    }

    public function testIssueEditIndependentOfSeriesState(): void {
        // Members add and remove series tags from the series page, so the
        // series fields of the edit form may no longer pass upload validation.
        $post = [
            'title'       => '',
            'publisher'   => '',
            'creators'    => [''],
            'year'        => '',
            'tags'        => '',
            'desc'        => '',
            'issue_start' => '7',
            'issue_end'   => '9',
            'format'      => 'pdf',
        ];
        $issue = Comic\Upload::issueProperties($post);
        $this->assertSame(7, $issue['issue_start'], 'edit-issue-start');
        $this->assertSame(9, $issue['issue_end'], 'edit-issue-end');
        $this->assertTrue($issue['pack'], 'edit-issue-pack');
        $this->assertSame('PDF', $issue['format'], 'edit-issue-format');
    }

    public function testLongTagListAcceptedOnEditOnly(): void {
        $tagList = array_map(fn ($n) => "phpunit.comic.tag.$n", range(1, 20));
        $post    = [...$this->editPost(), 'tags' => implode(', ', $tagList)];
        $this->assertGreaterThan(200, strlen($post['tags']), 'edit-tags-over-upload-limit');

        $this->assertSame($tagList, Comic\Upload::seriesProperties($post, isEdit: true)['tags'], 'edit-tags-accepted');
        $this->expectException(\InvalidArgumentException::class);
        Comic\Upload::seriesProperties($post);
    }

    public function testStaffChangesSeriesWithRevision(): void {
        [$tgroup, $torrent] = $this->makeIssue();
        $comic  = Comic\Upload::issueProperties($this->editPost());
        $series = Comic\Upload::seriesProperties($this->editPost(), isEdit: true);

        $this->assertTrue(
            Comic\Upload::reviseSeries($tgroup, $series, $tgroup->image(), $this->staff),
            'edit-staff-revision',
        );
        $this->assertTrue(
            Comic\Upload::applyMetadata($tgroup, $torrent, $comic, $series, $this->staff, $tgroup->image()),
            'edit-staff-series-applied',
        );

        $tgroup = new Manager\TGroup()->findById($tgroup->id);
        $this->assertSame($this->prefix . ' renamed', $tgroup->name(), 'edit-staff-name');
        $this->assertSame(2020, $tgroup->year(), 'edit-staff-year');
        $this->assertSame('A replaced series description.', $tgroup->description(), 'edit-staff-description');
        $this->assertSame($this->prefix . ' other publisher', Comic\Series::publisher($tgroup), 'edit-staff-publisher');
        $this->assertSame([$this->prefix . ' other creator'], Comic\Series::creatorList($tgroup), 'edit-staff-creators');

        $bodyList = DB::DB()->scalar("
            SELECT group_concat(Body ORDER BY RevisionID SEPARATOR '|')
            FROM wiki_torrents
            WHERE PageID = ?
            ", $tgroup->id
        );
        $this->assertSame(
            'phpunit comic description|A replaced series description.',
            $bodyList,
            'edit-staff-revision-history',
        );
    }
}
