<?php

declare(strict_types=1);

namespace Piwigo\Tag;

/**
 * Tag domain business logic: the self-contained slice (plain tag reads +
 * pure computation). Constructor-injects TagRepository, plain constructor
 * injection (same shape as PermalinkService/GroupService).
 *
 * Calls the still-procedural tag_alpha_compare() (functions_html.inc.php,
 * Html domain, already migrated in P17) and trigger_change() directly --
 * free functions, no class dependency, no deptrac concern.
 */
final class TagService
{
    public function __construct(
        private readonly TagRepository $repo,
    ) {}

    /**
     * Returns all tags even associated to no image.
     *
     * @return list<array<string, mixed>> [id, name, url_name]
     */
    public function getAllTags(): array
    {
        $tags = [];
        foreach ($this->repo->findAll() as $row) {
            $row['name_raw'] = $row['name'];
            $row['name'] = trigger_change('render_tag_name', $row['name'], $row);
            $tags[] = $row;
        }

        usort($tags, tag_alpha_compare(...));

        return $tags;
    }

    /**
     * Returns a list of tags corresponding to any of ids, url_names or
     * names.
     *
     * @param array<int|string, int|string> $ids
     * @param array<int|string, string> $urlNames
     * @param array<int|string, string> $names
     * @return list<array<string, mixed>> [id, name, url_name]
     */
    public function findTags(array $ids = [], array $urlNames = [], array $names = []): array
    {
        return $this->repo->findByIdsUrlNamesOrNames($ids, $urlNames, $names);
    }

    /**
     * Giving a set of tags with a counter for each one, calculate the
     * display level of each tag.
     *
     * The level of each tag depends on the average count of tags. This
     * calculation method avoid having very different levels for tags
     * having nearly the same count when set are small.
     *
     * @param array<int, array<string, mixed>> $tags at least [id, counter]
     * @return array<int, array<string, mixed>> [..., level]
     */
    public function addLevelToTags(array $tags): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($tags === []) {
            return $tags;
        }

        $totalCount = 0;
        foreach ($tags as $tag) {
            $totalCount += is_numeric($tag['counter']) ? (int) $tag['counter'] : 0;
        }

        // average count of available tags will determine the level of each tag
        $tagAverageCount = $totalCount / count($tags);

        // tag levels threshold calculation: a tag with an average rate
        // must have the middle level.
        $tagsLevels = is_numeric($conf['tags_levels'] ?? null) ? (int) $conf['tags_levels'] : 5;

        $thresholdOfLevel = [];
        for ($i = 1; $i < $tagsLevels; $i++) {
            $thresholdOfLevel[$i] = 2 * $i * $tagAverageCount / $tagsLevels;
        }

        foreach ($tags as &$tag) {
            $tag['level'] = 1;

            for ($i = $tagsLevels - 1; $i >= 1; $i--) {
                if ($tag['counter'] > $thresholdOfLevel[$i]) {
                    $tag['level'] = $i + 1;
                    break;
                }
            }
        }
        unset($tag);

        return $tags;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagsIdCompare(array $a, array $b): int
    {
        return ($a['id'] < $b['id']) ? -1 : 1;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagsCounterCompare(array $a, array $b): int
    {
        $counterA = is_numeric($a['counter']) ? (float) $a['counter'] : 0.0;
        $counterB = is_numeric($b['counter']) ? (float) $b['counter'] : 0.0;

        if ($counterA === $counterB) {
            return $this->tagsIdCompare($a, $b);
        }

        return $counterA < $counterB ? 1 : -1;
    }
}
