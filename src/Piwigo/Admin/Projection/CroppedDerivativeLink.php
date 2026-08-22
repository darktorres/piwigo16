<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One entry of `picture_coi.latte`'s `$cropped_derivatives` list, built
 * by {@see \Piwigo\Admin\PictureCoiPageRenderer::render()} -- one row per
 * crop-enabled derivative size ({@see \Piwigo\Image\SizingParams::
 * $max_crop} `!== 0.0`).
 */
final readonly class CroppedDerivativeLink
{
    public function __construct(
        public string $uImg,
        public string $htmSize,
    ) {}

    /**
     * @return array{U_IMG: string, HTM_SIZE: string}
     */
    public function toArray(): array
    {
        return [
            'U_IMG' => $this->uImg,
            'HTM_SIZE' => $this->htmSize,
        ];
    }
}
