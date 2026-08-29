<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

/**
 * All needed parameters to generate a derivative image.
 */
final class DerivativeParams
{
    /**
     * @var string among the ImageStdParams size-type constants
     */
    public string $type = ImageStdParams::CUSTOM;

    /**
     * @var int used for non-custom images to regenerate the cached files
     */
    public int $last_mod_time = 0;

    public bool $use_watermark = false;

    /**
     * @var float from 0=no sharpening to 1=max sharpening
     */
    public float $sharpen = 0.0;

    public function __construct(
        public SizingParams $sizing
    ) {}

    public function __serialize(): array
    {
        return [
            'last_mod_time' => $this->last_mod_time,
            'sizing' => $this->sizing,
            'sharpen' => $this->sharpen,
        ];
    }

    /**
     * Adds tokens depending on sizing configuration.
     *
     * @param array<int, int|string> $tokens
     */
    public function addUrlTokens(array &$tokens): void
    {
        $this->sizing->addUrlTokens($tokens);
    }

    public function computeFinalSize(Dimensions $in_size): Dimensions
    {
        $this->sizing->compute($in_size, null, $crop_rect, $scale_size);

        // compute()'s out-param is float-typed rectangle math; the sizes
        // this returns are pixel counts, so they are narrowed back to int
        // here rather than at each of the half-dozen read sites.
        return $scale_size instanceof Dimensions
            ? new Dimensions((int) $scale_size->width, (int) $scale_size->height)
            : $in_size;
    }

    public function maxWidth(): int
    {
        return (int) $this->sizing->ideal_size->width;
    }

    public function maxHeight(): int
    {
        return (int) $this->sizing->ideal_size->height;
    }

    /**
     * @todo : description of DerivativeParams::isIdentity
     */
    public function isIdentity(Dimensions $in_size): bool
    {
        if ($in_size->width > $this->sizing->ideal_size->width or
            $in_size->height > $this->sizing->ideal_size->height) {
            return false;
        }
        return true;
    }

    public function willWatermark(Dimensions $out_size, ImageStdParams $imageStdParams): bool
    {
        if ($this->use_watermark) {
            $min_size = $imageStdParams->getWatermark()
                ->min_size;
            return $min_size[0] <= $out_size->width
              || $min_size[1] <= $out_size->height;
        }
        return false;
    }
}
