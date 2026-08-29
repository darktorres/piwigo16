<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One row of `element_set_ranks.latte`'s `$thumbnails` list, built by
 * {@see \Piwigo\Admin\ElementSetRanksPageRenderer::render()}.
 *
 * The template needs two numbers for its `--tn-width`/`--tn-height`
 * custom properties, so it gets two, resolved once at the producer.
 * They come from {@see \Piwigo\Image\DerivativeImage::getSize()},
 * whose `?int[]` pair return is deliberately unconverted for now (see
 * the plan's cluster 14 writeup on
 * `DerivativeParams::computeFinalSize()`); reading `[0]`/`[1]` off it
 * in the template meant reading them off a possible null, which PHP 8
 * warns about on every row of a photo with no known dimensions.
 */
final readonly class ElementSetThumbnailRow
{
    public function __construct(
        public int $id,
        public string $name,
        public string $tnSrc,
        public int $rank,
        public ?int $width,
        public ?int $height,
    ) {}
}
