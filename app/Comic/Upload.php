<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use InvalidArgumentException;

/**
 * @phpstan-type ComicProperties array{
 *     title: string,
 *     publisher: string,
 *     creators: string[],
 *     year: int,
 *     issue_start: ?int,
 *     issue_end: ?int,
 *     issue_label: ?string,
 *     scanner: ?string,
 *     pack: bool,
 *     format: string,
 *     tags: string[],
 *     description: string,
 *     release_description: ?string,
 *     groupid: ?int,
 * }
 */
final class Upload {
    /**
     * Validate and normalize the comic upload form.
     *
     * @return ComicProperties
     * @throws InvalidArgumentException
     */
    public static function properties(array $post): array {
        $title     = self::requiredString($post, 'title', 2, 200, 'The series title must be between 2 and 200 characters.');
        $publisher = self::requiredString($post, 'publisher', 2, 255, 'The publisher must be between 2 and 255 characters.');
        $creators  = self::creatorList($post['creators'] ?? []);
        $year      = self::integerField($post, 'year', 1900, (int)date('Y') + 1, true,
            'The year must be between 1900 and ' . ((int)date('Y') + 1) . '.');

        $issueLabel = self::optionalString($post, 'issue_label', 1, 32, 'The issue label must be between 1 and 32 characters.');
        $issueStart = self::integerField($post, 'issue_start', 0, 100000, false,
            'The issue number must be between 0 and 100000.');
        $issueEnd   = self::integerField($post, 'issue_end', 0, 100000, false,
            'The end issue number must be between 0 and 100000.');

        if ($issueStart === null && $issueLabel === null) {
            throw new InvalidArgumentException('Enter an issue number or an issue label.');
        }
        if ($issueEnd !== null) {
            if ($issueStart === null) {
                throw new InvalidArgumentException('An end issue number requires a start issue number.');
            }
            if ($issueEnd < $issueStart) {
                throw new InvalidArgumentException('The end issue number cannot be less than the start issue number.');
            }
        }
        $pack = $issueStart !== null && $issueEnd !== null && $issueEnd > $issueStart;

        $scanner = self::optionalString($post, 'scanner', 1, 100, 'The scanner must be between 1 and 100 characters.');

        $format = strtoupper(self::requiredString($post, 'format', 2, 10, 'Select a comic format.'));
        if (!in_array($format, COMIC_FORMAT, true)) {
            throw new InvalidArgumentException('Select a valid comic format.');
        }

        $tags = self::tagList(self::requiredString($post, 'tags', 2, 200,
            'Enter at least one tag (maximum 200 characters).'));

        $description        = self::requiredString($post, 'desc', 10, 1_000_000,
            'The description must be between 10 and 1000000 characters.');
        $releaseDescription = self::optionalString($post, 'release_desc', 0, 1_000_000,
            'The release description is too long.');
        $groupId            = self::integerField($post, 'groupid', 1, PHP_INT_MAX, false,
            'Select a valid series.');

        return [
            'title'               => $title,
            'publisher'           => $publisher,
            'creators'            => $creators,
            'year'                => $year,
            'issue_start'         => $issueStart,
            'issue_end'           => $issueEnd,
            'issue_label'         => $issueLabel,
            'scanner'             => $scanner,
            'pack'                => $pack,
            'format'              => $format,
            'tags'                => $tags,
            'description'         => $description,
            'release_description' => $releaseDescription,
            'groupid'             => $groupId,
        ];
    }

    /**
     * @param ComicProperties $comic
     */
    public static function applySeries(\Gazelle\TGroup $tgroup, array $comic): void {
        Series::setPublisher($tgroup, $comic['publisher']);
        Series::setCreators($tgroup, $comic['creators']);
    }

    /**
     * @param ComicProperties $comic
     */
    public static function applyIssue(\Gazelle\Torrent $torrent, array $comic): void {
        Torrent::setIssue(
            $torrent,
            $comic['issue_start'],
            $comic['issue_end'],
            $comic['issue_label'],
            $comic['scanner'],
            $comic['pack'],
        );
    }

    /**
     * Series fields are shared by every issue in the series, so changing them
     * needs the same permission as the upstream group edit form.
     */
    public static function canEditSeries(\Gazelle\User $viewer): bool {
        return $viewer->permitted('torrents_edit');
    }

