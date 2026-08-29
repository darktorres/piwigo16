<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar_related_categories.latte`'s own typed view. Rendered through
 * `Renderer::render()` into the block's `raw_content` -- see {@see
 * MenubarLinksView} for why the sub-templates are not `{include}`d.
 */
#[Template('menubar_related_categories.latte')]
final readonly class MenubarRelatedCategoriesView implements View
{
    /**
     * @param list<MenubarRelatedCategoryRow> $categories in tree order;
     *    never empty, the block is only filled in when at least one
     *    related album survives the exclusions
     */
    public function __construct(
        public array $categories,
    ) {}
}
