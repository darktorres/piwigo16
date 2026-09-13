<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Common\Enum\Section;

/**
 * Explicit-parameter identity for one section's item-id query --
 * everything SectionPopulator::resolveSectionItems() needs to run the
 * query, and nothing else (no URL parsing, no session/redirect/template
 * side effects). See that method's own docblock for why this exists.
 *
 * Named constructors rather than one do-everything constructor: `flat`
 * and `combinedCategories` are mutually exclusive in the real per-section
 * dispatch this mirrors (SectionPopulator::populate()'s own "GET IMAGES
 * LIST" block), so a flat boolean-soup shape would let both be set
 * incoherently. `Section\Event\GetIndexDerivativeParams`/
 * `Category\Event\GetCategoryDerivativeParams` are the sibling "typed
 * over stringly" precedent already established elsewhere in this
 * codebase.
 *
 * `Search` is deliberately not a case here -- see resolveSectionItems()'s
 * own docblock for why.
 */
final readonly class SectionItemQuery
{
    /**
     * @param  list<int>  $combinedCategoryIds
     * @param  list<int>  $tagIds
     * @param  list<string>  $imageIds
     */
    private function __construct(
        public Section $section,
        public ?CategoryInfo $category = null,
        public array $combinedCategoryIds = [],
        public bool $flat = false,
        public array $tagIds = [],
        public array $imageIds = [],
    ) {}

    public static function categories(CategoryInfo $category): self
    {
        return new self(section: Section::Categories, category: $category);
    }

    /**
     * @param  list<int>  $combinedCategoryIds  the primary category's own id plus every combined category's id
     */
    public static function combinedCategories(array $combinedCategoryIds): self
    {
        return new self(section: Section::Categories, combinedCategoryIds: $combinedCategoryIds);
    }

    public static function flatCategory(CategoryInfo $category): self
    {
        return new self(section: Section::Categories, category: $category, flat: true);
    }

    /**
     * Whole-gallery flat mode -- no category restriction at all.
     */
    public static function wholeGalleryFlat(): self
    {
        return new self(section: Section::Categories, flat: true);
    }

    /**
     * @param  list<int>  $tagIds
     */
    public static function tags(array $tagIds): self
    {
        return new self(section: Section::Tags, tagIds: $tagIds);
    }

    public static function favorites(): self
    {
        return new self(section: Section::Favorites);
    }

    public static function recentPics(): self
    {
        return new self(section: Section::RecentPics);
    }

    public static function mostVisited(): self
    {
        return new self(section: Section::MostVisited);
    }

    public static function bestRated(): self
    {
        return new self(section: Section::BestRated);
    }

    /**
     * @param  list<string>  $imageIds
     */
    public static function imageList(array $imageIds): self
    {
        return new self(section: Section::ListView, imageIds: $imageIds);
    }
}
