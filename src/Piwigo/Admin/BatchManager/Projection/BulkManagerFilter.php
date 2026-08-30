<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * `$_SESSION['bulk_manager_filter']`'s own fixed, ~10-field shape, built
 * once by each of the 4 real consumers ({@see
 * \Piwigo\Controller\Admin\BatchManagerSubController::computeCurrentSet()}/
 * `computeDimensionOptions()`/`computeFilesizeOptions()`, {@see
 * \Piwigo\Admin\BatchManagerUnitPageRenderer}, {@see
 * \Piwigo\Admin\BatchManagerGlobalPageRenderer}, {@see
 * \Piwigo\Admin\BatchManager\FilterPanelRenderer}) immediately after
 * reading the raw session array, via {@see self::fromArray()}.
 *
 * The session bag itself deliberately stays a raw array at ONE remaining
 * boundary: `Controller\Admin\Event\BatchManagerRegisterFilters`'s own
 * array payload, a genuine plugin-extensibility dispatch point (no
 * registered handler today, but the event exists specifically so a plugin
 * could splice in an unknown key `fromArray()` wouldn't know to preserve).
 *
 * {@see \Piwigo\Admin\BatchManager\Projection\FilterPanelPageContext::$filter}
 * used to be a second such boundary, on the grounds that its Latte
 * template drives checkbox/selected state off raw `isset($filter['xxx'])`
 * key-presence checks. That was true and is no longer a reason (P58-B3):
 * the question those checks ask is "did the user enable this filter", and
 * key presence was only how an array could say it. Nine of the eleven
 * fields already answered it through their own null/empty default; the
 * four that could not -- `dimension`, `filesize`, `tags` and `search`, each
 * written by its own `filter_*_use` checkbox and therefore present-but-empty
 * whenever the box is ticked with nothing filled in -- now carry an
 * explicit `*Used` bool. That is a gap in the model being closed, not a
 * Smarty idiom being preserved: without it the VO could not represent
 * "enabled but blank" at all.
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::
 * resolveSessionFilter()} (the write side) is untouched for the same
 * reason its own docblock already gives: it can't keep a precise array
 * shape for a superglobal offset mutated with dynamic keys across many
 * independent if-blocks, so it builds the raw array once and commits it --
 * this VO only replaces the *reading* side's own repeated defensive
 * `isset()`/`is_numeric()` narrowing.
 */
final readonly class BulkManagerFilter
{
    /**
     * @param list<int> $tags
     */
    public function __construct(
        public ?string $prefilter = null,
        public DuplicateFieldFlags $duplicateFlags = new DuplicateFieldFlags(),
        public ?int $category = null,
        public bool $categoryRecursive = false,
        public array $tags = [],
        public ?string $tagMode = null,
        public ?int $level = null,
        public bool $levelIncludeLower = false,
        public DimensionFilter $dimension = new DimensionFilter(),
        public FilesizeFilter $filesize = new FilesizeFilter(),
        public ?string $searchQuery = null,
        public bool $dimensionUsed = false,
        public bool $filesizeUsed = false,
        public bool $tagsUsed = false,
        public bool $searchUsed = false,
    ) {}

    /**
     * @param array<string, mixed> $bulkFilter
     */
    public static function fromArray(array $bulkFilter): self
    {
        $tags = [];
        if (is_array($bulkFilter['tags'] ?? null)) {
            foreach ($bulkFilter['tags'] as $tag) {
                if (is_numeric($tag)) {
                    $tags[] = (int) $tag;
                }
            }
        }

        $searchQuery = null;
        if (is_array($bulkFilter['search'] ?? null) && is_string($bulkFilter['search']['q'] ?? null)) {
            $searchQuery = $bulkFilter['search']['q'];
        }

        return new self(
            prefilter: is_string($bulkFilter['prefilter'] ?? null) ? $bulkFilter['prefilter'] : null,
            duplicateFlags: DuplicateFieldFlags::fromBulkFilter($bulkFilter),
            category: isset($bulkFilter['category']) && is_numeric($bulkFilter['category']) ? (int) $bulkFilter['category'] : null,
            categoryRecursive: isset($bulkFilter['category_recursive']),
            tags: $tags,
            tagMode: is_string($bulkFilter['tag_mode'] ?? null) ? $bulkFilter['tag_mode'] : null,
            level: isset($bulkFilter['level']) && is_numeric($bulkFilter['level']) ? (int) $bulkFilter['level'] : null,
            levelIncludeLower: isset($bulkFilter['level_include_lower']),
            dimension: DimensionFilter::fromArray(self::stringKeyedSubArray($bulkFilter['dimension'] ?? null)),
            filesize: FilesizeFilter::fromArray(self::stringKeyedSubArray($bulkFilter['filesize'] ?? null)),
            searchQuery: $searchQuery,
            dimensionUsed: isset($bulkFilter['dimension']),
            filesizeUsed: isset($bulkFilter['filesize']),
            tagsUsed: isset($bulkFilter['tags']),
            searchUsed: isset($bulkFilter['search']),
        );
    }

    /**
     * $bulkFilter is only known as array<string, mixed>, so a nested array
     * offset only narrows to array<mixed, mixed> after is_array() --
     * rebuild with only string keys so DimensionFilter::fromArray()/
     * FilesizeFilter::fromArray()'s own declared array<string, mixed>
     * parameter type-checks against a real, verified shape rather than a
     * trust-me cast.
     *
     * @return array<string, mixed>
     */
    private static function stringKeyedSubArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
