<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (nb_categories, nb_images) per-site aggregate from CategoryRepository::findSiteStorageStats(), keyed by site_id. */
final readonly class SiteStorageStat
{
    public function __construct(
        public int $nbCategories,
        public int $nbImages,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            nbCategories: is_numeric($row['nb_categories'] ?? null) ? (int) $row['nb_categories'] : 0,
            nbImages:     is_numeric($row['nb_images'] ?? null) ? (int) $row['nb_images'] : 0,
        );
    }
}
