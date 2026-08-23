<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

use LogicException;

/**
 * Paramaters for derivative scaling and cropping.
 * Instance of this class contained by DerivativeParams class.
 */
final class SizingParams
{
    /**
     * @param float|int $max_crop - from 0=no cropping to 1= max cropping (100% of width/height);
     *    expressed as a factor of the input width/height. Genuinely holds either
     *    type at runtime -- square()'s int literal 1, the constructor's own int
     *    default 0, and DerivativeUrlCodec::charToFraction()'s int|float-typed
     *    division all feed real int values here, alongside round()'s always-float
     *    result at ConfigurationSubController's own construction site.
     * @param ?Dimensions $min_size - (used only if _$max_crop_ !=0) output dimensions; null when $max_crop=0
     */
    public function __construct(
        public Dimensions $ideal_size,
        public float|int $max_crop = 0,
        public ?Dimensions $min_size = null
    ) {}

    /**
     * Returns a simple SizingParams object.
     */
    public static function classic(int $w, int $h): self
    {
        return new self(new Dimensions($w, $h));
    }

    /**
     * Returns a square SizingParams object.
     */
    public static function square(int $w): self
    {
        return new self(new Dimensions($w, $w), 1, new Dimensions($w, $w));
    }

    /**
     * Adds tokens depending on sizing configuration.
     *
     * @param array<int, int|string> $tokens
     */
    public function addUrlTokens(array &$tokens): void
    {
        if ($this->max_crop === 0.0) {
            $tokens[] = 's' . DerivativeUrlCodec::sizeToUrl($this->ideal_size);
        } elseif ($this->max_crop === 1.0 && $this->min_size instanceof Dimensions && DerivativeUrlCodec::sizeEquals($this->ideal_size, $this->min_size)) {
            $tokens[] = 'e' . DerivativeUrlCodec::sizeToUrl($this->ideal_size);
        } else {
            $tokens[] = DerivativeUrlCodec::sizeToUrl($this->ideal_size);
            $tokens[] = DerivativeUrlCodec::fractionToChar($this->max_crop);
            if ($this->min_size instanceof Dimensions) {
                $tokens[] = DerivativeUrlCodec::sizeToUrl($this->min_size);
            }
        }
    }

    /**
     * Calculates the cropping rectangle and the scaled size for an input image size.
     *
     * @param string|null $coi - four character encoded string containing the
     *   center of interest, or null (unused if max_crop=0 — computeFinalSize()
     *   always passes null since it never needs the crop preview); cropH()/
     *   cropV() already treat null and '' identically via empty($coi)
     * @param-out ImageRect|null $crop_rect - ImageRect containing the cropping rectangle or null if cropping is not required
     * @param-out ?Dimensions $scale_size - the scaled image's dimensions, or null
     * @param ImageRect|null $crop_rect by-ref out-param; always bound to a fresh, undefined variable at every real call site
     * @param ?Dimensions $scale_size by-ref out-param; always bound to a fresh, undefined variable at every real call site
     */
    public function compute(Dimensions $in_size, ?string $coi, ?ImageRect &$crop_rect, ?Dimensions &$scale_size): void
    {
        $destCrop = new ImageRect($in_size);

        if ($this->max_crop > 0) {
            // min_size is only ever null when max_crop=0 (see this class's
            // own constructor docblock) -- guaranteed non-null here.
            $minSize = $this->min_size;
            if ($minSize === null) {
                throw new LogicException('SizingParams::compute(): min_size must not be null when max_crop > 0');
            }

            $ratio_w = $destCrop->width() / (float) $this->ideal_size->width;
            $ratio_h = $destCrop->height() / (float) $this->ideal_size->height;
            if ($ratio_w > 1 || $ratio_h > 1) {
                if ($ratio_w > $ratio_h) {
                    $h = $destCrop->height() / $ratio_w;
                    if ($h < $minSize->height) {
                        $idealCropPx = $destCrop->width() - floor($destCrop->height() * (float) $this->ideal_size->width / (float) $minSize->height);
                        $maxCropPx = round($this->max_crop * $destCrop->width());
                        $destCrop->cropH((int) min($idealCropPx, $maxCropPx), $coi);
                    }
                } else {
                    $w = $destCrop->width() / $ratio_h;
                    if ($w < $minSize->width) {
                        $idealCropPx = $destCrop->height() - floor($destCrop->width() * (float) $this->ideal_size->height / (float) $minSize->width);
                        $maxCropPx = round($this->max_crop * $destCrop->height());
                        $destCrop->cropV((int) min($idealCropPx, $maxCropPx), $coi);
                    }
                }
            }
        }

        $scale_size = new Dimensions($destCrop->width(), $destCrop->height());

        $ratio_w = $destCrop->width() / (float) $this->ideal_size->width;
        $ratio_h = $destCrop->height() / (float) $this->ideal_size->height;
        if ($ratio_w > 1 || $ratio_h > 1) {
            if ($ratio_w > $ratio_h) {
                $scale_size = new Dimensions($this->ideal_size->width, (int) floor(1e-6 + $scale_size->height / $ratio_w));
            } else {
                $scale_size = new Dimensions((int) floor(1e-6 + $scale_size->width / $ratio_h), $this->ideal_size->height);
            }
        } else {
            $scale_size = null;
        }

        $crop_rect = null;
        if ($destCrop->width() !== (float) $in_size->width || $destCrop->height() !== (float) $in_size->height) {
            $crop_rect = $destCrop;
        }
    }
}
