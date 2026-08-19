<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * The 4 fractional crop-of-interest edges ({@see
 * \Piwigo\Image\DerivativeUrlCodec::charToFraction()}'s own decoded
 * output), built for display by
 * {@see \Piwigo\Admin\PictureCoiPageRenderer::render()} from an image
 * row's `coi` column.
 */
final readonly class CenterOfInterest
{
    public function __construct(
        public float $l,
        public float $t,
        public float $r,
        public float $b,
    ) {}

    /**
     * @return array{l: float, t: float, r: float, b: float}
     */
    public function toArray(): array
    {
        return [
            'l' => $this->l,
            't' => $this->t,
            'r' => $this->r,
            'b' => $this->b,
        ];
    }
}
