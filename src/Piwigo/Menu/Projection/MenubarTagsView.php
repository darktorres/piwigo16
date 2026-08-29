<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar_tags.latte`'s own typed view. Rendered through
 * `Renderer::render()` into the block's `raw_content` -- see {@see
 * MenubarLinksView} for why the sub-templates are not `{include}`d.
 */
#[Template('menubar_tags.latte')]
final readonly class MenubarTagsView implements View
{
    /**
     * @param list<MenubarTagRow> $tags never empty -- the block is only
     *    filled in when the visitor can see at least one tag
     */
    public function __construct(
        public array $tags,
    ) {}
}
