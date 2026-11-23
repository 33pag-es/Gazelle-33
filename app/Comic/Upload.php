<?php

declare(strict_types=1);

namespace Gazelle\Comic;

use Gazelle\Enum\ComicSource;
use Gazelle\Enum\CreatorRole;
use Gazelle\Enum\SeriesStatus;
use Gazelle\Enum\SeriesType;
use InvalidArgumentException;

/**
 * @phpstan-import-type CollectRange from Collect
 * @phpstan-type ComicIssue array{
 *     issue_start: ?string,
 *     issue_end: ?string,
 *     issue_label: ?string,
 *     scanner: ?string,
 *     pack: bool,
 *     format: string,
 *     release_date: ?string,
 *     source: ComicSource,
 *     collects: list<CollectRange>,
 *     release_description: ?string,
 *     ...
 * }
 * @phpstan-type ComicSeries array{
 *     title: string,
 *     publisher: string,
 *     imprint: ?string,
 *     series_type: ?SeriesType,
 *     series_status: ?SeriesStatus,
 *     language: string,
 *     creators: string[],
 *     creator_roles: array<string, array<string>>,
 *     year: int,
 *     tags: string[],
 *     description: string,
 *     groupid: ?int,
 *     ...
 * }
 * @phpstan-type ComicProperties array{
 *     title: string,
 *     publisher: string,
 *     imprint: ?string,
 *     series_type: ?SeriesType,
 *     series_status: ?SeriesStatus,
 *     language: string,
 *     creators: string[],
 *     creator_roles: array<string, array<string>>,
 *     year: int,
 *     issue_start: ?string,
 *     issue_end: ?string,
 *     issue_label: ?string,
 *     scanner: ?string,
 *     pack: bool,
 *     format: string,
 *     release_date: ?string,
 *     source: ComicSource,
 *     collects: list<CollectRange>,
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
        $publisher   = self::publisherName($post['publisher'] ?? null);
        $creatorRole = self::creatorRoleList($post['creators'] ?? []);
        $groupId     = self::integerField($post, 'groupid', 1, PHP_INT_MAX, false,
            'Select a valid series.');

        // an issue added to an existing series does not change its details
        [
            'series_type'   => $type,
            'series_status' => $status,
            'imprint'       => $imprint,
            'language'      => $language,
        ] = self::classification($post, $isEdit || $groupId === null);

        $tagLength = $isEdit ? self::TAG_LENGTH_EDIT : self::TAG_LENGTH_UPLOAD;
        $tags = self::tagList(self::requiredString($post, 'tags', 2, $tagLength,
            "Enter at least one tag (maximum $tagLength characters)."));
        if (!Series::tagListFits($tags)) {
            throw new InvalidArgumentException(
                'A series holds at most ' . Series::TAGLIST_MAX . ' characters of tags; remove some tags.'
            );
        }

        $description = self::requiredString($post, 'desc', 10, 1_000_000,
            'The description must be between 10 and 1000000 characters.');

        return [
            'title'         => $title,
            'publisher'     => $publisher,
            'imprint'       => $imprint,
            'series_type'   => $type,
            'series_status' => $status,
            'language'      => $language,
            'creators'      => array_values(array_unique(array_merge(...array_values($creatorRole)))),
            'creator_roles' => $creatorRole,
            'year'          => $year,
            'tags'          => $tags,
            'description'   => $description,
            'groupid'       => $groupId,
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
        $issueStart = self::issueNumber($post, 'issue_start');
        $issueEnd   = self::issueNumber($post, 'issue_end');

        if ($issueStart === null && $issueLabel === null) {
            throw new InvalidArgumentException('Enter an issue number or an issue label.');
        }
        if ($issueEnd !== null) {
            if ($issueStart === null) {
                throw new InvalidArgumentException('An end issue number requires a start issue number.');
            }
            $sortStart = Torrent::issueSort($issueStart);
            $sortEnd   = Torrent::issueSort($issueEnd);
            if ($sortStart === null || $sortEnd === null) {
                throw new InvalidArgumentException('A range of issues needs two issue numbers that begin with a number.');
            }
            if ($sortEnd < $sortStart) {
                throw new InvalidArgumentException('The end issue number cannot be less than the start issue number.');
            }
        }
        $pack = Torrent::isPack($issueStart, $issueEnd);

        $releaseDate = self::dateField($post, 'release_date');
        $source      = self::enumField(ComicSource::class, $post, 'comic_source', true,
            'Select whether the files are a digital release or a scan.');
        $collects    = self::collectList($post['collects'] ?? '');

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
            'release_date'        => $releaseDate,
            'source'              => $source,
            'collects'            => $collects,
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
        $result = self::creatorNameList($value);
        if ($result === []) {
            throw new InvalidArgumentException('Enter at least one creator.');
        }
        return $result;
    }

    /**
     * Read the creators of the form: creators[writer], creators[artist] and
     * so on, each a comma-separated list. Names posted without a role, as
     * creators[] or creators, are kept as plain creators.
     *
     * @return array<string, array<string>> names by CreatorRole value
     * @throws InvalidArgumentException
     */
    public static function creatorRoleList(mixed $value): array {
        if (!is_array($value)) {
            $value = [$value];
        }
        $raw = [];
        foreach ($value as $key => $nameList) {
            $role = is_int($key) ? CreatorRole::creator : CreatorRole::tryFrom($key);
            if ($role === null) {
                throw new InvalidArgumentException('That is not a creator role.');
            }
            $raw[$role->value] = [...($raw[$role->value] ?? []), ...(is_array($nameList) ? $nameList : [$nameList])];
        }
        $result = [];
        foreach (CreatorRole::cases() as $role) {
            $nameList = self::creatorNameList($raw[$role->value] ?? []);
            if ($nameList) {
                $result[$role->value] = $nameList;
            }
        }
        if ($result === []) {
            throw new InvalidArgumentException('Enter at least one creator.');
        }
        return $result;
    }

    /**
     * @return string[]
     * @throws InvalidArgumentException
     */
    private static function creatorNameList(mixed $value): array {
        if (!is_array($value)) {
            $value = [$value];
        }
        $rawList = [];
        foreach ($value as $name) {
            if ($name === null) {
                continue;
            }
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
        if (count($result) > 20) {
            throw new InvalidArgumentException('Enter no more than 20 creators for one role.');
        }
        return $result;
    }

    /**
     * Read the "Collects" field: one line per run of issues, the series
     * (its link or its id) followed by the issue or the first and last issue.
     *
     * @return list<CollectRange>
     * @throws InvalidArgumentException
     */
    public static function collectList(mixed $value): array {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('The collected issues must be text.');
        }
        $lineList = array_values(array_filter(
            array_map(trim(...), explode("\n", (string)$value)),
            fn ($line) => $line !== '',
        ));
        if (count($lineList) > Collect::LINE_MAX) {
            throw new InvalidArgumentException('Enter no more than ' . Collect::LINE_MAX . ' lines of collected issues.');
        }
        $tgMan  = new \Gazelle\Manager\TGroup();
        $result = [];
        foreach ($lineList as $line) {
            if (!preg_match('/^(?:\S*[?&]id=)?(\d+)\S*\s+#?([^\s–-]+)(?:\s*[–-]\s*#?(\S+))?$/u', $line, $match)) {
                throw new InvalidArgumentException(
                    "Collected issues: write the series link or id, then the issues, such as “1234 1-6”. Not understood: $line"
                );
            }
            $start = self::normalizeIssueNumber($match[2]);
            $end   = isset($match[3]) ? self::normalizeIssueNumber($match[3]) : null;
            $tgroup = $tgMan->findById((int)$match[1]);
            if ($tgroup === null || $tgroup->categoryId() !== CATEGORY_COMIC) {
                throw new InvalidArgumentException("Collected issues: there is no series {$match[1]}.");
            }
            $result[] = [
                'tgroup_id'   => $tgroup->id,
                'issue_start' => $start,
                'issue_end'   => $end === $start ? null : $end,
            ];
        }
        return $result;
    }

    /**
     * @param ComicSeries $series
     */
    public static function applySeries(\Gazelle\TGroup $tgroup, array $series): void {
        Series::setPublisher($tgroup, $series['publisher']);
        Series::setClassification(
            $tgroup,
            $series['series_type'],
            $series['series_status'],
            $series['language'],
            $series['imprint'],
        );
        Series::setCreatorRoles($tgroup, $series['creator_roles']);
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
            $issue['release_date'],
            $issue['source'],
        );
        Collect::set($torrent, $issue['collects']);
    }

    /**
     * The series fields of the upload, torrent edit and group edit forms.
     *
     * @return array<string, mixed>
     */
    public static function seriesFormValues(\Gazelle\TGroup $tgroup): array {
        return [
            'Publisher'    => Series::publisher($tgroup),
            'Imprint'      => Series::imprint($tgroup),
            'SeriesType'   => Series::type($tgroup)?->value,
            'SeriesStatus' => Series::status($tgroup)?->value,
            'Language'     => Series::language($tgroup),
            'CreatorRoles' => array_map(
                fn (array $nameList) => implode(', ', $nameList),
                Series::creatorRoleList($tgroup),
            ),
        ];
    }

    /**
     * The issue fields of the torrent edit form.
     *
     * @return array<string, mixed>
     */
    public static function issueFormValues(\Gazelle\TorrentAbstract $torrent): array {
        return [
            'IssueStart'  => $torrent->issueNumber(),
            'IssueEnd'    => $torrent->issueNumberEnd(),
            'IssueLabel'  => $torrent->issueLabel(),
            'Scanner'     => $torrent->scanner(),
            'Checked'     => $torrent->isChecked(),
            'ReleaseDate' => $torrent->releaseDate(),
            'Source'      => $torrent->comicSource()?->value,
            'Collects'    => Collect::formText($torrent),
        ];
    }

    /**
     * @return array{series_type: ?SeriesType, series_status: ?SeriesStatus, imprint: ?string, language: string}
     * @throws InvalidArgumentException
     */
    private static function classification(array $post, bool $typeRequired): array {
        $language = self::optionalString($post, 'language', 2, 2, 'Select a valid language.')
            ?? COMIC_LANGUAGE_DEFAULT;
        if (!isset(COMIC_LANGUAGE[$language])) {
            throw new InvalidArgumentException('Select a valid language.');
        }
        return [
            'series_type'   => self::enumField(SeriesType::class, $post, 'series_type', $typeRequired,
                'Select a series type.'),
            'series_status' => self::enumField(SeriesStatus::class, $post, 'series_status', false,
                'Select a valid series status.'),
            'imprint'       => self::optionalString($post, 'imprint', 2, 255,
                'The imprint must be between 2 and 255 characters.'),
            'language'      => $language,
        ];
    }

    /**
     * Apply the series fields of the group edit form.
     *
     * @return list<string> what changed, for the log of the series
     * @throws InvalidArgumentException
     */
    public static function editSeries(\Gazelle\TGroup $tgroup, array $post): array {
        $publisher = self::publisherName($post['publisher'] ?? null);
        $roleList  = self::creatorRoleList($post['creators'] ?? []);
        $new       = self::classification($post, true);

        $log          = [];
        $oldPublisher = Series::publisher($tgroup);
        if ($publisher !== $oldPublisher) {
            Series::setPublisher($tgroup, $publisher);
            $log[] = "publisher “{$oldPublisher}” → “{$publisher}”";
        }

        $change = [
            'type'     => [Series::type($tgroup)?->label(), $new['series_type']?->label()],
            'status'   => [Series::status($tgroup)?->label(), $new['series_status']?->label()],
            'imprint'  => [Series::imprint($tgroup), $new['imprint']],
            'language' => [Series::language($tgroup), $new['language']],
        ];
        $changed = false;
        foreach ($change as $name => [$from, $to]) {
            if ($from !== $to) {
                $changed = true;
                $log[]   = "$name “{$from}” → “{$to}”";
            }
        }
        // an imprint belongs to its publisher, so a new publisher needs the imprint looked up again
        if ($changed || $publisher !== $oldPublisher) {
            Series::setClassification(
                $tgroup, $new['series_type'], $new['series_status'], $new['language'], $new['imprint'],
            );
        }

        $oldRoleList = Series::creatorRoleList($tgroup);
        if ($roleList !== $oldRoleList) {
            Series::setCreatorRoles($tgroup, $roleList);
            $log[] = 'creators “' . self::creatorRoleText($oldRoleList) . '” → “' . self::creatorRoleText($roleList) . '”';
        }
        return $log;
    }

    /**
     * @param array<string, array<string>> $roleList
     */
    private static function creatorRoleText(array $roleList): string {
        $text = [];
        foreach ($roleList as $role => $nameList) {
            $text[] = CreatorRole::from($role)->label() . ': ' . implode(', ', $nameList);
        }
        return implode('; ', $text);
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
                $issue['issue_start'] !== $torrent->issueNumber()
                || $issue['issue_end'] !== $torrent->issueNumberEnd()
                || $issue['issue_label'] !== $torrent->issueLabel()
                || $issue['scanner'] !== $torrent->scanner()
                || $issue['format'] !== $torrent->format()
                || $issue['source'] !== $torrent->comicSource()
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

    /**
     * An issue number as printed. Leading zeroes of a plain number are
     * dropped, so 001 and 1 are the same issue.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeIssueNumber(string $value): string {
        $value = trim($value);
        if (preg_match('/^\d+$/', $value)) {
            $value = ltrim($value, '0') ?: '0';
        }
        $sort = Torrent::issueSort($value);
        if (
            mb_strlen($value) > Torrent::ISSUE_NUMBER_LENGTH
            || !preg_match(Torrent::ISSUE_NUMBER_REGEXP, $value)
            || ($sort !== null && abs($sort) > Torrent::ISSUE_SORT_MAX)
        ) {
            throw new InvalidArgumentException(
                'An issue number begins with a digit and has at most ' . Torrent::ISSUE_NUMBER_LENGTH
                . ' characters, such as 1, 0, -1, ½, 1.MU or 23.1. Use the issue label for anything else.'
            );
        }
        return $value;
    }

    private static function issueNumber(array $post, string $key): ?string {
        $value = $post[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('An issue number must be text.');
        }
        return self::normalizeIssueNumber((string)$value);
    }

    private static function dateField(array $post, string $key): ?string {
        $value = $post[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $message = 'The release date must be a date between ' . self::YEAR_MIN . ' and next year, as YYYY-MM-DD.';
        if (!is_string($value)) {
            throw new InvalidArgumentException($message);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (
            $date === false
            || $date->format('Y-m-d') !== trim($value)
            || (int)$date->format('Y') < self::YEAR_MIN
            || (int)$date->format('Y') > (int)date('Y') + 1
        ) {
            throw new InvalidArgumentException($message);
        }
        return $date->format('Y-m-d');
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return ($required is true ? T : ?T)
     */
    private static function enumField(
        string $enum,
        array  $post,
        string $key,
        bool   $required,
        string $message,
    ): ?\BackedEnum {
        $value = $post[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException($message);
            }
            return null;
        }
        $case = is_string($value) ? $enum::tryFrom($value) : null;
        if ($case === null) {
            throw new InvalidArgumentException($message);
        }
        return $case;
    }
}
