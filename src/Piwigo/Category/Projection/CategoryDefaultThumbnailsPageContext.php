<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Image\DerivativeParams;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Category\CategoryDefaultRenderer::render()} before its
 * own `assignVarFromHandle('THUMBNAILS', 'index_thumbnails')` call
 * parses the template using these vars.
 */
final readonly class CategoryDefaultThumbnailsPageContext implements TemplatePageContext
{
    /**
     * @param array<int|string, mixed> $thumbnails
     */
    public function __construct(
        public DerivativeParams $derivativeParams,
        public int $maxRequests,
        public bool $showThumbnailCaption,
        public array $thumbnails,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'derivative_params' => $this->derivativeParams,
            'maxRequests' => $this->maxRequests,
            'SHOW_THUMBNAIL_CAPTION' => $this->showThumbnailCaption,
            'thumbnails' => $this->thumbnails,
        ];
    }
}
