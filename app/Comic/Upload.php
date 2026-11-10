<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use InvalidArgumentException;

/**
 * @phpstan-type ComicIssue array{
 *     issue_start: ?int,
 *     issue_end: ?int,
 *     issue_label: ?string,
 *     scanner: ?string,
 *     pack: bool,
 *     format: string,
 *     release_description: ?string,
 *     ...
 * }
 * @phpstan-type ComicSeries array{
 *     title: string,
 *     publisher: string,
 *     creators: string[],
 *     year: int,
 *     tags: string[],
 *     description: string,
 *     groupid: ?int,
 * }
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
    final public const YEAR_MIN = 1900;

    /**
     * The tag field of the upload form is capped like the upstream form. The
     * edit form is prefilled with every tag of the series, which members
     * extend from the series page up to what torrents_group.TagList holds
     * (500 characters), so it needs more room.
     */
    private const TAG_LENGTH_UPLOAD = 200;
    private const TAG_LENGTH_EDIT   = 1000;

    /**
     * @return array{title: string, year: int}
     * @throws InvalidArgumentException
     */
    public static function titleAndYear(array $post): array {
        $future = (int)date('Y') + 1;
        return [
            'title' => self::requiredString($post, 'title', 2, 200, 'The series title must be between 2 and 200 characters.'),
            'year'  => (int)self::integerField($post, 'year', self::YEAR_MIN, $future, true,
                'The year must be between ' . self::YEAR_MIN . " and $future."),
        ];
    }

    /**
     * Validate and normalize the comic upload form.
     *
     * @return ComicProperties
     * @throws InvalidArgumentException
     */
    public static function properties(array $post): array {
        return [...self::seriesProperties($post), ...self::issueProperties($post)];
    }

    /**
     * Validate and normalize the fields that belong to the series.
     *
     * @return ComicSeries
     * @throws InvalidArgumentException
     */
    public static function seriesProperties(array $post, bool $isEdit = false): array {
        ['title' => $title, 'year' => $year] = self::titleAndYear($post);
        $publisher = self::publisherName($post['publisher'] ?? null);
        $creators  = self::creatorList($post['creators'] ?? []);

        $tagLength = $isEdit ? self::TAG_LENGTH_EDIT : self::TAG_LENGTH_UPLOAD;
        $tags = self::tagList(self::requiredString($post, 'tags', 2, $tagLength,
            "Enter at least one tag (maximum $tagLength characters)."));

        $description = self::requiredString($post, 'desc', 10, 1_000_000,
            'The description must be between 10 and 1000000 characters.');
        $groupId     = self::integerField($post, 'groupid', 1, PHP_INT_MAX, false,
            'Select a valid series.');

        return [
            'title'       => $title,
            'publisher'   => $publisher,
            'creators'    => $creators,
            'year'        => $year,
            'tags'        => $tags,
            'description' => $description,
            'groupid'     => $groupId,
        ];
    }

    /**
     * Validate and normalize the fields that belong to one torrent. The
     * uploader of an issue may always change these, whatever the state of
     * the series fields.
     *
     * @return ComicIssue
     * @throws InvalidArgumentException
     */
    public static function issueProperties(array $post): array {
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

        $releaseDescription = self::optionalString($post, 'release_desc', 0, 1_000_000,
            'The release description is too long.');

        return [
            'issue_start'         => $issueStart,
            'issue_end'           => $issueEnd,
            'issue_label'         => $issueLabel,
            'scanner'             => $scanner,
            'pack'                => $pack,
            'format'              => $format,
            'release_description' => $releaseDescription,
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function publisherName(mixed $value): string {
        return self::requiredString(['publisher' => $value], 'publisher', 2, 255,
            'The publisher must be between 2 and 255 characters.');
    }

    /**
     * Normalize creator names: split on commas, trim, drop duplicates.
     *
     * @return string[]
     * @throws InvalidArgumentException
     */
    public static function creatorList(mixed $value): array {
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
     * @param array{publisher: string, creators: string[], ...} $series
     */
    public static function applySeries(\Gazelle\TGroup $tgroup, array $series): void {
        Series::setPublisher($tgroup, $series['publisher']);
        Series::setCreators($tgroup, $series['creators']);
    }

    /**
     * @param ComicIssue $issue
     */
    public static function applyIssue(\Gazelle\Torrent $torrent, array $issue): void {
        Torrent::setIssue(
            $torrent,
            $issue['issue_start'],
            $issue['issue_end'],
            $issue['issue_label'],
            $issue['scanner'],
            $issue['pack'],
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
     * Staff vouch for what they checked. When someone who cannot check
     * torrents changes what identifies the issue, the check no longer holds.
     * Call this before the torrent is modified.
     *
     * @param ComicIssue $issue
     */
    public static function invalidatesCheck(\Gazelle\Torrent $torrent, array $issue, \Gazelle\User $viewer): bool {
        return $torrent->isChecked()
            && !self::canEditSeries($viewer)
            && (
                $issue['issue_start'] !== $torrent->issueStart()
                || $issue['issue_end'] !== $torrent->issueEnd()
                || $issue['issue_label'] !== $torrent->issueLabel()
                || $issue['scanner'] !== $torrent->scanner()
                || $issue['format'] !== $torrent->format()
            );
    }

    /**
     * Record a wiki revision when the series description or cover changes.
     * Call this before applyMetadata(), as the upstream group edit does, so
     * the first revision stashes the original body and image.
     *
     * @param ?ComicSeries $series
     */
    public static function reviseSeries(
        \Gazelle\TGroup $tgroup,
        ?array          $series,
        \Gazelle\Image  $image,
        \Gazelle\User   $viewer,
    ): bool {
        if (
            $series === null
            || !self::canEditSeries($viewer)
            || ($series['description'] === $tgroup->description()
                && $image->url() === $tgroup->image()->url())
        ) {
            return false;
        }
        $tgroup->createRevision($series['description'], $image, 'comic series edit', $viewer);
        return true;
    }

    /**
     * Apply the comic edit form. The uploader may change their own issue
     * fields; series fields are applied only for viewers who can edit series.
     *
     * @param ComicIssue   $issue
     * @param ?ComicSeries $series
     * @return bool true when the series fields were applied
     */
    public static function applyMetadata(
        \Gazelle\TGroup  $tgroup,
        \Gazelle\Torrent $torrent,
        array            $issue,
        ?array           $series,
        \Gazelle\User    $viewer,
        \Gazelle\Image   $image,
    ): bool {
        self::applyIssue($torrent, $issue);
        if ($series === null || !self::canEditSeries($viewer)) {
            return false;
        }
        $tgroup->setField('Name', $series['title']);
        $tgroup->setField('Year', $series['year']);
        $tgroup->setField('WikiBody', $series['description']);
        $tgroup->setField('WikiImage', $image->url());
        $tgroup->modify();
        self::applySeries($tgroup, $series);
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
