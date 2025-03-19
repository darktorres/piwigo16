<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Small utility to manipulate a 'rectangle'.
 */
final class ImageRect
{
    public int|float $l;

    public int|float $t;

    public int|float|string $r;

    public int|float|string $b;

    /**
     * @param array<int> $l width and height
     */
    public function __construct(
        array $l
    ) {
        $this->l = $this->t = 0;
        $this->r = $l[0];
        $this->b = $l[1];
    }

    public function width(): int|float
    {
        return $this->r - $this->l;
    }

    public function height(): int|float
    {
        return $this->b - $this->t;
    }

    /**
     * Crops horizontally this rectangle by increasing left side and/or reducing the right side.
     *
     * @param int|float $pixels - the amount to subtract from the width
     * @param ?string $coi - a 4 character string (or null) containing the center of interest
     */
    public function crop_h(
        int|float $pixels,
        ?string $coi
    ): void {
        if ($this->width() <= $pixels) {
            return;
        }

        $tlcrop = floor($pixels / 2);

        if (! empty($coi)) {
            $coil = floor($this->r * derivative_params::char_to_fraction($coi[0]));
            $coir = ceil($this->r * derivative_params::char_to_fraction($coi[2]));
            $availableL = $coil > $this->l ? $coil - $this->l : 0;
            $availableR = $coir < $this->r ? $this->r - $coir : 0;

            if ($availableL + $availableR >= $pixels) {
                if ($availableL < $tlcrop) {
                    $tlcrop = $availableL;
                } elseif ($availableR < $tlcrop) {
                    $tlcrop = $pixels - $availableR;
                }
            }
        }

        $this->l += $tlcrop;
        $this->r -= $pixels - $tlcrop;
    }

    /**
     * Crops vertically this rectangle by increasing top side and/or reducing the bottom side.
     *
     * @param int|float $pixels - the amount to subtract from the height
     * @param ?string $coi - a 4 character string (or null) containing the center of interest
     */
    public function crop_v(
        int|float $pixels,
        ?string $coi
    ): void {
        if ($this->height() <= $pixels) {
            return;
        }

        $tlcrop = floor($pixels / 2);

        if (! empty($coi)) {
            $coit = floor($this->b * derivative_params::char_to_fraction($coi[1]));
            $coib = ceil($this->b * derivative_params::char_to_fraction($coi[3]));
            $availableT = $coit > $this->t ? $coit - $this->t : 0;
            $availableB = $coib < $this->b ? $this->b - $coib : 0;

            if ($availableT + $availableB >= $pixels) {
                if ($availableT < $tlcrop) {
                    $tlcrop = $availableT;
                } elseif ($availableB < $tlcrop) {
                    $tlcrop = $pixels - $availableB;
                }
            }
        }

        $this->t += $tlcrop;
        $this->b -= $pixels - $tlcrop;
    }
}
