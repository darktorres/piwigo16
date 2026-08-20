<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Core\View;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Menu\BlockManager::apply()}. `Piwigo\Menu\*` is
 * L3Presentation (same layer as `Piwigo\Template\Renderer`), unlike the
 * L2a/L2b renderers earlier P40 batches had to split into a
 * data-returning method + caller-renders shape -- `BlockManager` renders
 * this itself.
 */
#[Template('menubar.latte')]
final readonly class MenubarView implements View
{
    /**
     * @param array<int|string, DisplayBlock> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {}
}
