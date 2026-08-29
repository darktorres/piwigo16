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
 *
 * Each block arrives with its markup already in `raw_content`, so this
 * view only lays them out. It used to carry a `match ($block->template)`
 * that replicated three sub-blocks' asset registrations on their behalf,
 * because `menubar.latte` reached them through a dynamic-filename
 * `{include $block->template, ...}` that no sub-template could hang a
 * `{templateType}` on. All seven now render through `Renderer::render()`
 * into `raw_content` and own their own assets, so both the include and
 * the dispatch are gone -- and with them `MenubarBlockView`, the
 * contract-only class that stood in for the seven missing view types.
 *
 * A plugin-registered block is no longer a separate path: it fills
 * `raw_content` like every other block, and one that fills nothing is
 * hidden by `apply()` rather than emitting an empty `<dl>`.
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
