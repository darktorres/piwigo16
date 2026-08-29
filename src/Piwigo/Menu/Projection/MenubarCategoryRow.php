<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Core\Projection\RecentIcon;

/**
 * One album in the menubar's "Albums" tree.
 *
 * `$level` drives the tree's nesting the same way {@see
 * MenubarRelatedCategoryRow}'s does -- see that template's own comment for
 * why the `<ul>`/`<li>` are printed as raw text rather than tracked HTML.
 *
 * `$countImages` counts photos in this album and its descendants;
 * `$nbImages` only those directly in it. The badge shows the former and
 * picks its CSS class from whether the latter is above zero, which is how
 * "has its own photos" and "only has them through children" are told
 * apart.
 */
final readonly class MenubarCategoryRow
{
    public function __construct(
        public int $level,
        public string $name,
        public string $url,
        public string $title,
        public bool $selected,
        public bool $isUppercat,
        public int $countImages,
        public int $nbImages,
        public ?RecentIcon $recentIcon,
    ) {}
}
