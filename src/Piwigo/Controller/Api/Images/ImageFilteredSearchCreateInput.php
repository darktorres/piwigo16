<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/searches` body DTO -- every field is genuinely
 * optional and independently validated by the controller itself
 * (nested `fields` object, arrays of scalars, an `allwords`
 * sub-structure, `int|string`-union `searchId`), so this stays a
 * property bag over the same raw shape `JsonBody::decode()` already
 * produced rather than a fully-narrowed DTO -- narrowing an
 * array-typed field here is safe (the controller's own `isset() &&
 * is_array()` gate already treats "wrong type" and "absent" as the
 * same outcome, a silently-skipped filter, so collapsing both to null
 * changes nothing), but `searchId`/`*Preset`/`*Custom`/`ratings` each
 * have their own real "present but wrong type -> 422" branch that a
 * narrowing DTO field would silently swallow into "treat as absent" --
 * those stay `mixed`, exactly the raw value the controller already
 * type-checks itself.
 */
final readonly class ImageFilteredSearchCreateInput
{
    /**
     * @param ?array<array-key, mixed> $allwordsFields
     * @param ?array<array-key, mixed> $tags
     * @param ?array<array-key, mixed> $categories
     * @param ?array<array-key, mixed> $authors
     * @param ?array<array-key, mixed> $filetypes
     * @param ?array<array-key, mixed> $addedBy
     * @param ?array<array-key, mixed> $ratios
     */
    public function __construct(
        public mixed $searchId,
        public ?string $allwords,
        public ?string $allwordsMode,
        public ?array $allwordsFields,
        public ?array $tags,
        public ?string $tagsMode,
        public ?array $categories,
        public bool $categoriesWithsubs,
        public ?array $authors,
        public ?array $filetypes,
        public ?array $addedBy,
        public mixed $datePostedPreset,
        public mixed $datePostedCustom,
        public mixed $dateCreatedPreset,
        public mixed $dateCreatedCustom,
        public ?array $ratios,
        public mixed $ratings,
        public ?int $filesizeMin,
        public ?int $filesizeMax,
        public ?int $widthMin,
        public ?int $widthMax,
        public ?int $heightMin,
        public ?int $heightMax,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            searchId: $raw['searchId'] ?? null,
            allwords: is_string($raw['allwords'] ?? null) ? $raw['allwords'] : null,
            allwordsMode: is_string($raw['allwordsMode'] ?? null) ? $raw['allwordsMode'] : null,
            allwordsFields: is_array($raw['allwordsFields'] ?? null) ? $raw['allwordsFields'] : null,
            tags: is_array($raw['tags'] ?? null) ? $raw['tags'] : null,
            tagsMode: is_string($raw['tagsMode'] ?? null) ? $raw['tagsMode'] : null,
            categories: is_array($raw['categories'] ?? null) ? $raw['categories'] : null,
            categoriesWithsubs: (bool) ($raw['categoriesWithsubs'] ?? false),
            authors: is_array($raw['authors'] ?? null) ? $raw['authors'] : null,
            filetypes: is_array($raw['filetypes'] ?? null) ? $raw['filetypes'] : null,
            addedBy: is_array($raw['addedBy'] ?? null) ? $raw['addedBy'] : null,
            datePostedPreset: $raw['datePostedPreset'] ?? null,
            datePostedCustom: $raw['datePostedCustom'] ?? null,
            dateCreatedPreset: $raw['dateCreatedPreset'] ?? null,
            dateCreatedCustom: $raw['dateCreatedCustom'] ?? null,
            ratios: is_array($raw['ratios'] ?? null) ? $raw['ratios'] : null,
            ratings: $raw['ratings'] ?? null,
            filesizeMin: is_int($raw['filesizeMin'] ?? null) ? $raw['filesizeMin'] : null,
            filesizeMax: is_int($raw['filesizeMax'] ?? null) ? $raw['filesizeMax'] : null,
            widthMin: is_int($raw['widthMin'] ?? null) ? $raw['widthMin'] : null,
            widthMax: is_int($raw['widthMax'] ?? null) ? $raw['widthMax'] : null,
            heightMin: is_int($raw['heightMin'] ?? null) ? $raw['heightMin'] : null,
            heightMax: is_int($raw['heightMax'] ?? null) ? $raw['heightMax'] : null,
        );
    }
}
