<?php

declare(strict_types=1);

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
    public function __construct(public $ideal_size, public $max_crop = 0, public $min_size = null)
    {
    }

    /**
     * Returns a simple SizingParams object.
     *
     * @param int $w
     * @param int $h
     */
    public static function classic($w, $h): SizingParams
    {
        return new SizingParams([$w,$h]);
    }

    /**
     * Returns a square SizingParams object.
     *
     */
    public static function square(int $w): self
    {
        return new SizingParams([$w,$w], 1, [$w,$w]);
    }

    /**
     * Adds tokens depending on sizing configuration.
     *
     * @param array &$tokens
     */
    /** @param array<int|string> $tokens */
    public function addUrlTokens(array &$tokens): void
    {
        if ($this->max_crop == 0) {
            $tokens[] = 's'.DerivativeEncoding::sizeToUrl($this->ideal_size);
        } elseif ($this->max_crop == 1 && DerivativeEncoding::sizeEquals($this->ideal_size, $this->min_size)) {
            $tokens[] = 'e'.DerivativeEncoding::sizeToUrl($this->ideal_size);
        } else {
            $tokens[] = DerivativeEncoding::sizeToUrl($this->ideal_size);
            $tokens[] = DerivativeEncoding::fractionToChar($this->max_crop);
            $tokens[] = DerivativeEncoding::sizeToUrl($this->min_size);
        }
    }

    /**
     * Calculates the cropping rectangle and the scaled size for an input image size.
     *
     * @param array<int|float> $in_size - two element array of input dimensions (width, height)
     * @param string|null $coi - four character encoded string containing the center of interest (unused if max_crop=0)
     * @param ImageRect|null &$crop_rect - ImageRect containing the cropping rectangle or null if cropping is not required
     * @param array<int,int|float>|null &$scale_size - two element array containing width and height of the scaled image
     */
    public function compute(array $in_size, string|null $coi, mixed &$crop_rect, mixed &$scale_size): void
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
                        $destCrop->cropH(min($idealCropPx, $maxCropPx), $coi ?? '');
                    }
                } else {
                    $w = $destCrop->width() / $ratio_h;
                    if ($w < $this->min_size[0]) {
                        $idealCropPx = $destCrop->height() - floor($destCrop->width() * $this->ideal_size[1] / $this->min_size[0]);
                        $maxCropPx = round($this->max_crop * $destCrop->height());
                        $destCrop->cropV(min($idealCropPx, $maxCropPx), $coi ?? '');
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
                $scale_size[1] = floor(1e-6 + $scale_size[1] / $ratio_w);
            } else {
                $scale_size[0] = floor(1e-6 + $scale_size[0] / $ratio_h);
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
