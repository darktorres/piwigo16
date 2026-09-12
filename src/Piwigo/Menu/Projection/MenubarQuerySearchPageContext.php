<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * All that survives of `MenubarIdentificationPageContext`, which flattened
 * twelve template keys assigned ambiently by
 * {@see \Piwigo\Menu\MenubarRenderer::render()}. Eleven of them belonged
 * to one sub-block each and now reach their own typed View directly:
 * eight to {@see MenubarIdentificationView} (as its `$identity` union) and
 * `U_START_FILTER`/`U_STOP_FILTER` to {@see MenubarCategoriesView}.
 *
 * `QUERY_SEARCH` is the exception, and the reason this context still
 * exists: its reader is `index.latte`, not a menubar sub-block, and
 * `IndexView` does not declare it -- so it arrives through the corpus-wide
 * fallback union instead. Moving it onto `IndexView` is the proper end
 * state and belongs with that producer (`GalleryController` holds the same
 * `SectionContext` this reads it from); until then it stays ambient rather
 * than being smuggled onto a view that does not render it.
 *
 * Always assigned (empty string when there's no active search), not
 * conditionally omitted: a theme's own `menubar.latte` override (P29.6,
 * modus) reads `$QUERY_SEARCH` unconditionally (not gated on an active
 * search section the way `index.latte`'s own read already is) --
 * confirmed real via that override's own end-to-end test that a `{varType}`-
 * declared ambient var is not `isset()`-safe: Latte's compiler rewrites
 * `isset($x)` into `$x !== null` for a variable it has a declared type
 * for, so an unconditionally-omitted key still throws "Undefined
 * variable" at render time regardless of how defensively the template
 * itself checks for it.
 */
final readonly class MenubarQuerySearchPageContext implements TemplatePageContext
{
    public function __construct(
        public ?string $querySearch,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'QUERY_SEARCH' => $this->querySearch ?? '',
        ];
    }
}
