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
 * Paramaters for derivative scaling and cropping.
 * Instance of this class contained by DerivativeParams class.
 */
final class SizingParams
{
    /**
     * @param int[] $ideal_size - two element array of maximum output dimensions (width, height)
     * @param float $max_crop - from 0=no cropping to 1= max cropping (100% of width/height);
     *    expressed as a factor of the input width/height
     * @param int[] $min_size - (used only if _$max_crop_ !=0) two element array of output dimensions (width, height)
     */
    public function __construct(
        public $ideal_size,
        public $max_crop = 0,
        public $min_size = null
    ) {}

    /**
     * Returns a simple SizingParams object.
     *
     * @param int $w
     * @param int $h
     */
    public static function classic($w, $h): self
    {
        return new self([$w, $h]);
    }

    /**
     * Returns a square SizingParams object.
     */
    public static function square(int $w): self
    {
        return new self([$w, $w], 1, [$w, $w]);
    }

    /**
     * Adds tokens depending on sizing configuration.
     *
     * @param array<int, int|string> $tokens
     */
    public function add_url_tokens(array &$tokens): void
    {
        if ($this->max_crop == 0) {
            $tokens[] = 's' . DerivativeUrlCodec::sizeToUrl($this->ideal_size);
        } elseif ($this->max_crop == 1 && DerivativeUrlCodec::sizeEquals($this->ideal_size, $this->min_size)) {
            $tokens[] = 'e' . DerivativeUrlCodec::sizeToUrl($this->ideal_size);
        } else {
            $tokens[] = DerivativeUrlCodec::sizeToUrl($this->ideal_size);
            $tokens[] = DerivativeUrlCodec::fractionToChar($this->max_crop);
            $tokens[] = DerivativeUrlCodec::sizeToUrl($this->min_size);
        }
    }

    /**
     * Calculates the cropping rectangle and the scaled size for an input image size.
     *
     * @param int[] $in_size - two element array of input dimensions (width, height)
     * @param string|null $coi - four character encoded string containing the
     *   center of interest, or null (unused if max_crop=0 — compute_final_size()
     *   always passes null since it never needs the crop preview); crop_h()/
     *   crop_v() already treat null and '' identically via empty($coi)
     * @param-out ImageRect|null $crop_rect - ImageRect containing the cropping rectangle or null if cropping is not required
     * @param-out array<int|float>|null $scale_size - two element array containing width and height of the scaled image, or null
     * @param ImageRect|null $crop_rect by-ref out-param; always bound to a fresh, undefined variable at every real call site
     * @param array<int|float>|null $scale_size by-ref out-param; always bound to a fresh, undefined variable at every real call site
     */
    public function compute(array $in_size, ?string $coi, ?ImageRect &$crop_rect, ?array &$scale_size): void
    {
        $destCrop = new ImageRect($in_size);

        if ($this->max_crop > 0) {
            $ratio_w = $destCrop->width() / $this->ideal_size[0];
            $ratio_h = $destCrop->height() / $this->ideal_size[1];
            if ($ratio_w > 1 || $ratio_h > 1) {
                if ($ratio_w > $ratio_h) {
                    $h = $destCrop->height() / $ratio_w;
                    if ($h < $this->min_size[1]) {
                        $idealCropPx = $destCrop->width() - floor($destCrop->height() * $this->ideal_size[0] / $this->min_size[1]);
                        $maxCropPx = round($this->max_crop * $destCrop->width());
                        $destCrop->crop_h((int) min($idealCropPx, $maxCropPx), $coi);
                    }
                } else {
                    $w = $destCrop->width() / $ratio_h;
                    if ($w < $this->min_size[0]) {
                        $idealCropPx = $destCrop->height() - floor($destCrop->width() * $this->ideal_size[1] / $this->min_size[0]);
                        $maxCropPx = round($this->max_crop * $destCrop->height());
                        $destCrop->crop_v((int) min($idealCropPx, $maxCropPx), $coi);
                    }
                }
            }
        }

        $scale_size = [$destCrop->width(), $destCrop->height()];

        $ratio_w = $destCrop->width() / $this->ideal_size[0];
        $ratio_h = $destCrop->height() / $this->ideal_size[1];
        if ($ratio_w > 1 || $ratio_h > 1) {
            if ($ratio_w > $ratio_h) {
                $scale_size[0] = $this->ideal_size[0];
                $scale_size[1] = (int) floor(1e-6 + $scale_size[1] / $ratio_w);
            } else {
                $scale_size[0] = (int) floor(1e-6 + $scale_size[0] / $ratio_h);
                $scale_size[1] = $this->ideal_size[1];
            }
        } else {
            $scale_size = null;
        }

        $crop_rect = null;
        if ($destCrop->width() != $in_size[0] || $destCrop->height() != $in_size[1]) {
            $crop_rect = $destCrop;
        }
    }
}
