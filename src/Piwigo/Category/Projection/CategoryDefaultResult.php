<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Image\DerivativeParams;

/**
 * {@see \Piwigo\Category\CategoryDefaultRenderer::render()}'s own return
 * value -- the raw thumbnail-grid data a caller threads into its own {@see
 * \Piwigo\Controller\Projection\ThumbnailsView} construction, plus
 * `slideshowUrl` (unrelated to the thumbnail render itself, just this same
 * method's other real return value) and `derivativeParams` (also read back
 * separately by `GalleryController` for its own "index sizes icon" menu,
 * the same value `assignContext()`+`getTemplateVars('derivative_params')`
 * used to thread between the two before this conversion).
 */
final readonly class CategoryDefaultResult
{
    /**
     * @param array<int|string, mixed> $thumbnails
     */
    public function __construct(
        public ?string $slideshowUrl,
        public DerivativeParams $derivativeParams,
        public int $maxRequests,
        public bool $showThumbnailCaption,
        public array $thumbnails,
    ) {}
}
