<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use Gazelle\Cache;
use Gazelle\Comment;
use Gazelle\Enum;
use Gazelle\Manager;
use Gazelle\TGroup;
use Gazelle\User;
use Gazelle\Util;
use Gazelle\View;

final class SeriesPage {
    /**
     * @param array<int> $torrentList
     */
    public static function render(
        \Twig\Environment $twig,
        TGroup      $tgroup,
        User        $viewer,
        Comment\Torrent $commentPage,
        Util\Paginator  $paginator,
        Manager\Collage $collageMan,
        Manager\Torrent $torMan,
        bool        $isSubscribed,
        string      $urlStem,
        array       $torrentList,
        Cache       $cache,
    ): void {
        $tagUndo     = $cache->get_value("deleted_tags_{$tgroup->id}_{$viewer->id}");
        $collageList = $collageMan->addToCollageDefault($tgroup, $viewer);
        $description = \Text::full_format(
            $tgroup->description(),
            cache: Enum\ImageCacheMode::cache,
            bucket: Enum\CacheBucket::tgroup,
        );
        echo $twig->render('torrent/detail-header.twig', [
            'is_bookmarked' => new User\Bookmark($viewer)->isBookmarked($tgroup),
            'is_subscribed' => $isSubscribed,
            'revision_id'   => (int)($_GET['revisionid'] ?? 0),
            'tgroup'        => $tgroup,
            'viewer'        => $viewer,
        ]);
        echo $twig->render('comic/series-header.twig', [
            'collage_list' => $collageList,
            'creator_list' => Series::creatorList($tgroup),
            'publisher'    => Series::publisher($tgroup),
            'tag_undo'     => $tagUndo,
            'tgroup'       => $tgroup,
            'viewer'       => $viewer,
        ]);
        echo '</div><div class="main_column">';
        echo $twig->render('comic/series-torrents.twig', [
            'flash'        => isset($_GET['checked'])
                ? ((int)$_GET['checked'] ? 'Torrent marked checked.' : 'Torrent marked unchecked.')
                : null,
            'tgroup'       => $tgroup,
            'torrent_list' => object_generator($torMan, $torrentList),
            'url_stem'     => $urlStem,
            'viewer'       => $viewer,
        ]);
        echo $twig->render('comic/series-description.twig', [
            'description_html' => $description,
        ]);
        echo $twig->render('comment/thread.twig', [
            'object'    => $tgroup,
            'comment'   => $commentPage,
            'paginator' => $paginator,
            'subbed'    => $isSubscribed,
            'textarea'  => new Util\Textarea('quickpost', '')->setPreviewManual(true),
            'url'       => $_SERVER['REQUEST_URI'],
            'url_stem'  => 'comments.php?page=torrents',
            'userMan'   => new Manager\User(),
            'viewer'    => $viewer,
        ]);
        View::show_footer();
    }
}
