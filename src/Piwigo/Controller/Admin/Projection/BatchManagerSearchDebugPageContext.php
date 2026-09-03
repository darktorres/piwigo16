<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'footer_elements' template variable assigned by
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController} when a
 * search filter runs, carrying the quick-search's own debug trace --
 * `footer.latte` reads it via `{if isset($footer_elements)}`, so this is
 * only ever constructed inside that same conditional branch, matching
 * the sibling {@see BatchManagerNoSearchResultsPageContext}.
 *
 * $searchDebug is `SearchService::getQuickSearchResultsNoCache()`'s own
 * debug HTML comment block -- every dynamic piece it interpolates (the
 * parsed search expression/tokens) is already htmlspecialchars()'d by
 * that method itself, so the whole string is safe, pre-formed HTML
 * (P59), typed `Html` here rather than re-escaped.
 */
final readonly class BatchManagerSearchDebugPageContext implements TemplatePageContext
{
    public function __construct(
        public string $searchDebug,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'footer_elements' => [new Html($this->searchDebug)],
        ];
    }
}