    /**
     * Record a wiki revision when the series description or cover changes.
     * Call this before applyMetadata(), as the upstream group edit does, so
     * the first revision stashes the original body and image.
     *
     * @param ComicProperties $comic
     */
    public static function reviseSeries(
        \Gazelle\TGroup $tgroup,
        array           $comic,
        \Gazelle\Image  $image,
        \Gazelle\User   $viewer,
    ): bool {
        if (
            !self::canEditSeries($viewer)
            || ($comic['description'] === $tgroup->description()
                && $image->url() === $tgroup->image()->url())
        ) {
            return false;
        }
        $tgroup->createRevision($comic['description'], $image, 'comic series edit', $viewer);
        return true;
    }

    /**
     * Apply the comic edit form. The uploader may change their own issue
     * fields; series fields are applied only for viewers who can edit series.
     *
     * @param ComicProperties $comic
     * @return bool true when the series fields were applied
     */
    public static function applyMetadata(
        \Gazelle\TGroup  $tgroup,
        \Gazelle\Torrent $torrent,
        array            $comic,
        \Gazelle\User    $viewer,
        \Gazelle\Image   $image,
    ): bool {
        self::applyIssue($torrent, $comic);
        if (!self::canEditSeries($viewer)) {
            return false;
        }
        $tgroup->setField('Name', $comic['title']);
        $tgroup->setField('Year', $comic['year']);
        $tgroup->setField('WikiBody', $comic['description']);
        $tgroup->setField('WikiImage', $image->url());
        $tgroup->modify();
        self::applySeries($tgroup, $comic);
        return true;
    }

    /**
     * Replace the group tags after the metadata transaction has committed.
     * Tags belong to the series, so this needs the series edit permission.
     *
     * @param string[] $tagList
     */
    public static function updateTags(\Gazelle\TGroup $tgroup, array $tagList, \Gazelle\User $viewer): void {
        if (!self::canEditSeries($viewer)) {
            return;
        }
        $tagMan  = new \Gazelle\Manager\Tag();
        $current = $tgroup->tagNameList();
        foreach ($current as $name) {
            if (!in_array($name, $tagList, true)) {
                $tagMan->findByName($name)?->removeTGroup($tgroup);
            }
        }
        foreach ($tagList as $name) {
            if (!in_array($name, $current, true)) {
                $tagMan->softCreate($name, $viewer)?->addTGroup($tgroup, $viewer, 10);
            }
        }
    }

    /**
     * @return string[]
     */
    private static function creatorList(mixed $value): array {
        if (!is_array($value)) {
            $value = [$value];
        }
        $rawList = [];
        foreach ($value as $name) {
            if (!is_scalar($name)) {
                throw new InvalidArgumentException('A creator name must be a string.');
            }
            $rawList = array_merge(
                $rawList,
                array_map(trim(...), explode(',', (string)$name)),
            );
        }
        $nameList = array_values(array_filter($rawList, fn ($name) => $name !== ''));
        $dedupe   = [];
        $result   = [];
        foreach ($nameList as $name) {
            if (mb_strlen($name) > 255) {
                throw new InvalidArgumentException('A creator name must be 255 characters or fewer.');
            }
            $key = mb_strtolower($name);
            if (!isset($dedupe[$key])) {
                $dedupe[$key] = true;
                $result[] = $name;
            }
        }
        if ($result === []) {
            throw new InvalidArgumentException('Enter at least one creator.');
        }
        if (count($result) > 20) {
            throw new InvalidArgumentException('Enter no more than 20 creators.');
        }
        return $result;
    }

    /**
     * @return string[]
     */
    private static function tagList(string $value): array {
        $tagList = [];
        foreach (explode(',', $value) as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tagList[] = $tag;
            }
        }
        $tagList = array_values(array_unique($tagList));
        if ($tagList === []) {
            throw new InvalidArgumentException('Enter at least one tag.');
        }
        return $tagList;
    }

    private static function requiredString(
        array  $post,
        string $key,
        int    $min,
        int    $max,
        string $message,
    ): string {
        $value = self::stringValue($post, $key, $message);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private static function optionalString(
        array  $post,
        string $key,
        int    $min,
        int    $max,
        string $message,
    ): ?string {
        $value = $post[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $value  = self::stringValue($post, $key, $message);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private static function stringValue(array $post, string $key, string $message): string {
        $value = $post[$key] ?? null;
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($message);
        }
        return trim((string)$value);
    }

    private static function integerField(
        array  $post,
        string $key,
        int    $min,
        int    $max,
        bool   $required,
        string $message,
    ): ?int {
        $value = $post[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException($message);
            }
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($message);
        }
        $value = filter_var(trim((string)$value), FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }
}
