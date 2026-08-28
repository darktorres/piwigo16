<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One row of `element_set_ranks.latte`'s `$thumbnails` list, built by
 * {@see \Piwigo\Admin\ElementSetRanksPageRenderer::render()}. `$size`
 * stays a raw `?int[]` pair -- {@see \Piwigo\Image\DerivativeImage::
 * getSize()}'s own signature is deliberately unconverted (see this
 * plan's cluster 14 writeup on `DerivativeParams::computeFinalSize()`).
 */
final readonly class ElementSetThumbnailRow
{
    /**
     * @param ?int[] $size
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $tnSrc,
        public int $rank,
        public ?array $size,
    ) {}
}
