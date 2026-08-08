<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Search\SearchFilterRenderer::renderAlbumsFound()}.
 */
final readonly class SearchAlbumsFoundPageContext implements TemplatePageContext
{
    /**
     * @param list<string> $albumsFound
     */
    public function __construct(
        public array $albumsFound,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ALBUMS_FOUND' => $this->albumsFound,
        ];
    }
}
