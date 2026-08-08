<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Search\SearchFilterRenderer::renderTagsFound()}.
 */
final readonly class SearchTagsFoundPageContext implements TemplatePageContext
{
    /**
     * @param list<string> $tagsFound
     */
    public function __construct(
        public array $tagsFound,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'TAGS_FOUND' => $this->tagsFound,
        ];
    }
}
