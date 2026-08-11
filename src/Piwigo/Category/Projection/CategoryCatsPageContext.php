<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Image\DerivativeParams;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Category\CategoryCatsRenderer::render()}, before its own
 * `assignVarFromHandle('CATEGORIES', 'index_category_thumbnails')`
 * call parses the template using these vars -- must stay assigned
 * before that call, not combined with {@see CategoryCatsNavbarPageContext}
 * (assigned afterward).
 */
final readonly class CategoryCatsPageContext implements TemplatePageContext
{
    /**
     * @param array<int|string, mixed> $categoryThumbnails
     */
    public function __construct(
        public int $maxRequests,
        public array $categoryThumbnails,
        public DerivativeParams $derivativeParams,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'maxRequests' => $this->maxRequests,
            'category_thumbnails' => $this->categoryThumbnails,
            'derivative_params' => $this->derivativeParams,
        ];
    }
}
