<?php

declare(strict_types=1);

namespace Gazelle;

use GazelleUnitTest\Helper;
use PHPUnit\Framework\TestCase;

final class ComicSeriesPageTest extends TestCase {
    private User $user;
    private string $prefix;
    /** @var TGroup[] */
    private array $tgroupList = [];

    protected function setUp(): void {
        $this->user = Helper::makeUser('comic.' . randomString(8), 'comic');
        $this->user->setField('PermissionID', 40)->modify();
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

    public function testSeriesFragments(): void {
        $publisherName = $this->prefix . ' publisher';
        $creatorName   = $this->prefix . ' creator';
        $tgroup = Helper::makeTGroupComic(
            $this->user,
            $this->prefix . ' series',
            $publisherName,
            [$creatorName],
        );
        $this->tgroupList[] = $tgroup;
        $torrent = Helper::makeTorrentComic(
            tgroup:     $tgroup,
            user:       $this->user,
            issueStart: 1,
            scanner:    'Zone-Empire',
            format:     'CBZ',
        );
        Comic\Torrent::setChecked($torrent, $this->user, true);
        $torrent = new Manager\Torrent()->findById($torrent->id);

        Util\Twig::setViewer($this->user);
        $twig = Util\Twig::factory(new Manager\User());

        $header = $twig->render('comic/series-header.twig', [
            'collage_list' => [],
            'creator_list' => [$creatorName],
            'publisher'    => $publisherName,
            'tag_undo'     => null,
            'tgroup'       => $tgroup,
            'viewer'       => $this->user,
        ]);
        $this->assertStringContainsString($publisherName, $header, 'series-page-publisher');
        $this->assertStringContainsString($creatorName, $header, 'series-page-creator');
        $this->assertStringNotContainsString('Album of the Month', $header, 'series-page-no-aotm');

        $row = $twig->render('comic/torrent-row.twig', [
            'torrent' => $torrent,
            'tgroup'  => $tgroup,
            'viewer'  => $this->user,
        ]);
        $this->assertStringContainsString('Zone-Empire', $row, 'series-page-scanner');
        $this->assertStringContainsString('CBZ', $row, 'series-page-format');
        $this->assertStringContainsString('CHECKED', $row, 'series-page-checked');
        $this->assertStringContainsString('Uncheck', $row, 'series-page-check-action');
    }
}
