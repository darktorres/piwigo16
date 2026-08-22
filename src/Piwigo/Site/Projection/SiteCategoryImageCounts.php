<?php

declare(strict_types=1);

namespace Piwigo\Site\Projection;

/**
 * {@see \Piwigo\Site\SiteRepository::findCategoryAndImageCountsBySite()}'s
 * own fixed `{nb_categories, nb_images}` row shape, one per site id.
 */
final readonly class SiteCategoryImageCounts
{
    public function __construct(
        public int $categories,
        public int $images,
    ) {}
}
