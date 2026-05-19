<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

use Piwigo\Search\MalformedSearchRulesException;

/**
 * Versioned typed wrapper around the JSON `rules` blob stored in the
 * `search` table.
 *
 * The legacy storage shape is `{version: int, mode: string, fields: {...}}`.
 * Each top-level filter under `fields` is parsed into its own VO; absent
 * or empty filter blocks become null. Unknown keys at the top level (or
 * under `fields`) are dropped — historical plugin extension keys are not
 * accepted under this contract; the WS layer adds new fields through
 * dedicated WS handlers and DTOs, not via this blob.
 */
final readonly class SearchRules
{
    public const int CURRENT_VERSION = 1;

    public function __construct(
        public int $version,
        public string $mode,
        public ?ExpertFilter $expert,
        public ?AllwordsFilter $allwords,
        public ?AuthorFilter $author,
        public ?CatFilter $cat,
        public ?DatePostedFilter $datePosted,
        public ?DateCreatedFilter $dateCreated,
        public ?TagsFilter $tags,
        public ?FiletypesFilter $filetypes,
        public ?AddedByFilter $addedBy,
        public ?RatioFilter $ratio,
        public ?RatingFilter $rating,
        public ?FileSizeFilter $fileSize,
        public ?HeightFilter $height,
        public ?WidthFilter $width,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            throw new MalformedSearchRulesException('rules JSON is not an object');
        }
        if ($raw !== [] && array_is_list($raw)) {
            throw new MalformedSearchRulesException('rules JSON is a list, expected an object');
        }
        /** @var array<string, mixed> $raw */
        return self::fromArray($raw);
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $version = is_int($raw['version'] ?? null) ? $raw['version'] : self::CURRENT_VERSION;
        return match ($version) {
            self::CURRENT_VERSION => self::fromV1($raw),
            default => throw new MalformedSearchRulesException("unsupported search rules version {$version}"),
        };
    }

    /**
     * Parse a v1 payload.
     *
     * @param array<string, mixed> $raw
     */
    private static function fromV1(array $raw): self
    {
        $mode      = is_string($raw['mode'] ?? null) ? $raw['mode'] : 'AND';
        /** @var array<string, mixed> $fields */
        $fields    = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];

        return new self(
            version:     1,
            mode:        $mode,
            expert:      self::parseAssoc($fields, 'expert', ExpertFilter::fromArray(...)),
            allwords:    self::parseAssoc($fields, 'allwords', AllwordsFilter::fromArray(...)),
            author:      self::parseAssoc($fields, 'author', AuthorFilter::fromArray(...)),
            cat:         self::parseAssoc($fields, 'cat', CatFilter::fromArray(...)),
            datePosted:  self::parseAssoc($fields, 'date_posted', DatePostedFilter::fromArray(...)),
            dateCreated: self::parseAssoc($fields, 'date_created', DateCreatedFilter::fromArray(...)),
            tags:        self::parseAssoc($fields, 'tags', TagsFilter::fromArray(...)),
            filetypes:   self::parseList($fields, 'filetypes', FiletypesFilter::fromArray(...)),
            addedBy:     self::parseList($fields, 'added_by', AddedByFilter::fromArray(...)),
            ratio:       self::parseList($fields, 'ratios', RatioFilter::fromArray(...)),
            rating:      self::parseList($fields, 'ratings', RatingFilter::fromArray(...)),
            fileSize:    FileSizeFilter::fromValues($fields['filesize_min'] ?? null, $fields['filesize_max'] ?? null),
            height:      HeightFilter::fromValues($fields['height_min']   ?? null, $fields['height_max']   ?? null),
            width:       WidthFilter::fromValues($fields['width_min']    ?? null, $fields['width_max']    ?? null),
        );
    }

    /**
     * @template T of object
     * @param  array<string, mixed>                 $fields
     * @param  callable(array<int|string, mixed>): ?T $parser
     * @return T|null
     */
    private static function parseAssoc(array $fields, string $key, callable $parser): ?object
    {
        $value = $fields[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }
        return $parser($value);
    }

    /**
     * @template T of object
     * @param  array<string, mixed>                 $fields
     * @param  callable(array<int|string, mixed>): ?T $parser
     * @return T|null
     */
    private static function parseList(array $fields, string $key, callable $parser): ?object
    {
        $value = $fields[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }
        return $parser($value);
    }
}
