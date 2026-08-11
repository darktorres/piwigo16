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

    /**
     * @param int[] $in_size
     * @return int[]
     */
    public function computeFinalSize(array $in_size): array
    {
        $this->sizing->compute($in_size, null, $crop_rect, $scale_size);
        return $scale_size !== null ? array_map(intval(...), $scale_size) : $in_size;
    }

    public function maxWidth(): int
    {
        return $this->sizing->ideal_size[0];
    }

    public function maxHeight(): int
    {
        return $this->sizing->ideal_size[1];
    }

    /**
     * @todo : description of DerivativeParams::isIdentity
     * @param int[] $in_size
     */
    public function isIdentity(array $in_size): bool
    {
        if ($in_size[0] > $this->sizing->ideal_size[0] or
            $in_size[1] > $this->sizing->ideal_size[1]) {
            return false;
        }
        return true;
    }

    /**
     * @param int[] $out_size
     */
    public function willWatermark(array $out_size, ImageStdParams $imageStdParams): bool
    {
        if ($this->use_watermark) {
            $min_size = $imageStdParams->getWatermark()
                ->min_size;
            return $min_size[0] <= $out_size[0]
              || $min_size[1] <= $out_size[1];
        }
        return false;
    }
}
