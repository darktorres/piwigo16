<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * EXIF Orientation tag (TIFF tag 0x0112) values — 8 standard codes
 * describing how the camera held the sensor relative to the scene.
 * Piwigo uses this to auto-rotate uploaded photos so they display
 * the right way up.
 */
enum ExifOrientation: int
{
    case TopLeft     = 1;
    case TopRight    = 2;
    case BottomRight = 3;
    case BottomLeft  = 4;
    case LeftTop     = 5;
    case RightTop    = 6;
    case RightBottom = 7;
    case LeftBottom  = 8;

    /** Degrees of clockwise rotation needed to display the image upright. */
    public function rotation(): int
    {
        return match ($this) {
            self::TopLeft, self::TopRight       => 0,
            self::BottomRight, self::BottomLeft => 180,
            self::LeftTop, self::RightTop       => 270,
            self::RightBottom, self::LeftBottom => 90,
        };
    }
}
