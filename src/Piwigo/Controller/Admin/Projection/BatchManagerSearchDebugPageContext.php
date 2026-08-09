<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'footer_elements' template variable assigned by
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController} when a
 * search filter runs, carrying the quick-search's own debug trace --
 * `footer.tpl` reads it via `{if isset($footer_elements)}`, so this is
 * only ever constructed inside that same conditional branch, matching
 * the sibling {@see BatchManagerNoSearchResultsPageContext}.
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
            'footer_elements' => [$this->searchDebug],
        ];
    }
}
