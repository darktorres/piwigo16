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
     * @param int[]|null $min_size - (used only if _$max_crop_ !=0) two element array of output dimensions (width, height)
     */
    public function __construct(public $ideal_size, public $max_crop = 0, public ?array $min_size = null)
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
     * @return array{ideal_size: array{int, int}, max_crop: float, min_size: array{int, int}|null}
     */
    public function toArray(): array
    {
        return [
            'ideal_size' => [$this->ideal_size[0], $this->ideal_size[1]],
            'max_crop'   => $this->max_crop,
            'min_size'   => $this->min_size === null ? null : [$this->min_size[0], $this->min_size[1]],
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $ideal = is_array($row['ideal_size'] ?? null) ? $row['ideal_size'] : [0, 0];
        $idealW = isset($ideal[0]) && is_numeric($ideal[0]) ? (int) $ideal[0] : 0;
        $idealH = isset($ideal[1]) && is_numeric($ideal[1]) ? (int) $ideal[1] : 0;
        $maxCrop = is_numeric($row['max_crop'] ?? null) ? (float) $row['max_crop'] : 0.0;
        $minSizeRaw = $row['min_size'] ?? null;
        $minSize = null;
        if (is_array($minSizeRaw)) {
            $minSize = [
                isset($minSizeRaw[0]) && is_numeric($minSizeRaw[0]) ? (int) $minSizeRaw[0] : 0,
                isset($minSizeRaw[1]) && is_numeric($minSizeRaw[1]) ? (int) $minSizeRaw[1] : 0,
            ];
        }
        return new self([$idealW, $idealH], $maxCrop, $minSize);
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
            $tokens[] = DerivativeEncoding::sizeToUrl($this->min_size ?? [0, 0]);
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
            $ratio_w = (float) $destCrop->width() / (float) $this->ideal_size[0];
            $ratio_h = (float) $destCrop->height() / (float) $this->ideal_size[1];
            if ($ratio_w > 1 || $ratio_h > 1) {
                if ($ratio_w > $ratio_h) {
                    $h = (float) $destCrop->height() / $ratio_w;
                    $minSize1 = $this->min_size[1] ?? 0;
                    if ($h < $minSize1) {
                        $idealCropPx = (float) $destCrop->width() - floor((float) $destCrop->height() * (float) $this->ideal_size[0] / (float) $minSize1);
                        $maxCropPx = round($this->max_crop * (float) $destCrop->width());
                        $destCrop->cropH(min($idealCropPx, $maxCropPx), $coi ?? '');
                    }
                } else {
                    $w = (float) $destCrop->width() / $ratio_h;
                    $minSize0 = $this->min_size[0] ?? 0;
                    if ($w < $minSize0) {
                        $idealCropPx = (float) $destCrop->height() - floor((float) $destCrop->width() * (float) $this->ideal_size[1] / (float) $minSize0);
                        $maxCropPx = round($this->max_crop * (float) $destCrop->height());
                        $destCrop->cropV(min($idealCropPx, $maxCropPx), $coi ?? '');
                    }
                }
            }
        }

        $scale_size = [$destCrop->width(), $destCrop->height()];

        $ratio_w = (float) $destCrop->width() / (float) $this->ideal_size[0];
        $ratio_h = (float) $destCrop->height() / (float) $this->ideal_size[1];
        if ($ratio_w > 1 || $ratio_h > 1) {
            if ($ratio_w > $ratio_h) {
                $scale_size[0] = $this->ideal_size[0];
                $scale_size[1] = floor(1e-6 + (float) $scale_size[1] / $ratio_w);
            } else {
                $scale_size[0] = floor(1e-6 + (float) $scale_size[0] / $ratio_h);
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
