<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

/**
 * One album in the menubar's "Related albums" tree.
 *
 * `$level` is the album's depth, derived from its global rank, and drives
 * how many `<ul>`/`</ul>` the template opens and closes between rows --
 * which is why that template builds its nesting as raw text rather than
 * tracked HTML.
 *
 * The array this replaces was mixed-case, `LEVEL`/`TITLE` coming from
 * `CategoryService::getRelatedCategoriesMenu()` and `url`/`name`/
 * `count_images`/`count_categories` from the row projection underneath
 * it. That service keeps returning arrays on purpose -- its own docblock
 * explains that a dynamic-index mutation loop defeats shape tracking
 * regardless -- and says to convert at this one boundary, which is here.
 */
final readonly class MenubarRelatedCategoryRow
{
    public function __construct(
        public int $level,
        public string $name,
        public ?string $url,
        public ?string $title,
        public ?int $countImages,
        public ?int $countCategories,
    ) {}
}
