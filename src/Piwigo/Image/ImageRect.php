<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Small utility to manipulate a 'rectangle'.
 */
final class ImageRect
{
    /**
     * @var int $l
     * @var int $t
     * @var int $r
     * @var int $b
     */
    public $l;
    public int $t = 0;
    public int $r = 0;
    public int $b = 0;

    /**
     * @param array<int|float> $l width and height
     */
    public function __construct(array $l)
    {
        $this->l = $this->t = 0;
        $this->r = (int) $l[0];
        $this->b = (int) $l[1];
    }

    public function width(): int
    {
        return $this->r - $this->l;
    }

    public function height(): int
    {
        return $this->b - $this->t;
    }

    /**
     * Crops horizontally this rectangle by increasing left side and/or reducing the right side.
     *
     * @param int|float $pixels - the amount to substract from the width
     * @param string|null $coi - a 4 character string (or null) containing the center of interest
     */
    public function cropH(int|float $pixels, ?string $coi): void
    {
        $pixels = (float) $pixels;
        if ($this->width() <= $pixels) {
            return;
        }
        $tlcrop = floor($pixels / 2.0);

        if ($coi !== null && $coi !== '') {
            $coil = floor((float) $this->r * (float) DerivativeEncoding::charToFraction($coi[0]));
            $coir = ceil((float) $this->r * (float) DerivativeEncoding::charToFraction($coi[2]));
            $availableL = $coil > $this->l ? $coil - (float) $this->l : 0.0;
            $availableR = $coir < $this->r ? (float) $this->r - $coir : 0.0;
            if ($availableL + $availableR >= $pixels) {
                if ($availableL < $tlcrop) {
                    $tlcrop = $availableL;
                } elseif ($availableR < $tlcrop) {
                    $tlcrop = $pixels - $availableR;
                }
            }
        }
        $this->l += (int) $tlcrop;
        $this->r -= (int) ($pixels - $tlcrop);
    }

    /**
     * Crops vertically this rectangle by increasing top side and/or reducing the bottom side.
     *
     * @param int|float $pixels - the amount to substract from the height
     * @param string|null $coi - a 4 character string (or null) containing the center of interest
     */
    public function cropV(int|float $pixels, ?string $coi): void
    {
        $pixels = (float) $pixels;
        if ($this->height() <= $pixels) {
            return;
        }
        $tlcrop = floor($pixels / 2.0);

        if ($coi !== null && $coi !== '') {
            $coit = floor((float) $this->b * (float) DerivativeEncoding::charToFraction($coi[1]));
            $coib = ceil((float) $this->b * (float) DerivativeEncoding::charToFraction($coi[3]));
            $availableT = $coit > $this->t ? $coit - (float) $this->t : 0.0;
            $availableB = $coib < $this->b ? (float) $this->b - $coib : 0.0;
            if ($availableT + $availableB >= $pixels) {
                if ($availableT < $tlcrop) {
                    $tlcrop = $availableT;
                } elseif ($availableB < $tlcrop) {
                    $tlcrop = $pixels - $availableB;
                }
            }
        }
        $this->t += (int) $tlcrop;
        $this->b -= (int) ($pixels - $tlcrop);
    }
}
