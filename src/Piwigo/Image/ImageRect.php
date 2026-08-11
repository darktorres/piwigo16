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
 * Small utility to manipulate a 'rectangle'.
 */
final class ImageRect
{
    public int|float $l;

    public int|float $t;

    public int|float $r;

    public int|float $b;

    /**
     * @param int[] $l width and height
     */
    public function __construct(
        array $l
    ) {
        $this->l = $this->t = 0;
        $this->r = $l[0];
        $this->b = $l[1];
    }

    public function width(): float
    {
        return (float) $this->r - (float) $this->l;
    }

    public function height(): float
    {
        return (float) $this->b - (float) $this->t;
    }

    /**
     * Crops horizontally this rectangle by increasing left side and/or reducing the right side.
     *
     * @param int $pixels - the amount to substract from the width
     * @param ?string $coi - a 4 character string (or null) containing the center of interest
     */
    public function cropH(int $pixels, ?string $coi): void
    {
        if ($this->width() <= $pixels) {
            return;
        }
        $tlcrop = floor($pixels / 2);

        if ($coi !== null && $coi !== '') {
            $coil = floor((float) $this->r * (float) DerivativeUrlCodec::charToFraction($coi[0]));
            $coir = ceil((float) $this->r * (float) DerivativeUrlCodec::charToFraction($coi[2]));
            $availableL = $coil > $this->l ? $coil - (float) $this->l : 0.0;
            $availableR = $coir < $this->r ? (float) $this->r - $coir : 0.0;
            if ($availableL + $availableR >= $pixels) {
                if ($availableL < $tlcrop) {
                    $tlcrop = $availableL;
                } elseif ($availableR < $tlcrop) {
                    $tlcrop = (float) $pixels - $availableR;
                }
            }
        }
        $this->l = (float) $this->l + $tlcrop;
        $this->r = (float) $this->r - ((float) $pixels - $tlcrop);
    }

    /**
     * Crops vertically this rectangle by increasing top side and/or reducing the bottom side.
     *
     * @param int $pixels - the amount to substract from the height
     * @param ?string $coi - a 4 character string (or null) containing the center of interest
     */
    public function cropV(int $pixels, ?string $coi): void
    {
        if ($this->height() <= $pixels) {
            return;
        }
        $tlcrop = floor($pixels / 2);

        if ($coi !== null && $coi !== '') {
            $coit = floor((float) $this->b * (float) DerivativeUrlCodec::charToFraction($coi[1]));
            $coib = ceil((float) $this->b * (float) DerivativeUrlCodec::charToFraction($coi[3]));
            $availableT = $coit > $this->t ? $coit - (float) $this->t : 0.0;
            $availableB = $coib < $this->b ? (float) $this->b - $coib : 0.0;
            if ($availableT + $availableB >= $pixels) {
                if ($availableT < $tlcrop) {
                    $tlcrop = $availableT;
                } elseif ($availableB < $tlcrop) {
                    $tlcrop = (float) $pixels - $availableB;
                }
            }
        }
        $this->t = (float) $this->t + $tlcrop;
        $this->b = (float) $this->b - ((float) $pixels - $tlcrop);
    }
}
