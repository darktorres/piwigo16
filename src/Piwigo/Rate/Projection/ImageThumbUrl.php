<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * A thumbnail-url pair for one image, built by
 * {@see \Piwigo\Admin\RatingUserPageRenderer::render()} from
 * {@see \Piwigo\Rate\Projection\ImageThumbInfo} rows -- `tn` is a
 * {@see \Piwigo\Image\DerivativeImage::url()} result, `page` a
 * {@see \Piwigo\Core\UrlServiceInterface::makePictureUrl()} result.
 */
final readonly class ImageThumbUrl
{
    public function __construct(
        public string $tn,
        public string $page,
    ) {}

    /**
     * @return array{tn: string, page: string}
     */
    public function toArray(): array
    {
        return [
            'tn' => $this->tn,
            'page' => $this->page,
        ];
    }
}
