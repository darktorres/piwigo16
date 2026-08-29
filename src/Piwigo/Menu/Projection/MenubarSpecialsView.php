<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar_specials.latte`'s own typed view. Rendered through
 * `Renderer::render()` into the block's `raw_content` -- see {@see
 * MenubarLinksView} for why the sub-templates are not `{include}`d.
 */
#[Template('menubar_specials.latte')]
final readonly class MenubarSpecialsView implements View
{
    /**
     * @param list<MenubarSpecialRow> $specials in display order; which
     *    entries are present depends on the visitor and on whether rating
     *    is enabled
     */
    public function __construct(
        public array $specials,
    ) {}
}
