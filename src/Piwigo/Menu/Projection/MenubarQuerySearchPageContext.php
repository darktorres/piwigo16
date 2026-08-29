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
        return $this->querySearch === null ? [] : [
            'QUERY_SEARCH' => $this->querySearch,
        ];
    }
}
