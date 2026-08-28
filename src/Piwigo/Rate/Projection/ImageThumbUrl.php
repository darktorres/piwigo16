<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * One image's square-thumbnail URL, built by
 * {@see \Piwigo\Admin\RatingUserPageRenderer::render()} from
 * {@see \Piwigo\Rate\Projection\ImageThumbInfo} rows -- a
 * {@see \Piwigo\Image\DerivativeImage::url()} result, read by
 * `rating_user.latte`'s per-rate thumbnail {capture}.
 *
 * It carried a second `page` field, a makePictureUrl() result, until P58-A:
 * the flatten emitted it as a key and neither the template nor
 * rating_user.ts ever read it, before or after. It was costing a URL build
 * per rated image for nothing.
 */
final readonly class ImageThumbUrl
{
    public function __construct(
        public string $tn,
    ) {}
}
