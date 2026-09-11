<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The raw `mbSpecials`/`mbMenu` rows `MenubarRenderer::render()` builds,
 * exposed ambiently alongside their own rendered `DisplayBlock::$raw_content`
 * -- not a replacement for {@see MenubarSpecialsView}/{@see MenubarMenuView},
 * which still receive the same rows directly as constructor args for
 * `menubar_specials.latte`/`menubar_menu.latte`'s own default rendering.
 *
 * `MenubarView::$blocks` only ever carries pre-rendered HTML (`DisplayBlock::
 * $raw_content`) -- by the time `menubar.latte` runs, the rows that built
 * `mbSpecials`/`mbMenu` are gone. A theme whose own `menubar.latte`/
 * `menubar_specials.latte` override needs to reshape that content (P29.6's
 * `modus`: hoisting `MenubarSpecialKind::MostVisited`/`BestRated` into
 * standalone top-level blocks, and merging `mbMenu`'s links into
 * `mbSpecials`'s own dropdown after an `<hr>` -- both real legacy behaviors
 * with no way to reconstruct them from rendered HTML) needs the rows
 * themselves. Same ambient-fallback mechanism as
 * {@see MenubarQuerySearchPageContext}'s own `QUERY_SEARCH`.
 *
 * Both fields default to empty rather than this context being conditionally
 * assigned: `mbSpecials`/`mbMenu` are each independently optional
 * (`BlockManager`/`RegisteredBlock` visibility), so "the block wasn't
 * computed this request" and "the block was computed empty" read the same
 * to a consuming template either way.
 */
final readonly class MenubarSpecialsPageContext implements TemplatePageContext
{
    /**
     * @param array<int, MenubarSpecialRow> $specials
     * @param array<int, MenubarMenuRow> $menuLinks
     */
    public function __construct(
        public array $specials = [],
        public array $menuLinks = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'MENUBAR_SPECIALS' => $this->specials,
            'MENUBAR_MENU_LINKS' => $this->menuLinks,
        ];
    }
}
