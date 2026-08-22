<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * `$search['fields']` -- the advanced-search rule bag stored as
 * `SavedSearchEntity::$rules['fields']` and rebuilt fresh by
 * {@see \Piwigo\Controller\SearchController}/{@see \Piwigo\Controller\
 * Api\Images\ImageFilteredSearchCreateController} on every new search.
 * Every real reader ({@see \Piwigo\Search\SearchService::
 * getRegularSearchResults()}, {@see \Piwigo\Search\SearchFilterRenderer::
 * render()}, {@see \Piwigo\Controller\Api\History\
 * HistorySearchController}) only ever touches these fields by their
 * literal name -- no site anywhere reads/writes a computed/dynamic key
 * into this bag, unlike its sibling `search_details`/
 * `image_ids_for_filter` map (genuinely keyed by whichever filters
 * happen to be active this request, left array-shaped).
 *
 * Mutable: `SearchFilterRenderer::render()` narrows several fields in
 * place (intersecting `words` down to what the current user may
 * actually see) and `unset()`s a field entirely once it's determined to
 * be inaccessible -- a null property plays the `unset()`'s role.
 * `null` means "this filter isn't part of the search" (the original
 * array's own `!isset()`); a rule object (even one holding only empty
 * defaults) means "this filter is part of the search," matching the
 * original array's own `isset()`-as-presence-flag semantics exactly --
 * a search can genuinely have e.g. `filetypes: []` (the filter is
 * active, no photos matched, still shown as applied) which reads
 * differently from `filetypes: null` (the filter was never chosen at
 * all).
 */
final class SearchRules
{
    /**
     * @param ?list<string> $filetypes
     * @param ?list<int|string> $addedBy
     * @param ?list<string> $ratios
     * @param ?list<string> $ratings
     */
    public function __construct(
        public ?AllwordsRule $allwords = null,
        public ?AuthorRule $author = null,
        public ?ExpertRule $expert = null,
        public ?array $filetypes = null,
        public ?array $addedBy = null,
        public ?CategoryRule $cat = null,
        public ?TagsRule $tags = null,
        public ?DateRule $datePosted = null,
        public ?DateRule $dateCreated = null,
        public ?array $ratios = null,
        public ?array $ratings = null,
        public int|string|null $filesizeMin = null,
        public int|string|null $filesizeMax = null,
        public int|string|null $widthMin = null,
        public int|string|null $widthMax = null,
        public int|string|null $heightMin = null,
        public int|string|null $heightMax = null,
    ) {}

    /**
     * Presence is keyed on `isset($row[...])`, not on the value's own
     * validity -- matching `SearchFilterRenderer::render()`'s own
     * pre-existing behavior (Browser-tested) of coercing a present-but-
     * garbage `tags`/`author`/`cat` value to an empty rule rather than
     * treating it as absent, instead of silently reinterpreting a
     * malformed row as "filter not chosen." A garbage inner shape
     * (`{words: "not-an-array"}`) still narrows to that rule's own
     * empty defaults either way.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $allwords = self::stringKeyedIfSet($row, 'allwords');
        $author = self::stringKeyedIfSet($row, 'author');
        $expert = self::stringKeyedIfSet($row, 'expert');
        $cat = self::stringKeyedIfSet($row, 'cat');
        $tags = self::stringKeyedIfSet($row, 'tags');
        $datePosted = self::stringKeyedIfSet($row, 'date_posted');
        $dateCreated = self::stringKeyedIfSet($row, 'date_created');

        return new self(
            allwords: $allwords !== null ? AllwordsRule::fromArray($allwords) : null,
            author: $author !== null ? AuthorRule::fromArray($author) : null,
            expert: $expert !== null ? ExpertRule::fromArray($expert) : null,
            filetypes: self::stringList($row['filetypes'] ?? null),
            addedBy: self::idList($row['added_by'] ?? null),
            cat: $cat !== null ? CategoryRule::fromArray($cat) : null,
            tags: $tags !== null ? TagsRule::fromArray($tags) : null,
            datePosted: $datePosted !== null ? DateRule::fromArray($datePosted) : null,
            dateCreated: $dateCreated !== null ? DateRule::fromArray($dateCreated) : null,
            ratios: self::stringList($row['ratios'] ?? null),
            ratings: self::stringList($row['ratings'] ?? null),
            filesizeMin: self::intOrString($row['filesize_min'] ?? null),
            filesizeMax: self::intOrString($row['filesize_max'] ?? null),
            widthMin: self::intOrString($row['width_min'] ?? null),
            widthMax: self::intOrString($row['width_max'] ?? null),
            heightMin: self::intOrString($row['height_min'] ?? null),
            heightMax: self::intOrString($row['height_max'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return ?array<string, mixed>
     */
    private static function stringKeyedIfSet(array $row, string $key): ?array
    {
        if (! isset($row[$key])) {
            return null;
        }

        $value = $row[$key];

        return is_array($value) ? array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @return ?list<string>
     */
    private static function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return ?list<int|string>
     */
    private static function idList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => is_int($v) || is_string($v)));
    }

    private static function intOrString(mixed $value): int|string|null
    {
        return (is_int($value) || is_string($value)) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->allwords instanceof AllwordsRule) {
            $result['allwords'] = $this->allwords->toArray();
        }

        if ($this->author instanceof AuthorRule) {
            $result['author'] = $this->author->toArray();
        }

        if ($this->expert instanceof ExpertRule) {
            $result['expert'] = $this->expert->toArray();
        }

        if ($this->filetypes !== null) {
            $result['filetypes'] = $this->filetypes;
        }

        if ($this->addedBy !== null) {
            $result['added_by'] = $this->addedBy;
        }

        if ($this->cat instanceof CategoryRule) {
            $result['cat'] = $this->cat->toArray();
        }

        if ($this->tags instanceof TagsRule) {
            $result['tags'] = $this->tags->toArray();
        }

        if ($this->datePosted instanceof DateRule) {
            $result['date_posted'] = $this->datePosted->toArray();
        }

        if ($this->dateCreated instanceof DateRule) {
            $result['date_created'] = $this->dateCreated->toArray();
        }

        if ($this->ratios !== null) {
            $result['ratios'] = $this->ratios;
        }

        if ($this->ratings !== null) {
            $result['ratings'] = $this->ratings;
        }

        if ($this->filesizeMin !== null) {
            $result['filesize_min'] = $this->filesizeMin;
        }

        if ($this->filesizeMax !== null) {
            $result['filesize_max'] = $this->filesizeMax;
        }

        if ($this->widthMin !== null) {
            $result['width_min'] = $this->widthMin;
        }

        if ($this->widthMax !== null) {
            $result['width_max'] = $this->widthMax;
        }

        if ($this->heightMin !== null) {
            $result['height_min'] = $this->heightMin;
        }

        if ($this->heightMax !== null) {
            $result['height_max'] = $this->heightMax;
        }

        return $result;
    }
}
