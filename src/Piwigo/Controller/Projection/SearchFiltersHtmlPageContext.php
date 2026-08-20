<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `SEARCH_FILTERS` alone -- {@see \Piwigo\Controller\GalleryController::__invoke()}
 * renders {@see SearchFiltersView} via `Renderer::render()` (a page-scoped
 * sub-fragment, not `IndexView`'s own property), then writes the result
 * into `Template::$vars` this way, same shape as {@see
 * ThumbnailsHtmlPageContext}/{@see CategoryCatsHtmlPageContext}.
 * `index.latte`'s own `{if !empty($SEARCH_FILTERS)}{$SEARCH_FILTERS}{/if}`
 * guard replaces its old `{if !empty($SEARCH_ID)}{include
 * 'include/search_filters.inc.latte'}{/if}` pair -- the emptiness check
 * now tests the already-rendered `Html` itself, matching
 * `CATEGORIES`/`THUMBNAILS`.
 */
final readonly class SearchFiltersHtmlPageContext implements TemplatePageContext
{
    public function __construct(
        public Html $searchFilters,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'SEARCH_FILTERS' => $this->searchFilters,
        ];
    }
}
