<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Menu\DisplayBlock;

/**
 * `{templateType}` target shared by all 7 real menubar sub-block
 * templates (`menubar_links.latte`, `menubar_categories.latte`,
 * `menubar_related_categories.latte`, `menubar_tags.latte`,
 * `menubar_specials.latte`, `menubar_menu.latte`,
 * `menubar_identification.latte`) -- each is reached only via
 * `menubar.latte`'s own native Latte `{include $block->template, block:
 * $block, id: $id}`, never through `Renderer::render()`, so this is a
 * contract-only conversion: a plain type-hint for
 * `VariableMapBuilder`'s reflection branch, deliberately NOT
 * implementing `Piwigo\Core\View` and carrying no `#[Template]`
 * attribute (there is no single file it corresponds to, and
 * `ViewTemplateTypeRoundTripTest` would otherwise require one).
 * Whichever ambient names a specific sub-block also needs beyond
 * `block`/`id` (e.g. `menubar_identification.latte`'s own
 * `U_LOGIN`/`U_LOGOUT`/etc., assigned ambiently by
 * `MenubarIdentificationPageContext` before `menubar.latte` itself
 * renders) still reach it via the corpus-wide fallback union
 * `VariableMap::forTemplate()` always merges in for names a
 * `{templateType}` View doesn't itself declare.
 */
final readonly class MenubarBlockView
{
    public function __construct(
        public DisplayBlock $block,
        public string $id,
    ) {}
}
