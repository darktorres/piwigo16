<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar_categories.latte`'s own typed view. Rendered through
 * `Renderer::render()` into the block's `raw_content` -- see {@see
 * MenubarLinksView} for why the sub-templates are not `{include}`d.
 *
 * `$startFilterUrl`/`$stopFilterUrl` are mutually exclusive and both null
 * when the filter icon is off. They used to reach this template as the
 * ambient `U_START_FILTER`/`U_STOP_FILTER` that
 * `MenubarIdentificationPageContext` assigns; this is their only reader,
 * and `MenubarRenderer` computes them a few lines before building this
 * view, so they are passed directly now.
 */
#[Template('menubar_categories.latte')]
final readonly class MenubarCategoriesView implements View
{
    /**
     * @param list<MenubarCategoryRow> $categories in tree order
     */
    public function __construct(
        public array $categories,
        public string $categoriesUrl,
        public ?int $totalPhotos,
        public ?string $startFilterUrl,
        public ?string $stopFilterUrl,
        public string $rootUrl,
        public string $iconDir,
    ) {}
}
