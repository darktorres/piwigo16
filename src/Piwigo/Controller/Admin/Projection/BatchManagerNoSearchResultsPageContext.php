<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'no_search_results' template variable assigned by
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController} when a
 * search filter yields unmatched terms.
 */
final readonly class BatchManagerNoSearchResultsPageContext implements TemplatePageContext
{
    /**
     * @param list<string> $unmatchedTerms
     */
    public function __construct(
        public array $unmatchedTerms,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'no_search_results' => $this->unmatchedTerms,
        ];
    }
}
