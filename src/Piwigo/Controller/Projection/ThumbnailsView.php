<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `thumbnails.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} from
 * {@see \Piwigo\Category\CategoryDefaultRenderer::render()}'s own {@see
 * \Piwigo\Category\Projection\CategoryDefaultResult}. `CategoryDefaultRenderer`
 * is `Piwigo\Category\*` (L2aCoreDomain) and may not depend on
 * `Renderer`/`View`/`#[Template]` (L3Presentation) directly -- it stays a
 * pure data-returning method, same shape as
 * `Piwigo\Picture\PictureMetadataRenderer`/`PictureRateRenderer`, and its
 * caller (a Controller, always L3/L4) does the actual render. The rendered
 * `Html` is then written into `Template::$vars['THUMBNAILS']` via {@see
 * ThumbnailsHtmlPageContext}, staying an ambient sibling contributor
 * `index.latte` reads directly, not folded onto `IndexView` itself.
 */
#[Template('thumbnails.latte')]
final readonly class ThumbnailsView implements View
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
}
